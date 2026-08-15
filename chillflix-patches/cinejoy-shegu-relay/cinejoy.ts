import { BaseProvider } from '@omss/framework';
import type {
    ProviderCapabilities,
    ProviderMediaObject,
    ProviderResult,
    Source
} from '@omss/framework';

import { resolveCinejoyPlaylist } from './lumen-client.js';
import { relayAwareFetch } from '../../utils/worker-http.js';

const RESULT_TTL_MS = 10 * 60 * 1000;
const resultCache = new Map<string, { expiresAt: number; result: ProviderResult }>();

/**
 * Cinejoy.to (shegu.st / earthcleaner HLS).
 * Shown to users as "4K"; master playlist defaults to Full HD when available.
 */
export class CinejoyProvider extends BaseProvider {
    readonly id = 'cinejoy';
    readonly name = '4K';
    readonly enabled = true;
    readonly BASE_URL = 'https://cinejoy.to/';
    readonly HEADERS: Record<string, string> = {
        'User-Agent':
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/137.0.0.0 Safari/537.36',
        Referer: 'https://cinejoy.to/',
        Origin: 'https://cinejoy.to'
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

    private yearOf(media: ProviderMediaObject): string | undefined {
        const raw = (media as { year?: string | number; releaseDate?: string }).year
            ?? (media as { releaseDate?: string }).releaseDate
            ?? '';
        const s = String(raw);
        const m = s.match(/(\d{4})/);
        return m?.[1];
    }

    private async resolve(media: ProviderMediaObject): Promise<ProviderResult> {
        const key = this.cacheKey(media);
        const hit = resultCache.get(key);
        if (hit && hit.expiresAt > Date.now()) return hit.result;

        if (!media.tmdbId) return this.emptyResult('Missing TMDB id');
        if (!media.title) return this.emptyResult('Missing title');

        try {
            const resolved = await resolveCinejoyPlaylist({
                type: media.type === 'tv' ? 'tv' : 'movie',
                tmdbId: String(media.tmdbId),
                imdbId: media.imdbId ? String(media.imdbId) : undefined,
                title: String(media.title),
                year: this.yearOf(media),
                season: media.type === 'tv' ? Number(media.s ?? 1) || 1 : undefined,
                episode: media.type === 'tv' ? Number(media.e ?? 1) || 1 : undefined
            });

            // Prefer advertising Full HD as the default pick when 4K isn't present;
            // when 4K exists, still label quality as 4K but the master playlist
            // defaults to 1080p via the CDN DEFAULT=YES tag.
            const quality =
                resolved.quality === '4K'
                    ? '4K'
                    : resolved.quality === '1080p'
                      ? '1080p'
                      : resolved.quality || '1080p';

            const sources: Source[] = [
                {
                    url: resolved.playlist,
                    type: 'hls',
                    quality,
                    audioTracks: [{ label: 'Original', language: 'Original' }],
                    provider: { id: this.id, name: this.name }
                }
            ];

            const result: ProviderResult = { sources, subtitles: [], diagnostics: [] };
            resultCache.set(key, { expiresAt: Date.now() + RESULT_TTL_MS, result });
            return result;
        } catch (error) {
            return this.emptyResult(error instanceof Error ? error.message : 'provider error');
        }
    }

    async healthCheck(): Promise<boolean> {
        try {
            const res = await relayAwareFetch('https://api.shegu.st/servers', {
                headers: this.HEADERS,
                signal: AbortSignal.timeout(8_000)
            });
            return res.ok;
        } catch {
            return false;
        }
    }
}
