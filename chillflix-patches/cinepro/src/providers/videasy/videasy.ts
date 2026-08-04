import { BaseProvider } from '@omss/framework';
import type {
    ProviderCapabilities,
    ProviderMediaObject,
    ProviderResult
} from '@omss/framework';
import type { VideasyServer } from './videasy.types.js';
import { decryptResponse } from './decryptor.js';
import { fetchText } from '../../utils/provider-fetch.js';

/**
 * all known api endpoints. mb-flix is the primary english source.
 * endpoints like meine, overflix, cuevana serve other languages.
 * hdmovie returns sources where the "quality" field is actually
 * a language label ("Hindi", "English") rather than a resolution.
 * those which are commented do not work
 */

const VIDEASY_API = 'https://api.speedracelight.com';

/**
 * Videasy migrated from api.videasy.net → api.videasy.to (player.videasy.to).
 * Title must be double URL-encoded. See EncDecEndpoints samples/videasy.py.
 */
const VIDEASY_SERVERS: readonly VideasyServer[] = [
    // Yoru CDN — movies often include 2160p on moon.ironwallnet.net
    {
        name: 'cdn',
        url: `${VIDEASY_API}/cdn/sources-with-title`,
        language: 'english'
    },
    {
        name: 'mb-flix',
        url: `${VIDEASY_API}/mb-flix/sources-with-title`,
        language: 'english'
    },
    {
        name: 'm4uhd',
        url: `${VIDEASY_API}/m4uhd/sources-with-title`,
        language: 'english'
    }
] as const;

export class VideasyProvider extends BaseProvider {
    readonly id = 'Videasy';
    readonly name = 'Videasy';
    readonly enabled = true;
    readonly BASE_URL = VIDEASY_API;
    readonly HEADERS = {
        'User-Agent':
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/137.0.0.0 Safari/537.36',
        Accept: 'application/json, */*; q=0.01',
        Referer: 'https://player.videasy.to/',
        Origin: 'https://player.videasy.to'
    };

    readonly capabilities: ProviderCapabilities = {
        supportedContentTypes: ['movies', 'tv']
    };

    async getMovieSources(media: ProviderMediaObject): Promise<ProviderResult> {
        return this.getSources(media);
    }

    async getTVSources(media: ProviderMediaObject): Promise<ProviderResult> {
        return this.getSources(media);
    }

    // fans out to all servers in parallel, merges results
    private async getSources(
        media: ProviderMediaObject
    ): Promise<ProviderResult> {
        const results = await Promise.allSettled(
            VIDEASY_SERVERS.map((server) => this.fetchFromServer(server, media))
        );

        const sources: ProviderResult['sources'] = [];
        const subtitles: ProviderResult['subtitles'] = [];
        const diagnostics: ProviderResult['diagnostics'] = [];
        let failCount = 0;

        for (const result of results) {
            if (
                result.status === 'rejected' ||
                !result.value ||
                result.value.sources.length === 0
            ) {
                failCount++;
                continue;
            }
            sources.push(...result.value.sources);
            subtitles.push(...result.value.subtitles);
        }

        if (failCount > 0 && sources.length > 0) {
            diagnostics.push({
                code: 'PARTIAL_SCRAPE',
                message: `${failCount} of ${VIDEASY_SERVERS.length} videasy servers did not return results`,
                field: '',
                severity: 'warning'
            });
        }

        if (sources.length === 0) {
            return this.emptyResult(
                'all videasy servers returned no sources (Yoru/cdn via speedracelight)',
                media
            );
        }

        return { sources, subtitles, diagnostics };
    }

    // I have added a small identification of error in case in future we have some problem
    // if the error has all capital then it proly mean that they shifted their encryption and all
    // if it's small and has same then we might have to change a bit let's say api url ?.
    // suppose the small invalid response indicates that they might have changed their setup
    // while the capital indicates that the response might be short not enough, hope it helps.

    // fetches one server, reads plain text blob, decrypts via enc-dec.app
    private async fetchFromServer(
        server: VideasyServer,
        media: ProviderMediaObject
    ): Promise<ProviderResult | null> {
        const seed = await this.fetchSeed(String(media.tmdbId));
        if (!seed) {
            return null;
        }
        const url = this.buildRequestUrl(server, media, seed);
        let blob =
            (await fetchText(url, this.HEADERS, { useProxy: true })) ||
            null;
        if (!blob) {
            const response = await fetch(url, { headers: this.HEADERS });
            if (!response.ok) {
                return null;
            }
            blob = await response.text();
        }

        if (!blob || blob.length < 10) {
            return null;
        }

        const decrypted = await decryptResponse(blob, String(media.tmdbId), seed);

        if (!decrypted || decrypted.sources.length === 0) {
            return null;
        }

        const sources: ProviderResult['sources'] = decrypted.sources
            .filter((s) => !!s?.url)
            .map((s) => ({
                url: this.createProxyUrl(s.url, this.HEADERS),
                type: this.detectType(s.url, s.type),
                quality: this.normalizeQuality(s.quality),
                audioTracks: [
                    {
                        language: this.resolveLanguage(server),
                        label: this.resolveLanguageLabel(server)
                    }
                ],
                provider: { id: this.id, name: this.name }
            }));

        const subtitles: ProviderResult['subtitles'] = decrypted.subtitles
            .filter((s) => !!s?.url)
            .map((s) => ({
                url: this.createProxyUrl(s.url, {}),
                label: s.lang ?? s.language ?? 'Unknown',
                format: 'vtt' as const
            }));

        return { sources, subtitles, diagnostics: [] };
    }

