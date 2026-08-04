import { BaseProvider } from '@omss/framework';
import type {
    ProviderCapabilities,
    ProviderMediaObject,
    ProviderResult,
    Source
} from '@omss/framework';

import { pickDirectUrl, resolveHimoviesPhp } from '../himovies-php/resolve-php.js';

export class VidmolyProvider extends BaseProvider {
    readonly id = 'vidmoly';
    readonly name = 'Vidmoly';
    readonly enabled = true;
    readonly BASE_URL = 'https://vidmoly.biz';
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
            const result = await resolveHimoviesPhp('vidmoly', media);
            const sources = this.mapSources(result.sources ?? []);
            if (!sources.length) {
                return this.emptyResult(
                    result.error || 'No playable streams from Vidmoly'
                );
            }
            return { sources, subtitles: [], diagnostics: [] };
        } catch (error) {
            return this.emptyResult(
                error instanceof Error ? error.message : 'Vidmoly provider error'
            );
        }
    }

    private mapSources(
        raw: NonNullable<Awaited<ReturnType<typeof resolveHimoviesPhp>>['sources']>
    ): Source[] {
        const out: Source[] = [];
        for (const src of raw) {
            const direct = pickDirectUrl(src);
            if (!direct) continue;
            const embed =
                typeof src.meta?.embed === 'string' && src.meta.embed
                    ? src.meta.embed
                    : 'https://vidmoly.biz/';
            const headers = {
                ...this.HEADERS,
                Referer: embed,
                Origin: 'https://vidmoly.biz',
                Accept: '*/*',
                'Sec-Fetch-Dest': 'empty',
                'Sec-Fetch-Mode': 'cors',
                'Sec-Fetch-Site': 'cross-site'
            };
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
