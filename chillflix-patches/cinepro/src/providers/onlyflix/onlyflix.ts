import { BaseProvider } from '@omss/framework';
import type {
    ProviderCapabilities,
    ProviderMediaObject,
    ProviderResult,
    Source
} from '@omss/framework';

import {
    rankOnlyFlixPlayers,
    resolveOnlyFlixPlayerSource
} from './embed-resolver.js';
import { fetchOnlyFlixPlayers } from './player-api.js';
import { BASE_URL, HEADERS, resolveOnlyFlixPost } from './slug-resolver.js';

/** Keep well under CinePro PROVIDER_TIMEOUT_MS even when the host is busy. */
const TOTAL_BUDGET_MS = 28_000;
const PLAYER_RESOLVE_TIMEOUT_MS = 10_000;
const MAX_SOURCES = 3;

function withTimeout<T>(promise: Promise<T>, ms: number): Promise<T | null> {
    let timer: ReturnType<typeof setTimeout> | undefined;
    return Promise.race([
        promise.then((value) => value),
        new Promise<null>((resolve) => {
            timer = setTimeout(() => resolve(null), ms);
        })
    ]).finally(() => {
        if (timer) clearTimeout(timer);
    });
}

export class OnlyFlixProvider extends BaseProvider {
    readonly id = 'onlyflix';
    readonly name = 'OnlyFlix';
    readonly enabled = true;
    readonly BASE_URL = BASE_URL;
    readonly HEADERS = HEADERS;

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
        const startedAt = Date.now();

        try {
            const post = await resolveOnlyFlixPost(media);
            if (!post) {
                return this.emptyResult(
                    `No OnlyFlix page found for "${media.title}" (${media.releaseYear || 'unknown year'}).`
                );
            }

            const players = await fetchOnlyFlixPlayers(post);
            if (!players.length) {
                return this.emptyResult(
                    `OnlyFlix page ${post.pageUrl} returned no available servers.`
                );
            }

            // Sequential + ranked: first successful server is enough for playback,
            // and avoids a thundering herd of nested scrapers under Chillflix load.
            const ranked = rankOnlyFlixPlayers(players);
            const sources: Source[] = [];

            for (const player of ranked) {
                if (sources.length >= MAX_SOURCES) break;
                if (Date.now() - startedAt > TOTAL_BUDGET_MS) break;

                const remaining = TOTAL_BUDGET_MS - (Date.now() - startedAt);
                const budget = Math.min(PLAYER_RESOLVE_TIMEOUT_MS, Math.max(2_000, remaining));

                const source = await withTimeout(
                    resolveOnlyFlixPlayerSource(
                        player,
                        media,
                        post.pageUrl,
                        (url, headers) => this.createProxyUrl(url, headers)
                    ),
                    budget
                );

                if (source) {
                    sources.push(source);
                    // One solid stream is enough to stop blocking the provider slot.
                    if (sources.length >= 1 && Date.now() - startedAt > 8_000) {
                        break;
                    }
                }
            }

            if (!sources.length) {
                return this.emptyResult(
                    `OnlyFlix found ${players.length} server embed(s) but could not resolve playable streams.`
                );
            }

            const diagnostics: ProviderResult['diagnostics'] = [];
            if (sources.length < players.length) {
                diagnostics.push({
                    code: 'PARTIAL_SCRAPE',
                    message: `OnlyFlix resolved ${sources.length}/${players.length} servers for ${post.slug}.`,
                    field: '',
                    severity: 'warning'
                });
            }

            return {
                sources,
                subtitles: [],
                diagnostics
            };
        } catch (error) {
            return this.emptyResult(
                error instanceof Error ? error.message : 'Unknown OnlyFlix provider error'
            );
        }
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

    async healthCheck(): Promise<boolean> {
        try {
            const response = await fetch(this.BASE_URL, {
                method: 'HEAD',
                headers: this.HEADERS
            });
            return response.ok;
        } catch {
            return false;
        }
    }
}
