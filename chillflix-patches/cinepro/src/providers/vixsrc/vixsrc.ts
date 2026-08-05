import { BaseProvider } from '@omss/framework';
import type {
    ProviderCapabilities,
    ProviderMediaObject,
    ProviderResult,
    Source,
    Subtitle
} from '@omss/framework';
import { ProxyAgent, type Dispatcher } from 'undici';
import {
    flareSolverrGet,
    hasFlareSolverrConfigured
} from '../cinemacity/flaresolverr-client.js';
import { VixSrcApiResponse } from './vixsrc.types.js';

function resolveVixSrcProxyUrl(): string | undefined {
    const raw =
        process.env.VIXSRC_PROXY?.trim() ||
        process.env.PROVIDER_FETCH_PROXY?.trim() ||
        process.env.OUTBOUND_HTTP_PROXY?.trim() ||
        '';

    return raw || undefined;
}

export class VixSrcProvider extends BaseProvider {
    readonly id = 'vixsrc';
    readonly name = 'VixSrc';
    readonly enabled = true;
    readonly BASE_URL = 'https://vixsrc.to';
    readonly HEADERS = {
        'User-Agent':
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150 Safari/537.36',
        Accept: 'application/json, text/javascript, */*; q=0.01',
        'Accept-Language': 'en-US,en;q=0.9',
        Referer: this.BASE_URL,
        Origin: this.BASE_URL
    };

    private readonly fetchDispatcher: Dispatcher | undefined = (() => {
        const proxy = resolveVixSrcProxyUrl();
        return proxy ? new ProxyAgent(proxy) : undefined;
    })();

    private flareSessionId?: string;
    private flareCookieHeader?: string;
    private flareUserAgent?: string;

    private buildRequestHeaders(
        extra?: Record<string, string>
    ): Record<string, string> {
        const headers: Record<string, string> = {
            ...this.HEADERS,
            ...extra
        };

        if (this.flareCookieHeader) {
            headers.Cookie = this.flareCookieHeader;
        }

        if (this.flareUserAgent) {
            headers['User-Agent'] = this.flareUserAgent;
        }

        return headers;
    }

    private async fetchBodyViaFlareSolverr(url: string): Promise<string | null> {
        if (!hasFlareSolverrConfigured()) {
            return null;
        }

        const { session, html } = await flareSolverrGet(
            url,
            this.flareSessionId
        );

        if (session?.cookieHeader) {
            this.flareSessionId = session.flareSessionId ?? this.flareSessionId;
            this.flareCookieHeader = session.cookieHeader;
            this.flareUserAgent = session.userAgent;
        }

        return html ?? null;
    }

    private providerFetch(
        url: string,
        init: RequestInit = {}
    ): Promise<Response> {
        const headers = this.buildRequestHeaders(
            (init.headers as Record<string, string> | undefined) ?? undefined
        );
        const requestInit: RequestInit = { ...init, headers };

        if (this.fetchDispatcher) {
            return fetch(url, {
                ...requestInit,
                dispatcher: this.fetchDispatcher
            } as unknown as RequestInit);
        }

        return fetch(url, requestInit);
    }

    private async providerFetchText(
        url: string,
        extraHeaders?: Record<string, string>
    ): Promise<{ status: number; body: string | null }> {
        try {
            const response = await this.providerFetch(url, {
                headers: extraHeaders ?? this.buildRequestHeaders()
            });

            if (response.status === 200) {
                return { status: response.status, body: await response.text() };
            }

            if (
                (response.status === 403 || response.status === 401) &&
                hasFlareSolverrConfigured()
            ) {
                const flared = await this.fetchBodyViaFlareSolverr(url);
                if (flared) {
                    return { status: 200, body: flared };
                }
            }

            return { status: response.status, body: null };
        } catch {
            if (hasFlareSolverrConfigured()) {
                const flared = await this.fetchBodyViaFlareSolverr(url);
                if (flared) {
                    return { status: 200, body: flared };
                }
            }

            return { status: 0, body: null };
        }
    }

    readonly capabilities: ProviderCapabilities = {
        supportedContentTypes: ['movies', 'tv']
    };

    /**
     * Fetch movie sources
     */
    async getMovieSources(media: ProviderMediaObject): Promise<ProviderResult> {
        return this.getSources(media);
    }

    /**
     * Fetch TV episode sources
     */
    async getTVSources(media: ProviderMediaObject): Promise<ProviderResult> {
        return this.getSources(media);
    }

