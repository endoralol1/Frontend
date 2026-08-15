import { BaseProvider } from '@omss/framework';
import type {
    ProviderCapabilities,
    ProviderMediaObject,
    ProviderResult,
    Source
} from '@omss/framework';

const SITE = 'https://filesun.sbs';
const RESULT_TTL_MS = 10 * 60 * 1000;

const UA =
    'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';

const resultCache = new Map<string, { expiresAt: number; result: ProviderResult }>();

type ResolveServer = {
    server?: string;
    url?: string;
};

function extractM3u8(html: string): string | null {
    const fromSources = html.match(
        /sources\s*:\s*\[\s*\{\s*file\s*:\s*['"]([^'"]+\.m3u8[^'"]*)['"]/i
    )?.[1];
    if (fromSources) return fromSources.replace(/&amp;/g, '&');
    const bare = html.match(/https?:\/\/[^\s'"]+\.m3u8[^\s'"]*/i)?.[0];
    return bare ? bare.replace(/&amp;/g, '&') : null;
}

function inferQuality(url: string): string {
    const blob = url.toLowerCase();
    if (/2160|4k|uhd/.test(blob)) return '2160p';
    if (/1080/.test(blob)) return '1080p';
    if (/720/.test(blob)) return '720p';
    if (/480/.test(blob)) return '480p';
    return 'Auto';
}

export class FilesunProvider extends BaseProvider {
    readonly id = 'filesun';
    readonly name = 'FileSuN';
    readonly enabled = true;
    readonly BASE_URL = SITE;
    readonly HEADERS: Record<string, string> = {
        'User-Agent': UA,
        Accept: 'text/html,application/xhtml+xml,application/json;q=0.9,*/*;q=0.8',
        'Accept-Language': 'en-US,en;q=0.9',
        Referer: `${SITE}/`
    };

    readonly capabilities: ProviderCapabilities = {
        supportedContentTypes: ['movies', 'tv']
    };

    async getMovieSources(media: ProviderMediaObject): Promise<ProviderResult> {
        return this.resolve(media);
    }

    async getTVSources(media: ProviderMediaObject): Promise<ProviderResult> {
        return this.resolve(media);
    }

    private cacheKey(media: ProviderMediaObject): string {
        return media.type === 'tv'
            ? `tv:${media.tmdbId}:s${media.s ?? 1}:e${media.e ?? 1}`
            : `movie:${media.tmdbId}`;
    }

    private emptyResult(message: string): ProviderResult {
        return {
            sources: [],
            subtitles: [],
            diagnostics: [
                {
                    code: 'PROVIDER_ERROR',
                    message: `${this.name}: ${message}`,
                    field: '',
                    severity: 'warning'
                }
            ]
        };
    }

    private async fetchText(
        url: string,
        init: RequestInit & { timeoutMs?: number } = {}
    ): Promise<{ ok: boolean; status: number; body: string }> {
        const timeoutMs = init.timeoutMs ?? 20_000;
        const { timeoutMs: _ignored, ...fetchInit } = init;
        const controller = new AbortController();
        const timer = setTimeout(() => controller.abort(), timeoutMs);
        try {
            const response = await fetch(url, {
                ...fetchInit,
                headers: {
                    ...this.HEADERS,
                    ...(fetchInit.headers as Record<string, string> | undefined)
                },
                redirect: 'follow',
                signal: controller.signal
            });
            const body = await response.text();
            return { ok: response.ok, status: response.status, body };
        } catch (error) {
            return {
                ok: false,
                status: 0,
                body: error instanceof Error ? error.message : 'fetch failed'
            };
        } finally {
            clearTimeout(timer);
        }
    }

    private async resolveImdbId(media: ProviderMediaObject): Promise<string | null> {
        const direct = String(media.imdbId || '').trim();
        if (/^tt\d+$/i.test(direct)) return direct;

        const apiKey = process.env.TMDB_API_KEY?.trim();
        if (!apiKey || !media.tmdbId) return null;

        const kind = media.type === 'tv' ? 'tv' : 'movie';
        const url = `https://api.themoviedb.org/3/${kind}/${encodeURIComponent(String(media.tmdbId))}/external_ids?api_key=${encodeURIComponent(apiKey)}`;
        try {
            const response = await fetch(url, { signal: AbortSignal.timeout(8_000) });
            if (!response.ok) return null;
            const json = (await response.json()) as { imdb_id?: string | null };
            const id = String(json.imdb_id || '').trim();
            return /^tt\d+$/i.test(id) ? id : null;
        } catch {
            return null;
        }
    }

    private embedPath(media: ProviderMediaObject, imdbId: string | null): string | null {
        if (media.type === 'tv') {
            if (!media.tmdbId) return null;
            const season = Number(media.s ?? 1) || 1;
            const episode = Number(media.e ?? 1) || 1;
            return `/embed/tv/${encodeURIComponent(String(media.tmdbId))}/${season}/${episode}`;
        }
        // Movies: FileSuN expects IMDb (tt…)
        if (!imdbId) return null;
        return `/embed/movie/${encodeURIComponent(imdbId)}`;
    }

    private async resolveFromEmbed(embedPath: string): Promise<{
        url: string;
        quality: string;
        server: string;
    } | null> {
        const embedUrl = `${SITE}${embedPath}`;
        const page = await this.fetchText(embedUrl, {
            headers: { Accept: 'text/html', Referer: `${SITE}/` }
        });
        if (!page.ok) {
            throw new Error(`Embed HTTP ${page.status}`);
        }
        if (/just a moment/i.test(page.body)) {
            throw new Error('Cloudflare challenge on embed');
        }

        const token = page.body.match(/const\s+RTOKEN\s*=\s*"([^"]+)"/)?.[1];
        if (!token) {
            const err = page.body.match(/const\s+ERROR\s*=\s*"([^"]+)"/)?.[1];
            throw new Error(err || 'Missing RTOKEN on embed page');
        }

        const resolveRes = await this.fetchText(`${SITE}/api/resolve`, {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                Origin: SITE,
                Referer: embedUrl
            },
            body: JSON.stringify({ t: token }),
            timeoutMs: 20_000
        });
        if (!resolveRes.ok) {
            throw new Error(`Resolve HTTP ${resolveRes.status}`);
        }

        let servers: ResolveServer[] = [];
        try {
            const json = JSON.parse(resolveRes.body) as { servers?: ResolveServer[]; error?: string };
            if (json.error) throw new Error(String(json.error));
            servers = Array.isArray(json.servers) ? json.servers : [];
        } catch (error) {
            if (error instanceof Error && error.message.startsWith('Resolve')) throw error;
            throw new Error('Resolve returned non-JSON');
        }
        if (!servers.length) return null;

        // Prefer first working server (usually vidmoly / embedsun).
        for (const server of servers.slice(0, 4)) {
            const serverUrl = String(server.url || '').trim();
            if (!/^https?:\/\//i.test(serverUrl)) continue;

            const emb = await this.fetchText(serverUrl, {
                headers: {
                    Accept: 'text/html',
                    Referer: embedUrl,
                    Origin: SITE
                },
                timeoutMs: 20_000
            });
            if (!emb.ok || /just a moment/i.test(emb.body)) continue;
            const m3u8 = extractM3u8(emb.body);
            if (!m3u8) continue;
            return {
                url: m3u8,
                quality: inferQuality(m3u8),
                server: String(server.server || 'filesun')
            };
        }
        return null;
    }

    private async resolve(media: ProviderMediaObject): Promise<ProviderResult> {
        const key = this.cacheKey(media);
        const cached = resultCache.get(key);
        if (cached && cached.expiresAt > Date.now()) return cached.result;

        try {
            if (!media.tmdbId) return this.emptyResult('Missing TMDB id');

            const imdbId =
                media.type === 'tv' ? null : await this.resolveImdbId(media);
            if (media.type !== 'tv' && !imdbId) {
                return this.emptyResult('Missing IMDb id for FileSuN movie embed');
            }

            const path = this.embedPath(media, imdbId);
            if (!path) return this.emptyResult('Could not build embed path');

            const hit = await this.resolveFromEmbed(path);
            if (!hit) return this.emptyResult('No playable FileSuN servers');

            const source: Source = {
                url: hit.url,
                type: 'hls',
                quality: hit.quality,
                audioTracks: [{ label: 'Original', language: 'Original' }],
                provider: { id: this.id, name: this.name }
            };

            const result: ProviderResult = {
                sources: [source],
                subtitles: [],
                diagnostics: [
                    {
                        code: 'PARTIAL_SCRAPE',
                        message: `${this.name}: ${hit.server} · ${hit.quality}`,
                        field: '',
                        severity: 'info'
                    }
                ]
            };
            resultCache.set(key, { expiresAt: Date.now() + RESULT_TTL_MS, result });
            return result;
        } catch (error) {
            return this.emptyResult(
                error instanceof Error ? error.message : 'provider error'
            );
        }
    }

    async healthCheck(): Promise<boolean> {
        try {
            const response = await fetch(SITE, {
                method: 'HEAD',
                headers: this.HEADERS,
                signal: AbortSignal.timeout(8_000)
            });
            return response.status > 0 && response.status < 500;
        } catch {
            return false;
        }
    }
}
