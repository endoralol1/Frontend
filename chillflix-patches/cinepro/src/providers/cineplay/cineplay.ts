import { BaseProvider } from '@omss/framework';
import type {
    ProviderCapabilities,
    ProviderMediaObject,
    ProviderResult,
    Source
} from '@omss/framework';

import {
    pickWorker,
    readWorkerRelayConfig
} from '../../config/worker-relay.js';
import { inferSourceType } from '../vidapi/embed-resolver.js';

const EMBED_ORIGIN = 'https://www.vidking.net';
const EMBED_REFERER = 'https://www.vidking.net/';

type RelaySource = {
    url?: string;
    quality?: string;
    type?: string;
};

function qualityRank(quality: string): number {
    const q = quality.toLowerCase();
    if (q.includes('2160') || q.includes('4k') || q.includes('uhd')) return 400;
    if (q.includes('1080')) return 300;
    if (q.includes('720')) return 200;
    if (q.includes('480')) return 100;
    if (q.includes('360')) return 50;
    return 10;
}

/**
 * Cineplay / Yoru (same source Vuflix uses) via shared Cloudflare Worker `/resolve`.
 */
export class CineplayProvider extends BaseProvider {
    readonly id = 'cineplay';
    readonly name = 'Cineplay';
    readonly enabled = true;
    readonly BASE_URL = EMBED_ORIGIN;
    readonly HEADERS = {
        'User-Agent':
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/137.0.0.0 Safari/537.36',
        Accept: 'application/json, */*; q=0.01',
        Referer: EMBED_REFERER,
        Origin: EMBED_ORIGIN
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

    private async getSources(media: ProviderMediaObject): Promise<ProviderResult> {
        try {
            const relay = readWorkerRelayConfig();
            if (!relay.enabled) {
                return this.emptyResult('Worker relay disabled');
            }

            const worker = pickWorker(relay);
            if (!worker) {
                return this.emptyResult('No Worker configured for Cineplay/Yoru');
            }

            const type = media.type === 'tv' ? 'tv' : 'movie';
            const url = new URL(`${worker.url}/resolve`);
            url.searchParams.set('type', type);
            url.searchParams.set('tmdb', String(media.tmdbId ?? ''));
            url.searchParams.set('key', worker.secret);
            url.searchParams.set('cacheTtl', String(relay.cacheTtlSeconds));
            if (media.title) url.searchParams.set('title', String(media.title));
            if (media.releaseYear) {
                url.searchParams.set('year', String(media.releaseYear));
            }
            if (media.imdbId?.startsWith('tt')) {
                url.searchParams.set('imdb', media.imdbId);
            }
            if (type === 'tv') {
                url.searchParams.set('season', String(media.s ?? 1));
                url.searchParams.set('episode', String(media.e ?? 1));
            }

            const response = await fetch(url.toString(), {
                headers: {
                    Accept: 'application/json',
                    'x-yoru-key': worker.secret
                },
                signal: AbortSignal.timeout(45_000)
            });
            const json = (await response.json().catch(() => null)) as {
                ok?: boolean;
                error?: string;
                sources?: RelaySource[];
            } | null;

            if (!response.ok || !json?.ok || !Array.isArray(json.sources) || !json.sources.length) {
                return this.emptyResult(
                    json?.error
                        ? `Cineplay relay: ${json.error}`
                        : `Cineplay relay HTTP ${response.status}`
                );
            }

            const mapped = this.mapSources(json.sources);
            if (!mapped.length) {
                return this.emptyResult('Cineplay relay returned no usable streams');
            }

            return { sources: mapped, subtitles: [], diagnostics: [] };
        } catch (error) {
            return this.emptyResult(
                error instanceof Error ? error.message : 'Cineplay provider error'
            );
        }
    }

    private mapSources(raw: RelaySource[]): Source[] {
        const headers = { ...this.HEADERS };
        const items = raw
            .map((src) => {
                const streamUrl = typeof src?.url === 'string' ? src.url.trim() : '';
                if (!streamUrl || streamUrl.includes('strategicgrowthpartners')) {
                    return null;
                }
                const quality = String(src.quality || 'Auto').trim() || 'Auto';
                return {
                    url: this.createProxyUrl(streamUrl, headers),
                    type: inferSourceType(streamUrl),
                    quality,
                    audioTracks: [{ language: 'en', label: 'English' }],
                    provider: { id: this.id, name: this.name },
                    _rank: qualityRank(quality)
                };
            })
            .filter((s): s is NonNullable<typeof s> => Boolean(s))
            .sort((a, b) => b._rank - a._rank)
            .map(({ _rank, ...source }) => source);

        return items;
    }

    private emptyResult(message: string): ProviderResult {
        return {
            sources: [],
            subtitles: [],
            diagnostics: [
                {
                    code: 'CINEPLAY_EMPTY',
                    message,
                    severity: 'warning',
                    provider: this.id
                }
            ]
        };
    }
}
