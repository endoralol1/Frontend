import { BaseProvider } from '@omss/framework';
import type {
    ProviderCapabilities,
    ProviderMediaObject,
    ProviderResult,
    Source,
    Subtitle
} from '@omss/framework';

import { HEADERS } from '../vidapi/config.js';
import { inferSourceType } from '../vidapi/embed-resolver.js';
import { resolveVaplayerStreams, VAPLAYER_BASE } from '../vidapi/vaplayer-resolver.js';

function formatVaplayerFailure(reason?: string) {
    if (!reason) {
        return 'No playable streams found via vaplayer.ru API.';
    }

    // Catalog miss — not an outage.
    if (
        reason.includes('no streams for this title') ||
        reason.includes('no usable stream URLs') ||
        reason.includes('streamdata status 404')
    ) {
        return reason;
    }

    return `API unavailable (${reason})`;
}

export class VaplayerProvider extends BaseProvider {
    readonly id = 'vaplayer';
    readonly name = 'VAPlayer';
    readonly enabled = true;
    readonly BASE_URL = VAPLAYER_BASE;
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
        try {
            const vaplayer = await resolveVaplayerStreams(media);
            if (!vaplayer?.streams.length) {
                return this.emptyResult(formatVaplayerFailure(vaplayer?.failureReason));
            }

            return {
                sources: this.mapSources(
                    vaplayer.streams,
                    vaplayer.fileName,
                    vaplayer.referer
                ),
                subtitles: this.mapSubtitles(vaplayer.subtitles),
                diagnostics: []
            };
        } catch (error) {
            return this.emptyResult(
                error instanceof Error ? error.message : 'Unknown VAPlayer provider error'
            );
        }
    }

    private mapSources(streamUrls: string[], fileName?: string, referer?: string): Source[] {
        const vaplayerHeaders = {
            ...this.HEADERS,
            Referer: referer ?? 'https://nextgencloudfabric.com/',
            Origin: 'https://nextgencloudfabric.com'
        };

        return streamUrls.map((streamUrl) => ({
            url: this.createProxyUrl(streamUrl, vaplayerHeaders),
            type: inferSourceType(streamUrl),
            quality: this.inferQuality(fileName ?? streamUrl),
            audioTracks: [
                {
                    label: 'Original',
                    language: 'Original'
                }
            ],
            provider: {
                id: this.id,
                name: this.name
            }
        }));
    }

    private mapSubtitles(defaultSubs: Array<{ lang: string; code: string; url: string }>) {
        return defaultSubs.map(
            (sub): Subtitle => {
                const ext = sub.url.split('.').pop()?.toLowerCase();
                const format =
                    ext === 'vtt'
                        ? 'vtt'
                        : ext === 'ass'
                          ? 'ass'
                          : ext === 'ssa'
                            ? 'ssa'
                            : ext === 'ttml'
                              ? 'ttml'
                              : 'srt';

                return {
                    url: sub.url,
                    label: sub.lang,
                    format
                };
            }
        );
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
