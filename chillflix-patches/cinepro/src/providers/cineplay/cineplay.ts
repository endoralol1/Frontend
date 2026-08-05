import { BaseProvider } from '@omss/framework';
import type {
    ProviderCapabilities,
    ProviderMediaObject,
    ProviderResult,
    Source
} from '@omss/framework';

import {
    listEnabledWorkers,
    pickWorker,
    readWorkerRelayConfig,
    type WorkerRelayEntry
} from '../../config/worker-relay.js';
import { inferSourceType } from '../vidapi/embed-resolver.js';

const EMBED_ORIGIN = 'https://www.vidking.net';
const EMBED_REFERER = 'https://www.vidking.net/';

type RelaySource = {
    url?: string;
    quality?: string;
    type?: string;
};

type RelayResponse = {
    ok?: boolean;
    error?: string;
    sources?: RelaySource[];
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

/** Retry other Workers only on auth/config failures — not upstream seed 429 (avoids burning free-tier quota). */
function shouldTryNextWorker(error: string | undefined, status: number): boolean {
    const msg = (error || '').toLowerCase();
    if (status === 401 || status === 403) return true;
    return msg.includes('unauthorized') || msg.includes('invalid key') || msg.includes('forbidden');
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

            const workers = listEnabledWorkers(relay);
            if (!workers.length) {
                return this.emptyResult('No Worker configured for Cineplay/Yoru');
            }

            // Start from round-robin pick, then walk the rest of the pool on soft failures.
            const start = pickWorker(relay) || workers[0];
            const startIdx = Math.max(
                0,
                workers.findIndex((w) => w.id === start.id)
            );
            const ordered: WorkerRelayEntry[] = [
                ...workers.slice(startIdx),
                ...workers.slice(0, startIdx)
            ];

            const type = media.type === 'tv' ? 'tv' : 'movie';
            let lastError = 'Cineplay relay returned no streams';

            for (const worker of ordered) {
                const url = new URL(`${worker.url}/resolve`);
                url.searchParams.set('type', type);
                url.searchParams.set('tmdb', String(media.tmdbId ?? ''));
                url.searchParams.set('season', String(media.s ?? 1));
                url.searchParams.set('episode', String(media.e ?? 1));
                url.searchParams.set('cacheTtl', String(relay.cacheTtlSeconds));
                if (media.title) url.searchParams.set('title', String(media.title));
                if (media.releaseYear) {
                    url.searchParams.set('year', String(media.releaseYear));
                }
                if (media.imdbId?.startsWith('tt')) {
                    url.searchParams.set('imdb', media.imdbId);
                }

                let response: Response;
                let json: RelayResponse | null;
                try {
                    response = await fetch(url.toString(), {
                        headers: {
                            Accept: 'application/json',
                            'User-Agent': 'VuflixYoruRelay/1.0',
                            'X-Yoru-Key': worker.secret
                        },
                        signal: AbortSignal.timeout(45_000)
                    });
                    json = (await response.json().catch(() => null)) as RelayResponse | null;
                } catch (error) {
                    lastError =
                        error instanceof Error
                            ? `${worker.label}: ${error.message}`
                            : `${worker.label}: network error`;
                    continue;
                }

                if (response.ok && json?.ok && Array.isArray(json.sources) && json.sources.length) {
                    const mapped = this.mapSources(json.sources);
                    if (mapped.length) {
                        return { sources: mapped, subtitles: [], diagnostics: [] };
                    }
                    lastError = `${worker.label}: no usable streams`;
                    continue;
                }

                lastError = json?.error
                    ? `${worker.label}: ${json.error}`
                    : `${worker.label}: HTTP ${response.status}`;

                if (!shouldTryNextWorker(json?.error, response.status)) {
                    break;
                }
            }

            return this.emptyResult(`Cineplay relay: ${lastError}`);
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
                    code: 'PROVIDER_ERROR',
                    message: `${this.name}: ${message}`,
                    field: '',
                    severity: 'error'
                }
            ]
        };
    }
}
