import { BaseProvider } from '@omss/framework';
import type {
    ProviderCapabilities,
    ProviderMediaObject,
    ProviderResult,
    Source
} from '@omss/framework';

import { NOTORRENT_ADDON_BASE, NOTORRENT_HEADERS } from './constants.js';
import {
    fetchNoTorrentStreams,
    notorrentProxyHeaders
} from './stream-resolve.js';

export class NoTorrentProvider extends BaseProvider {
    readonly id = 'notorrent';
    readonly name = 'NoTorrent';
    readonly enabled = true;
    readonly BASE_URL = NOTORRENT_ADDON_BASE;
    readonly HEADERS = NOTORRENT_HEADERS;

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
            const streams = await fetchNoTorrentStreams(media);

            const sources: Source[] = streams.map((stream) => ({
                url: this.createProxyUrl(
                    stream.playbackUrl,
                    notorrentProxyHeaders(stream.playbackUrl)
                ),
                type: stream.type,
                quality: stream.quality,
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

            return {
                sources,
                subtitles: [],
                diagnostics: []
            };
        } catch (error) {
            return this.emptyResult(
                error instanceof Error ? error.message : 'Unknown NoTorrent provider error'
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
            const response = await fetch(`${this.BASE_URL}/manifest.json`, {
                headers: this.HEADERS
            });
            return response.ok;
        } catch {
            return false;
        }
    }
}