    /**
     * Main scraping logic
     * (Worker /vixsrc removed — VixSrc no longer uses Cloudflare Worker quota.)
     */
    private async getSources(
        media: ProviderMediaObject
    ): Promise<ProviderResult> {
        try {
            const pageUrl = this.buildPageUrl(media);

            const apiResult = await this.fetchApi(pageUrl);
            if (!apiResult.ok) {
                return this.emptyResult(
                    apiResult.message ?? 'Failed to fetch api',
                    media
                );
            }

            const sublink = apiResult.data;
            if (!sublink) {
                return this.emptyResult('Failed to fetch api', media);
            }

            const html = await this.fetchPage(sublink.src);
            if (!html) {
                return this.emptyResult(
                    'Failed to fetch second embed page',
                    media
                );
            }

            const tokenData = this.extractTokenData(html, media);
            if (!tokenData) {
                return this.emptyResult('Invalid or expired token', media);
            }

            const masterUrl = this.buildMasterUrl(tokenData);

            const playlistContent = await this.fetchPlaylist(
                masterUrl,
                pageUrl,
                media
            );
            if (!playlistContent) {
                return this.emptyResult('Failed to fetch playlist', media);
            }

            return this.parsePlaylist(
                playlistContent,
                masterUrl,
                pageUrl,
                media
            );
        } catch (error) {
            return this.emptyResult(
                error instanceof Error
                    ? error.message
                    : 'Unknown provider error',
                media
            );
        }
    }

    /**
     * Build page URL based on media type
     */
    private buildPageUrl(media: ProviderMediaObject): string {
        if (media.type === 'movie') {
            return `${this.BASE_URL}/api/movie/${media.tmdbId}`;
        } else {
            return `${this.BASE_URL}/api/tv/${media.tmdbId}/${media.s}/${media.e}`;
        }
    }

    private describeFetchFailure(status: number): string {
        if (status === 403 || status === 401) {
            const hints: string[] = [];

            if (!resolveVixSrcProxyUrl()) {
                hints.push(
                    'Set VIXSRC_PROXY to a residential HTTP proxy / FlareSolverr (FLARESOLVERR_URL).'
                );
            } else if (!hasFlareSolverrConfigured()) {
                hints.push(
                    'Datacenter egress is blocked — run FlareSolverr on this host (FLARESOLVERR_URL, default http://127.0.0.1:8191).'
                );
            } else {
                hints.push(
                    'Server and proxy IPs are banned by Cloudflare. Use a residential proxy.'
                );
            }

            return `API blocked by Cloudflare (${status}). ${hints.join(' ')}`;
        }

        if (status === 404) {
            return 'Title not found on VixSrc';
        }

        if (status > 0) {
            return `Failed to fetch api (HTTP ${status})`;
        }

        return 'Failed to fetch api';
    }

    /**
     * Fetch page HTML
     */
    private async fetchApi(
        url: string
    ): Promise<{ ok: boolean; data?: VixSrcApiResponse; message?: string }> {
        const { status, body } = await this.providerFetchText(url);

        if (status !== 200 || !body) {
            return {
                ok: false,
                message: this.describeFetchFailure(status)
            };
        }

        try {
            return {
                ok: true,
                data: JSON.parse(body) as VixSrcApiResponse
            };
        } catch {
            return {
                ok: false,
                message: 'Failed to parse VixSrc API response'
            };
        }
    }

    private async fetchPage(suburl: string): Promise<string | null> {
        const { status, body } = await this.providerFetchText(
            this.BASE_URL + suburl
        );
        return status === 200 && body ? body : null;
    }

