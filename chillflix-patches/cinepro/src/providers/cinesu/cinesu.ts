import { BaseProvider } from '@omss/framework'
import type {
    ProviderCapabilities,
    ProviderMediaObject,
    ProviderResult
} from '@omss/framework'
import {
    buildGlendaleMasterUrl,
    parseGlendaleVariants,
    type GlendaleMedia
} from './glendale.js'

export class CineSuProvider extends BaseProvider {
    readonly id = 'CineSu'
    readonly name = 'CineSu'
    readonly enabled = true
    readonly BASE_URL = 'https://cine.su'
    readonly HEADERS = {
        'User-Agent':
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36',
        Accept: '*/*',
        'Accept-Language': 'en-US,en;q=0.9'
    }
    readonly capabilities: ProviderCapabilities = {
        supportedContentTypes: ['movies', 'tv']
    }

    async getMovieSources(media: ProviderMediaObject): Promise<ProviderResult> {
        return this.getSources(media)
    }

    async getTVSources(media: ProviderMediaObject): Promise<ProviderResult> {
        return this.getSources(media)
    }

    private buildWatchReferer(media: ProviderMediaObject): string {
        const mediaType = String(media.type).toLowerCase()
        if (mediaType === 'movie' || mediaType === 'movies') {
            return `${this.BASE_URL}/en/watch-movie/${media.tmdbId}`
        }
        return `${this.BASE_URL}/en/watch-tv/${media.tmdbId}`
    }

    private buildRequestHeaders(
        media: ProviderMediaObject
    ): Record<string, string> {
        return {
            ...this.HEADERS,
            Referer: this.buildWatchReferer(media),
            Origin: this.BASE_URL,
            Accept: 'application/vnd.apple.mpegurl,application/x-mpegURL,*/*'
        }
    }

    private resolveGlendaleMedia(
        media: ProviderMediaObject
    ): GlendaleMedia | null {
        const mediaType = String(media.type).toLowerCase()
        const tmdbId = Number(media.tmdbId)
        if (!Number.isFinite(tmdbId) || tmdbId < 1) return null

        if (mediaType === 'movie' || mediaType === 'movies') {
            return { type: 'movie', tmdbId }
        }
        if (mediaType === 'tv') {
            const season = Number(media.s ?? 0)
            const episode = Number(media.e ?? 0)
            if (!season || !episode) return null
            return { type: 'show', tmdbId, season, episode }
        }
        return null
    }

    private async getSources(
        media: ProviderMediaObject
    ): Promise<ProviderResult> {
        try {
            const glendaleMedia = this.resolveGlendaleMedia(media)
            if (!glendaleMedia) {
                return this.emptyResult('unsupported or incomplete media object')
            }

            const headers = this.buildRequestHeaders(media)
            const masterUrl = buildGlendaleMasterUrl(glendaleMedia)

            const response = await fetch(masterUrl, {
                method: 'GET',
                headers,
                signal: AbortSignal.timeout(20_000)
            })

            if (!response.ok) {
                return this.emptyResult(
                    `glendale master returned HTTP ${response.status}`
                )
            }

            const body = await response.text()
            if (!body.includes('#EXTM3U')) {
                return this.emptyResult('glendale response was not an HLS playlist')
            }

            const variants = parseGlendaleVariants(body, masterUrl)
            const provider = { name: this.name, id: this.id }

            const audioTracks = [
                {
                    label: 'English',
                    language: 'eng'
                }
            ]

            // Auto = full master (proxy rewrites relative variant URIs).
            const sources = [
                {
                    url: this.createProxyUrl(masterUrl, headers),
                    quality: 'Auto',
                    type: 'hls' as const,
                    audioTracks,
                    provider
                },
                ...variants.map((variant) => ({
                    url: this.createProxyUrl(variant.url, headers),
                    quality: variant.quality,
                    type: 'hls' as const,
                    audioTracks,
                    provider
                }))
            ]

            // De-dupe if Auto is the only stream and no discrete variants.
            const unique = sources.filter((source, index, list) => {
                if (index === 0) return true
                return !list
                    .slice(0, index)
                    .some(
                        (prior) =>
                            prior.quality === source.quality &&
                            prior.url === source.url
                    )
            })

            if (!unique.length) {
                return this.emptyResult('no playable Glendale variants')
            }

            return {
                sources: unique,
                subtitles: [],
                diagnostics: []
            }
        } catch (error) {
            return this.emptyResult(
                error instanceof Error ? error.message : 'Unknown provider error'
            )
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
        }
    }

    async healthCheck(): Promise<boolean> {
        try {
            const masterUrl = buildGlendaleMasterUrl({ type: 'movie', tmdbId: 550 })
            const response = await fetch(masterUrl, {
                method: 'GET',
                headers: {
                    ...this.HEADERS,
                    Referer: `${this.BASE_URL}/en/watch-movie/550`,
                    Origin: this.BASE_URL
                },
                signal: AbortSignal.timeout(10_000)
            })
            if (!response.ok) return false
            const body = await response.text()
            return body.includes('#EXTM3U')
        } catch {
            return false
        }
    }
}
