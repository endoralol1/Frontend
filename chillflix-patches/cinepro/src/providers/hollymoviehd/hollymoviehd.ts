import { BaseProvider } from '@omss/framework';
import type {
    ProviderCapabilities,
    ProviderMediaObject,
    ProviderResult,
    Source,
    SourceType
} from '@omss/framework';

import { flareSolverrGet } from '../cinemacity/flaresolverr-client.js';

type CfSession = {
    userAgent: string;
    cookieHeader: string;
    expiresAt: number;
};

type AjaxStreamResponse = {
    servers_iframe?: Record<string, string>;
    subtitles?: unknown;
    mq?: Array<{
        keyid?: string;
        title?: string;
        servers_iframe?: Record<string, string>;
    }>;
};

type GoodstreamSource = {
    file?: string;
    label?: string;
    type?: string;
};

const SITE = 'https://hollymoviehd.cc';
const GOODSTREAM = 'https://goodstream.cc';
const SESSION_TTL_MS = 20 * 60 * 1000;
const RESULT_TTL_MS = 12 * 60 * 1000;

let cachedSession: CfSession | null = null;
let sessionLock: Promise<void> = Promise.resolve();
const resultCache = new Map<string, { expiresAt: number; result: ProviderResult }>();
let warming = false;

export class HollymoviehdProvider extends BaseProvider {
    readonly id = 'hollymoviehd';
    readonly name = 'HollyMovieHD';
    readonly enabled = true;
    readonly BASE_URL = SITE;
    readonly HEADERS: Record<string, string> = {
        'User-Agent':
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
        Accept: 'text/html,application/xhtml+xml,*/*;q=0.8',
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

    private emptyResult(message: string): ProviderResult {
        return {
            sources: [],
            subtitles: [],
            diagnostics: [
                {
                    code: 'PROVIDER_ERROR',
                    message,
                    field: '',
                    severity: 'warning'
                }
            ]
        };
    }

    private cacheKey(media: ProviderMediaObject): string {
        return media.type === 'tv'
            ? `tv:${media.tmdbId}:s${media.s ?? 1}:e${media.e ?? 1}`
            : `movie:${media.tmdbId}`;
    }

    private async resolve(media: ProviderMediaObject): Promise<ProviderResult> {
        const key = this.cacheKey(media);
        const hit = resultCache.get(key);
        if (hit && hit.expiresAt > Date.now()) {
            return hit.result;
        }

        try {
            // Ensure a CF session exists (serialized). Warm also kicks off at process start.
            await this.ensureSession(true);

            const pageUrl = await this.findPageUrl(media);
            if (!pageUrl) {
                return this.emptyResult(
                    `No HollyMovieHD page for "${media.title}" (${media.releaseYear || '?'})`
                );
            }

            const html = await this.fetchText(pageUrl);
            if (!html || /just a moment/i.test(html)) {
                cachedSession = null;
                await this.ensureSession(true);
                return this.emptyResult('HollyMovieHD Cloudflare challenge blocked fetch');
            }

            const meta = this.extractPlayerMeta(html);
            if (!meta.streamkey || !meta.nonce) {
                return this.emptyResult(`No streamkey on ${pageUrl}`);
            }

            const ajax = await this.fetchAjaxStreams(meta, pageUrl);
            const embedUrls = this.collectEmbeds(ajax);
            if (!embedUrls.length) {
                return this.emptyResult('HollyMovieHD ajax returned no embeds');
            }

            const sources: Source[] = [];
            for (const embed of embedUrls.slice(0, 3)) {
                const resolved = await this.resolveGoodstream(embed);
                for (const item of resolved) {
                    sources.push(item);
                }
                if (sources.length >= 4) break;
            }

            if (!sources.length) {
                return this.emptyResult('Could not resolve Goodstream sources');
            }

            const result: ProviderResult = { sources, subtitles: [], diagnostics: [] };
            resultCache.set(key, { expiresAt: Date.now() + RESULT_TTL_MS, result });
            return result;
        } catch (error) {
            return this.emptyResult(
                error instanceof Error ? error.message : 'HollyMovieHD provider error'
            );
        }
    }

    private async findPageUrl(media: ProviderMediaObject): Promise<string | null> {
        if (media.type === 'tv') {
            return this.findEpisodeUrl(media);
        }

        const title = (media.title || '').trim();
        const year = (media.releaseYear || '').trim();
        if (!title) return null;

        // Fast path: /{slug}-{year}/ (most HollyMovieHD movie URLs)
        if (year) {
            const direct = `${SITE}/${this.slugify(title)}-${year}/`;
            const html = await this.fetchText(direct, true);
            if (html && !/just a moment/i.test(html) && this.extractPlayerMeta(html).streamkey) {
                return direct;
            }
        }

        const queries = [
            year ? `${title} ${year}` : title,
            media.imdbId?.startsWith('tt') ? media.imdbId : ''
        ].filter(Boolean);

        for (const q of queries) {
            const searchUrl = `${SITE}/?s=${encodeURIComponent(q)}`;
            const html = await this.fetchText(searchUrl, true);
            if (!html) continue;
            const hit = this.pickBestResult(html, title, year, false);
            if (hit) return hit;
        }
        return null;
    }

    private async findEpisodeUrl(media: ProviderMediaObject): Promise<string | null> {
        const title = (media.title || '').trim();
        const season = media.s ?? 1;
        const episode = media.e ?? 1;
        if (!title) return null;

        const slugBase = this.slugify(title);
        const seasonUrl = `${SITE}/series/${slugBase}-season-${season}/`;
        let html = await this.fetchText(seasonUrl, true);
        if (!html || /just a moment/i.test(html) || /page not found|404/i.test(html)) {
            const searchUrl = `${SITE}/?s=${encodeURIComponent(`${title} season ${season}`)}`;
            html = (await this.fetchText(searchUrl, true)) || '';
            const seriesHit = this.pickBestResult(html, title, '', true);
            if (!seriesHit) return null;
            html = (await this.fetchText(seriesHit, true)) || '';
        }

        const wanted = `/episode/${slugBase}-season-${season}-episode-${episode}/`;
        const abs = `${SITE}${wanted}`;
        if (html.includes(wanted) || html.includes(abs)) return abs;

        const re = new RegExp(
            `https://hollymoviehd\\.cc/episode/[^"'\\s]*season-${season}-episode-${episode}/?`,
            'i'
        );
        const m = html.match(re);
        return m?.[0] ?? null;
    }

    private pickBestResult(
        html: string,
        title: string,
        year: string,
        series: boolean
    ): string | null {
        const links = [
            ...html.matchAll(
                /href=["'](https:\/\/hollymoviehd\.cc\/[^"']+)["']/gi
            )
        ].map((m) => m[1]);

        const needle = this.slugify(title);
        const scored: Array<{ url: string; score: number }> = [];
        for (const url of links) {
            const path = url.replace(SITE, '');
            if (path.startsWith('/genre/') || path.startsWith('/release-year/')) continue;
            if (series) {
                if (!path.startsWith('/series/')) continue;
            } else if (
                path.startsWith('/series/') ||
                path.startsWith('/episode/') ||
                path.startsWith('/anime/')
            ) {
                continue;
            }

            let score = 0;
            const slug = path.toLowerCase();
            if (slug.includes(needle)) score += 10;
            if (year && slug.includes(year)) score += 5;
            if (!series && /\/\d{4}\/?$/.test(path)) score += 1;
            if (score > 0) scored.push({ url: url.replace(/\/$/, '') + '/', score });
        }
        scored.sort((a, b) => b.score - a.score);
        return scored[0]?.url ?? null;
    }

    private extractPlayerMeta(html: string) {
        const streamkey =
            html.match(/data-streamkey=["']([^"']+)["']/i)?.[1] ?? '';
        const nonce = html.match(/data-wpnonce=["']([^"']+)["']/i)?.[1] ?? '';
        const imdbid =
            html.match(/data-imdbid=["']([^"']+)["']/i)?.[1] ??
            html.match(/imdb\.com\/title\/(tt\d+)/i)?.[1] ??
            '';
        const tmdbid = html.match(/data-tmdbid=["'](\d+)["']/i)?.[1] ?? '';
        return { streamkey, nonce, imdbid, tmdbid };
    }

    private async fetchAjaxStreams(
        meta: { streamkey: string; nonce: string; imdbid: string; tmdbid: string },
        referer: string
    ): Promise<AjaxStreamResponse | null> {
        const session = await this.ensureSession();
        const body = new URLSearchParams({
            action: 'ajax_getlinkstream',
            streamkey: meta.streamkey,
            nonce: meta.nonce,
            imdbid: meta.imdbid,
            tmdbid: meta.tmdbid
        });

        const res = await fetch(`${SITE}/wp-admin/admin-ajax.php`, {
            method: 'POST',
            headers: {
                'User-Agent': session.userAgent,
                Cookie: session.cookieHeader,
                'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
                'X-Requested-With': 'XMLHttpRequest',
                Origin: SITE,
                Referer: referer,
                Accept: 'application/json, text/javascript, */*; q=0.01'
            },
            body
        });
        if (!res.ok) return null;
        return (await res.json()) as AjaxStreamResponse;
    }

    private collectEmbeds(ajax: AjaxStreamResponse | null): string[] {
        if (!ajax) return [];
        const out: string[] = [];
        const push = (map?: Record<string, string>) => {
            if (!map) return;
            for (const [name, url] of Object.entries(map)) {
                if (!url || !/goodstream\.cc/i.test(url)) continue;
                if (!out.includes(url)) out.push(url);
                void name;
            }
        };
        push(ajax.servers_iframe);
        for (const item of ajax.mq ?? []) push(item.servers_iframe);
        return out;
    }

    private async resolveGoodstream(embedUrl: string): Promise<Source[]> {
        const session = await this.ensureSession();
        const abs = embedUrl.startsWith('http') ? embedUrl : `${GOODSTREAM}${embedUrl}`;
        const pageRes = await fetch(abs, {
            headers: {
                'User-Agent': session.userAgent,
                Referer: `${SITE}/`,
                Accept: 'text/html,*/*'
            }
        });
        if (!pageRes.ok) return [];
        const html = await pageRes.text();
        const csrf =
            html.match(/id=["']csrf_token["']\s+value=["']([^"']+)["']/i)?.[1] ??
            '';
        if (!csrf) return [];

        const form = new FormData();
        form.append('token', '');
        form.append('csrf_token', csrf);
        const apiRes = await fetch(abs, {
            method: 'POST',
            headers: {
                'User-Agent': session.userAgent,
                Origin: GOODSTREAM,
                Referer: `${SITE}/`,
                Accept: 'application/json, text/plain, */*'
            },
            body: form
        });
        if (!apiRes.ok) return [];
        const json = (await apiRes.json()) as {
            success?: boolean;
            sources?: GoodstreamSource[];
        };
        if (!json?.sources?.length) return [];

        const headers = {
            Referer: `${GOODSTREAM}/`,
            Origin: GOODSTREAM,
            'User-Agent': session.userAgent,
            Accept: '*/*'
        };

        const out: Source[] = [];
        for (const s of json.sources) {
            const file = this.absolutize(s.file || '', abs);
            if (!file) continue;
            // Prefer HLS / direct CDN; skip relative streamsvr without host if absolutize failed
            if (file.startsWith('/')) continue;
            const type = this.mapType(s.type, file);
            out.push({
                url: this.createProxyUrl(file, headers),
                type,
                quality: s.label || 'Auto',
                audioTracks: [{ language: 'Original', label: 'Original' }],
                provider: { id: this.id, name: this.name }
            });
        }
        return out;
    }

    private absolutize(file: string, embedUrl: string): string {
        if (!file) return '';
        if (file.startsWith('//')) return `https:${file}`;
        if (file.startsWith('http://') || file.startsWith('https://')) return file;
        try {
            return new URL(file, embedUrl).toString();
        } catch {
            return '';
        }
    }

    private mapType(raw: string | undefined, url: string): SourceType {
        const t = (raw || '').toLowerCase();
        if (t.includes('hls') || url.includes('.m3u8') || url.includes('/pl/')) {
            return 'hls';
        }
        return 'mp4';
    }

    private async fetchText(url: string, allowFlare = true): Promise<string | null> {
        const session = await this.ensureSession(allowFlare);
        try {
            const res = await fetch(url, {
                headers: {
                    'User-Agent': session.userAgent,
                    Cookie: session.cookieHeader,
                    Accept: 'text/html,application/xhtml+xml,*/*;q=0.8',
                    'Accept-Language': 'en-US,en;q=0.9',
                    Referer: `${SITE}/`
                },
                redirect: 'follow'
            });
            const html = await res.text();
            if (res.ok && !/just a moment/i.test(html)) return html;
        } catch {
            // fall through to FlareSolverr
        }

        if (!allowFlare) return null;
        const flared = await flareSolverrGet(url);
        if (flared.session) {
            cachedSession = {
                userAgent: flared.session.userAgent,
                cookieHeader: flared.session.cookieHeader,
                expiresAt: Date.now() + SESSION_TTL_MS
            };
        }
        return flared.html ?? null;
    }

    private async ensureSession(allowFlare = true): Promise<CfSession> {
        if (cachedSession && cachedSession.expiresAt > Date.now()) {
            return cachedSession;
        }
        if (!allowFlare) {
            return {
                userAgent:
                    'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
                cookieHeader: '',
                expiresAt: Date.now() + 60_000
            };
        }

        // Serialize FlareSolverr unlocks — concurrent solves stack and hit provider timeouts.
        let release!: () => void;
        const wait = new Promise<void>((r) => {
            release = r;
        });
        const prev = sessionLock;
        sessionLock = prev.then(() => wait);
        await prev;
        try {
            if (cachedSession && cachedSession.expiresAt > Date.now()) {
                return cachedSession;
            }
            const flared = await flareSolverrGet(`${SITE}/`);
            if (!flared.session) {
                throw new Error('FlareSolverr could not unlock hollymoviehd.cc');
            }
            cachedSession = {
                userAgent: flared.session.userAgent,
                cookieHeader: flared.session.cookieHeader,
                expiresAt: Date.now() + SESSION_TTL_MS
            };
            return cachedSession;
        } finally {
            release();
        }
    }

    private slugify(input: string): string {
        return input
            .toLowerCase()
            .replace(/['’]/g, '')
            .replace(/[^a-z0-9]+/g, '-')
            .replace(/^-+|-+$/g, '');
    }
}

// Warm Cloudflare cookies once at process start so the first viewer isn't stuck.
if (!warming) {
    warming = true;
    void flareSolverrGet(`${SITE}/`)
        .then((flared) => {
            if (!flared.session) return;
            cachedSession = {
                userAgent: flared.session.userAgent,
                cookieHeader: flared.session.cookieHeader,
                expiresAt: Date.now() + SESSION_TTL_MS
            };
        })
        .catch(() => null)
        .finally(() => {
            warming = false;
        });
}
