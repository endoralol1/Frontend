import { BaseProvider } from '@omss/framework';
import type {
    ProviderCapabilities,
    ProviderMediaObject,
    ProviderResult,
    Source
} from '@omss/framework';

import { pickDirectUrl, resolveHimoviesPhp } from '../himovies-php/resolve-php.js';

export class UpcloudProvider extends BaseProvider {
    readonly id = 'upcloud';
    readonly name = 'UpCloud';
    readonly enabled = true;
    readonly BASE_URL = 'https://gn1r5n.org';
    readonly HEADERS = {
        'User-Agent':
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/137.0.0.0 Safari/537.36',
        Accept: '*/*'
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
            const result = await resolveHimoviesPhp('upcloud', media);
            const sources = this.mapSources(result.sources ?? []);
            if (!sources.length) {
                return this.emptyResult(
                    result.error || 'No playable streams from UpCloud/Byse'
                );
            }
            return { sources, subtitles: [], diagnostics: [] };
        } catch (error) {
            return this.emptyResult(
                error instanceof Error ? error.message : 'UpCloud provider error'
            );
        }
    }

    private mapSources(
        raw: NonNullable<Awaited<ReturnType<typeof resolveHimoviesPhp>>['sources']>
    ): Source[] {
        const headers = {
            ...this.HEADERS,
            Referer: 'https://gn1r5n.org/',
            Origin: 'https://gn1r5n.org'
        };
        const out: Source[] = [];
        for (const src of raw) {
            const direct = pickDirectUrl(src);
            if (!direct) continue;
            out.push({
                url: this.createProxyUrl(direct, headers),
                type: 'hls',
                quality: String(src.quality || 'Auto'),
                audioTracks: [{ label: 'Original', language: 'Original' }],
                provider: { id: this.id, name: this.name }
            });
        }
        return out;
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
