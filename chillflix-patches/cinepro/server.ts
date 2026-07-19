import { OMSSServer } from '@omss/framework';
import type { ProviderMediaObject, ProviderResult } from '@omss/framework';
import 'dotenv/config';

// Skip per-source HEAD validation (saves ~3s per stream on slow CDNs). Safe when providers are trusted.
if (process.env.SKIP_SOURCE_VALIDATION === 'true') {
    process.env.INTERNAL_DEBUG = 'true';
}
import { fileURLToPath } from 'node:url';
import path from 'node:path';
import { knownThirdPartyProxies } from './thirdPartyProxies.js';
import { streamPatterns } from './streamPatterns.js';
import { getManifest } from './providers/cinesu/manifest-store.js';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);

async function main() {
    const server = new OMSSServer({
        name: 'CinePro',
        version: '1.0.0',

        // Network
        host: process.env.HOST ?? 'localhost',
        port: Number(process.env.PORT ?? 3000),
        publicUrl: process.env.PUBLIC_URL,

        // Cache (memory for dev, Redis for prod)
        cache: {
            type: (process.env.CACHE_TYPE as 'memory' | 'redis') ?? 'memory',
            ttl: {
                sources: 60 * 60,
                subtitles: 60 * 60 * 24
            },
            redis: {
                host: process.env.REDIS_HOST ?? 'localhost',
                port: Number(process.env.REDIS_PORT ?? 6379),
                password: process.env.REDIS_PASSWORD
            }
        },

        // TMDB
        tmdb: {
            apiKey: process.env.TMDB_API_KEY!,
            cacheTTL: 24 * 60 * 60 // 24h
        },

        // Third Party Proxy removal
        proxyConfig: {
            knownThirdPartyProxies: knownThirdPartyProxies,
            streamPatterns
        },

        cors: {
            origin: process.env.CORS_ORIGIN ?? '*',
            methods: ['GET', 'OPTIONS'],
            allowedHeaders: ['Content-Type', 'Authorization'],
            exposedHeaders: ['Content-Range', 'Accept-Ranges', 'ETag'],
            preflightContinue: false,
            optionsSuccessStatus: 204
        },

        stremio: {
            // exposes a stremio addon on /stremio/manifest.json
            enableNativeAddon: process.env.STREMIO_ADDON === 'true',
            // you can your own custom stremio addons as sources into cinepro.
            stremioAddons: []
            /*
            stremioAddons: [
                {
                    id: 'some-unique-id',
                    url: 'https://example.com/manifest.json',
                    enabled: true
                }
            ]
            */
        },

        // MCP for AI agents
        mcp: {
            enabled: process.env.MCP_ENABLED === 'true'
        }
    });

    // Register providers
    const registry = server.getRegistry();
    await registry.discoverProviders(path.join(__dirname, './providers/'));

    const allDiscoveredProviders = [...registry.getProviders()];

    const providerAllowlist = process.env.CINEPRO_PROVIDER_ALLOWLIST
        ?.split(',')
        .map((entry) => entry.trim().toLowerCase())
        .filter(Boolean);

    if (providerAllowlist?.length) {
        for (const provider of registry.getProviders()) {
            if (!providerAllowlist.includes(provider.id.toLowerCase())) {
                registry.unregister(provider.id);
            }
        }

        console.log(
            `[CinePro] Provider allowlist active (${providerAllowlist.join(', ')})`
        );
    }

    const providerTimeoutMs = Number(process.env.PROVIDER_TIMEOUT_MS ?? 3000);
    if (providerTimeoutMs > 0) {
        for (const provider of registry.getProviders()) {
            const label = provider.name ?? provider.id ?? 'provider';
            const wrap = <T extends (...args: never[]) => Promise<unknown>>(fn: T) => {
                const bound = fn.bind(provider) as T;
                return ((...args: Parameters<T>) =>
                    new Promise<Awaited<ReturnType<T>>>((resolve) => {
                        const timer = setTimeout(() => {
                            console.warn(
                                `[CinePro] Provider '${label}' timed out after ${providerTimeoutMs}ms`
                            );
                            resolve({
                                sources: [],
                                subtitles: [],
                                diagnostics: [
                                    {
                                        code: 'PROVIDER_ERROR',
                                        message: `${label}: timed out after ${providerTimeoutMs}ms`,
                                        field: '',
                                        severity: 'warning'
                                    }
                                ]
                            } as Awaited<ReturnType<T>>);
                        }, providerTimeoutMs);

                        bound(...args)
                            .then((result) => {
                                clearTimeout(timer);
                                resolve(result as Awaited<ReturnType<T>>);
                            })
                            .catch((error) => {
                                clearTimeout(timer);
                                console.error(`[CinePro] Provider '${label}' failed:`, error);
                                resolve({
                                    sources: [],
                                    subtitles: [],
                                    diagnostics: [
                                        {
                                            code: 'PROVIDER_ERROR',
                                            message: `${label}: ${error instanceof Error ? error.message : 'failed'}`,
                                            field: '',
                                            severity: 'error'
                                        }
                                    ]
                                } as Awaited<ReturnType<T>>);
                            });
                    })) as T;
            };

            provider.getMovieSources = wrap(provider.getMovieSources);
            provider.getTVSources = wrap(provider.getTVSources);
        }
    }

    server.getInstance().get('/v1/cinesu/manifest/:id', async (request, reply) => {
        const id = String((request.params as { id?: string }).id ?? '').replace(/\.m3u8$/i, '');
        const manifest = getManifest(id);

        if (!manifest) {
            return reply.code(404).send('manifest not found\n');
        }

        return reply
            .header('Content-Type', 'application/vnd.apple.mpegurl')
            .header('Cache-Control', 'no-store')
            .send(manifest);
    });

    type ServerInternals = {
        tmdbService: {
            getMediaObject: (
                type: 'movie' | 'tv',
                tmdbId: string,
                season?: number,
                episode?: number
            ) => Promise<ProviderMediaObject>;
            getImdbId: (tmdbId: string, type: 'movie' | 'tv') => Promise<string | null>;
        };
        sourceService: {
            buildResponse: (results: ProviderResult[]) => {
                responseId: string;
                expiresAt: string;
                sources: unknown[];
                subtitles: unknown[];
                diagnostics: unknown[];
            };
            tmdbValidator: {
                validateMovie: (tmdbId: string) => Promise<void>;
                validateTVEpisode: (tmdbId: string, season: number, episode: number) => Promise<void>;
            };
        };
        cache: {
            get: (key: string) => Promise<unknown>;
            set: (key: string, value: unknown, ttl: number) => Promise<void>;
            delete: (key: string) => Promise<void>;
        };
    };

    const { tmdbService, sourceService, cache } = server as unknown as ServerInternals;
    const singleProviderCacheTtl = 3600;

    server.getInstance().get(
        '/v1/movies/:tmdbId/provider/:providerId',
        async (request, reply) => {
            const { tmdbId, providerId: providerIdParam } = request.params as {
                tmdbId: string;
                providerId: string;
            };
            const fresh = String((request.query as { fresh?: string }).fresh ?? '') === 'true';
            const probe = String((request.query as { probe?: string }).probe ?? '') === 'true';

            const providerPool = probe ? allDiscoveredProviders : registry.getProviders();
            const provider = providerPool.find(
                (entry) =>
                    entry.id.toLowerCase() === providerIdParam.toLowerCase() &&
                    (probe || entry.enabled) &&
                    entry.capabilities.supportedContentTypes.includes('movies')
            );

            if (!provider) {
                return reply.code(404).send({
                    error: {
                        code: 'PROVIDER_NOT_FOUND',
                        message: probe
                            ? `Provider '${providerIdParam}' is not installed or does not support movies`
                            : `Provider '${providerIdParam}' not found or not enabled for movies`,
                    },
                });
            }

            await sourceService.tmdbValidator.validateMovie(tmdbId);

            const cacheKey = `movie:${tmdbId}:provider:${provider.id}`;

            if (!fresh) {
                const cached = await cache.get(cacheKey);
                if (cached) {
                    console.log(`[CinePro] Cache HIT for ${cacheKey}`);
                    return reply.code(200).send(cached);
                }
            } else {
                await cache.delete(cacheKey);
            }

            console.log(`[CinePro] Cache MISS for ${cacheKey}`);

            const media = await tmdbService.getMediaObject('movie', tmdbId);
            media.imdbId = (await tmdbService.getImdbId(tmdbId, 'movie')) ?? '';

            const label = provider.name ?? provider.id ?? 'provider';
            const startedAt = Date.now();
            let result: ProviderResult;

            try {
                result = await provider.getMovieSources(media);
            } catch (error) {
                console.error(`[CinePro] Provider '${label}' failed:`, error);
                result = {
                    sources: [],
                    subtitles: [],
                    diagnostics: [
                        {
                            code: 'PROVIDER_ERROR',
                            message: `${label}: ${error instanceof Error ? error.message : 'failed'}`,
                            field: '',
                            severity: 'error',
                        },
                    ],
                };
            }

            const duration = Date.now() - startedAt;
            console.log(
                `[CinePro] Provider '${label}' returned ${result.sources.length} source(s) in ${duration}ms`
            );

            const response = sourceService.buildResponse([result]);
            // Do not pin empty/error responses for an hour — upstream (e.g. VAPlayer)
            // often 404s per episode; a long negative cache makes "worked then gone" worse.
            if (result.sources.length > 0) {
                await cache.set(cacheKey, response, singleProviderCacheTtl);
            } else {
                await cache.set(cacheKey, response, 60);
            }

            return reply.code(200).send(response);
        }
    );


    server.getInstance().get(
        '/v1/tv/:tmdbId/seasons/:season/episodes/:episode/provider/:providerId',
        async (request, reply) => {
            const {
                tmdbId,
                season: seasonParam,
                episode: episodeParam,
                providerId: providerIdParam
            } = request.params as {
                tmdbId: string;
                season: string;
                episode: string;
                providerId: string;
            };
            const season = Number(seasonParam);
            const episode = Number(episodeParam);
            const fresh = String((request.query as { fresh?: string }).fresh ?? '') === 'true';
            const probe = String((request.query as { probe?: string }).probe ?? '') === 'true';

            if (!Number.isFinite(season) || !Number.isFinite(episode)) {
                return reply.code(400).send({
                    error: {
                        code: 'INVALID_EPISODE',
                        message: 'Season and episode must be numbers',
                    },
                });
            }

            const providerPool = probe ? allDiscoveredProviders : registry.getProviders();
            const provider = providerPool.find(
                (entry) =>
                    entry.id.toLowerCase() === providerIdParam.toLowerCase() &&
                    (probe || entry.enabled) &&
                    entry.capabilities.supportedContentTypes.includes('tv')
            );

            if (!provider) {
                return reply.code(404).send({
                    error: {
                        code: 'PROVIDER_NOT_FOUND',
                        message: probe
                            ? `Provider '${providerIdParam}' is not installed or does not support tv`
                            : `Provider '${providerIdParam}' not found or not enabled for tv`,
                    },
                });
            }

            await sourceService.tmdbValidator.validateTVEpisode(tmdbId, season, episode);

            const cacheKey = `tv:${tmdbId}:s${season}:e${episode}:provider:${provider.id}`;

            if (!fresh) {
                const cached = await cache.get(cacheKey);
                if (cached) {
                    console.log(`[CinePro] Cache HIT for ${cacheKey}`);
                    return reply.code(200).send(cached);
                }
            } else {
                await cache.delete(cacheKey);
            }

            console.log(`[CinePro] Cache MISS for ${cacheKey}`);

            const media = await tmdbService.getMediaObject('tv', tmdbId, season, episode);
            media.imdbId = (await tmdbService.getImdbId(tmdbId, 'tv')) ?? '';

            // Framework sets title to the episode name ("Episode 1"). Providers that
            // match by series title need the show name — attach it when available.
            try {
                const show = await (tmdbService as unknown as {
                    validateTV?: (id: string) => Promise<{ title?: string }>
                }).validateTV?.(tmdbId);
                if (show?.title) {
                    media.title = show.title;
                }
            } catch {
                // keep episode title
            }

            const label = provider.name ?? provider.id ?? 'provider';
            const startedAt = Date.now();
            let result: ProviderResult;

            try {
                result = await provider.getTVSources(media);
            } catch (error) {
                console.error(`[CinePro] Provider '${label}' failed:`, error);
                result = {
                    sources: [],
                    subtitles: [],
                    diagnostics: [
                        {
                            code: 'PROVIDER_ERROR',
                            message: `${label}: ${error instanceof Error ? error.message : 'failed'}`,
                            field: '',
                            severity: 'error',
                        },
                    ],
                };
            }

            const duration = Date.now() - startedAt;
            console.log(
                `[CinePro] Provider '${label}' returned ${result.sources.length} source(s) in ${duration}ms`
            );

            const response = sourceService.buildResponse([result]);
            // Do not pin empty/error responses for an hour — upstream (e.g. VAPlayer)
            // often 404s per episode; a long negative cache makes "worked then gone" worse.
            if (result.sources.length > 0) {
                await cache.set(cacheKey, response, singleProviderCacheTtl);
            } else {
                await cache.set(cacheKey, response, 60);
            }

            return reply.code(200).send(response);
        }
    );

    await server.start();


    const publicUrl =
        process.env.PUBLIC_URL ??
        `http://${process.env.HOST ?? 'localhost'}:${process.env.PORT ?? 3000}`;

    const uiUrl = `https://ui.cinepro.cc/?omssurl=${encodeURIComponent(publicUrl)}`;

    const title = '🚀 CinePro/ui is in public testing';
    const contrib =
        '🤝 We are looking for contributors to improve and develop!';
    const repo = 'Contribute: https://github.com/cinepro-org/ui';
    const tryIt = `🌐 Try it out: ${uiUrl} !`;
    const note =
        'You will need to give the website "access to local applications" that it works.';

    const lines = [title, '', repo, '', contrib, '', tryIt, '', note];

    // compute box width based on longest line
    const width = Math.max(...lines.map((l) => l.length)) + 2;

    const borderTop = '╭' + '─'.repeat(width) + '╮';
    const borderBottom = '╰' + '─'.repeat(width) + '╯';

    const pad = (line: string) => '│ ' + line.padEnd(width - 2, ' ') + ' │';

    console.log(`
================== CINEPRO BETA ANNOUNCEMENT ==================

${borderTop}
${lines.map(pad).join('\n')}
${borderBottom}
`);
}

main().catch(() => {
    process.exit(1);
});