    // Current Videasy/Yoru API (speedracelight) needs short-lived seed + enc=2.
    // Minimal query works: mediaType + tmdbId + enc + seed (cdn/Yoru).
    private seedCache = new Map<string, { seed: string; exp: number }>();

    private async fetchSeed(tmdbId: string): Promise<string | null> {
        const hit = this.seedCache.get(tmdbId);
        if (hit && hit.exp > Date.now()) {
            return hit.seed;
        }

        for (let attempt = 0; attempt < 3; attempt++) {
            try {
                // Prefer residential/outbound proxy when configured (CF rate-limits datacenter IPs).
                const text = await fetchText(
                    `${VIDEASY_API}/seed?mediaId=${encodeURIComponent(tmdbId)}`,
                    this.HEADERS,
                    { useProxy: true }
                );
                if (!text || text.trim().startsWith('<')) {
                    // fallback direct once proxy missing/failed
                    const res = await fetch(
                        `${VIDEASY_API}/seed?mediaId=${encodeURIComponent(tmdbId)}`,
                        { headers: this.HEADERS }
                    );
                    if (!res.ok) {
                        await new Promise((r) => setTimeout(r, 400 * (attempt + 1)));
                        continue;
                    }
                    const json = (await res.json()) as { seed?: string; ttlMs?: number };
                    if (typeof json.seed === 'string' && json.seed) {
                        this.seedCache.set(tmdbId, {
                            seed: json.seed,
                            exp: Date.now() + Math.min(Number(json.ttlMs ?? 25000), 25000)
                        });
                        return json.seed;
                    }
                    continue;
                }
                const json = JSON.parse(text) as { seed?: string; ttlMs?: number };
                if (typeof json.seed === 'string' && json.seed) {
                    this.seedCache.set(tmdbId, {
                        seed: json.seed,
                        exp: Date.now() + Math.min(Number(json.ttlMs ?? 25000), 25000)
                    });
                    return json.seed;
                }
            } catch {
                await new Promise((r) => setTimeout(r, 400 * (attempt + 1)));
            }
        }
        return null;
    }

    private buildRequestUrl(
        server: VideasyServer,
        media: ProviderMediaObject,
        seed: string
    ): string {
        const params = new URLSearchParams({
            mediaType: media.type === 'movie' ? 'movie' : 'tv',
            tmdbId: String(media.tmdbId),
            enc: '2',
            seed
        });

        if (media.type === 'tv') {
            params.set('seasonId', String(media.s ?? 1));
            params.set('episodeId', String(media.e ?? 1));
        }

        // Optional extras help some non-cdn servers
        if (media.imdbId) params.set('imdbId', media.imdbId);
        if (media.title) {
            params.set(
                'title',
                encodeURIComponent(encodeURIComponent(media.title))
            );
        }
        if (media.type === 'movie' && media.releaseYear) {
            params.set('year', String(media.releaseYear));
        }
        if (server.language) {
            params.set('language', server.language);
        }

        return `${server.url}?${params.toString()}`;
    }

    // detects stream type from url extension and api hint
    private detectType(url: string, hint?: string): 'hls' | 'mp4' {
        const lower = (hint ?? '').toLowerCase();
        if (
            lower.includes('hls') ||
            lower.includes('m3u8') ||
            url.toLowerCase().includes('.m3u8')
        ) {
            return 'hls';
        }
        return 'mp4';
    }

    // guards against language labels being passed as quality (e.g. "Hindi")
    private normalizeQuality(raw?: string): string {
        if (!raw) return 'unknown';
        return /^\d{3,4}p$|^4K$|^8K$|^HD$|^SD$/i.test(raw.trim())
            ? raw.trim()
            : 'unknown';
    }

    private resolveLanguage(server: VideasyServer): string {
        if (!server.language) return 'en';
        const map: Record<string, string> = {
            german: 'de',
            italian: 'it',
            french: 'fr'
        };
        return map[server.language] ?? 'en';
    }

    private resolveLanguageLabel(server: VideasyServer): string {
        if (!server.language) return 'English';
        const map: Record<string, string> = {
            german: 'German',
            italian: 'Italian',
            french: 'French'
        };
        return map[server.language] ?? 'English';
    }

    private emptyResult(
        message: string,
        _media: ProviderMediaObject
    ): ProviderResult {
        return {
            sources: [],
            subtitles: [],
            diagnostics: [
                {
                    code: 'PROVIDER_ERROR',
                    message: `${this.name}: ${message}`,
                    field: '',
                    severity: 'error'
                }
            ]
        };
    }

    async healthCheck(): Promise<boolean> {
        try {
            const res = await fetch(this.BASE_URL, {
                method: 'HEAD',
                headers: this.HEADERS
            });
            return res.status < 500;
        } catch {
            return false;
        }
    }
}