    /**
     * Extract token, expires, and playlist URL from HTML
     */
    private extractTokenData(
        html: string,
        media: ProviderMediaObject
    ): { token: string; expires: string; playlist: string } | null {
        const token = html.match(/token["']\s*:\s*["']([^"']+)/)?.[1];
        const expires = html.match(/expires["']\s*:\s*["']([^"']+)/)?.[1];
        const playlist = html.match(/url\s*:\s*["']([^"']+)/)?.[1];

        if (!token || !expires || !playlist) {
            return null;
        }

        if (this.isTokenExpired(expires)) {
            return null;
        }

        return { token, expires, playlist };
    }

    /**
     * Check if token is expired
     */
    private isTokenExpired(expires: string): boolean {
        return parseInt(expires, 10) * 1000 - 60_000 < Date.now();
    }

    /**
     * Build master playlist URL with token
     */
    private buildMasterUrl(tokenData: {
        token: string;
        expires: string;
        playlist: string;
    }): string {
        const { token, expires, playlist } = tokenData;
        const separator = playlist.includes('?') ? '&' : '?';
        return `${playlist}${separator}token=${token}&expires=${expires}&h=1`;
    }

    /**
     * Fetch playlist content
     */
    private async fetchPlaylist(
        url: string,
        referer: string,
        media: ProviderMediaObject
    ): Promise<string | null> {
        const { status, body } = await this.providerFetchText(url, {
            ...this.buildRequestHeaders(),
            Referer: referer
        });
        return status === 200 && body ? body : null;
    }

    /**
     * Parse HLS playlist content
     */
    private parsePlaylist(
        content: string,
        masterUrl: string,
        pageUrl: string,
        media: ProviderMediaObject
    ): ProviderResult {
        const audioTracks = this.parseAudioTracks(content);
        const subtitles = this.parseSubtitles(content, pageUrl);
        const variants = this.parseVariants(content);

        if (variants.length === 0) {
            return this.emptyResult('No streams found in playlist', media);
        }

        const bestVariant = variants.reduce((best, current) =>
            current.resolution > best.resolution ? current : best
        );

        const sources: Source[] = [
            {
                url: this.createProxyUrl(masterUrl, {
                    ...this.HEADERS,
                    Referer: pageUrl
                }),
                type: 'hls',
                quality: `${bestVariant.resolution}p`,
                audioTracks:
                    audioTracks.length > 0
                        ? audioTracks
                        : [
                              {
                                  language: 'en',
                                  label: 'English'
                              }
                          ],
                provider: {
                    id: this.id,
                    name: this.name
                }
            }
        ];

        return {
            sources,
            subtitles,
            diagnostics:
                sources.length === 0
                    ? [
                          {
                              code: 'PARTIAL_SCRAPE',
                              message: 'No playable streams found',
                              field: 'sources',
                              severity: 'warning'
                          }
                      ]
                    : []
        };
    }

    /**
     * Parse audio tracks from HLS manifest
     */
    private parseAudioTracks(
        content: string
    ): Array<{ language: string; label: string }> {
        const tracks: Array<{ language: string; label: string }> = [];
        const lines = content.split('\n');

        for (const line of lines) {
            if (!line.startsWith('#EXT-X-MEDIA:TYPE=AUDIO')) continue;

            const language = line.match(/LANGUAGE="([^"]+)"/)?.[1] ?? 'unknown';
            const label = line.match(/NAME="([^"]+)"/)?.[1] ?? 'Audio';

            tracks.push({
                language,
                label
            });
        }

        return tracks;
    }

    /**
     * Parse subtitles from HLS manifest
     */
    private parseSubtitles(content: string, pageUrl: string): Subtitle[] {
        const subtitles: Subtitle[] = [];

        /* Doesn't work.. 
        // TODO: Fix subtitles for vixsrc
        const lines = content.split('\n');

        for (const line of lines) {
            if (!line.startsWith('#EXT-X-MEDIA:TYPE=SUBTITLES')) continue;

            const url = line.match(/URI="([^"]+)"/)?.[1];
            if (!url) continue;

            const language = line.match(/NAME="([^"]+)"/)?.[1] ?? 'unknown';

            subtitles.push({
                url: this.createProxyUrl(url, {
                    ...this.HEADERS,
                    Referer: pageUrl
                }),
                label: language,
                format: 'vtt'
            });
        }
        */

        return subtitles;
    }

    /**
     * Parse quality variants from HLS manifest
     */
    private parseVariants(
        content: string
    ): Array<{ resolution: number; url: string }> {
        const variants: Array<{ resolution: number; url: string }> = [];
        const regex =
            /#EXT-X-STREAM-INF:[^\n]*RESOLUTION=\d+x(\d+)[^\n]*\n([^\n]+)/g;
        let match;

        while ((match = regex.exec(content)) !== null) {
            variants.push({
                resolution: parseInt(match[1], 10),
                url: match[2]
            });
        }

        return variants;
    }

    /**
     * Return empty result with diagnostic
     */
    private emptyResult(
        message: string,
        media: ProviderMediaObject
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

    /**
     * Health check
     */
    async healthCheck(): Promise<boolean> {
        try {
            const response = await this.providerFetch(this.BASE_URL, {
                method: 'HEAD',
                headers: this.HEADERS
            });
            return response.status === 200;
        } catch {
            return false;
        }
    }
}
