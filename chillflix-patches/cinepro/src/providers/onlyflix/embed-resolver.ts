import type { ProviderMediaObject, Source, SourceType } from '@omss/framework';
import { BaseProvider } from '@omss/framework';

import { IcefyProvider } from '../icefy/icefy.js';
import { VaplayerProvider } from '../vaplayer/vaplayer.js';
import { VidApiProvider } from '../vidapi/vidapi.js';
import { VidRockProvider } from '../vidrock/vidrock.js';
import { VidSrcProvider } from '../vidsrc/vidsrc.js';
import type { OnlyFlixPlayer } from './onlyflix.types.js';
import { HEADERS } from './slug-resolver.js';

const M3U8_PATTERN =
    /https?:\/\/[^\s"'<>\\]+?\.m3u8(?:\?[^\s"'<>\\]*)?/gi;
const MP4_PATTERN =
    /https?:\/\/[^\s"'<>\\]+?\.mp4(?:\?[^\s"'<>\\]*)?/gi;
const IFRAME_SRC_PATTERN = /<iframe[^>]+src=["']([^"']+)["']/gi;
const DATA_SRC_PATTERN = /data-src=["']([^"']+)["']/gi;
const SRC_PATTERN = /(?:src|file|source|url)\s*[:=]\s*["']([^"']+)["']/gi;

/** Hosts we already have dedicated resolvers for — skip slow HTML scrape chains. */
const DELEGATE_FIRST_HOSTS =
    /vidapi|vaplayer|vidfast|vidking|cdnm\.|icefy|vidsrc|multiembed|moviesapi|embedmaster|videasy|nontongo/i;

type ResolvedStream = {
    url: string;
    referer: string;
};

function inferSourceType(url: string): SourceType {
    const lower = url.toLowerCase();
    if (lower.includes('.mp4')) return 'mp4';
    if (lower.includes('.mkv')) return 'mkv';
    if (lower.includes('.webm')) return 'webm';
    if (lower.includes('.mpd')) return 'dash';
    return 'hls';
}

function resolveUrl(baseUrl: string, candidate: string) {
    try {
        const decoded = candidate.replace(/&amp;/g, '&');
        if (decoded.startsWith('//')) {
            return new URL(`https:${decoded}`).href;
        }
        return new URL(decoded, baseUrl).href;
    } catch {
        return null;
    }
}

function extractStreams(html: string) {
    return [
        ...new Set([
            ...(html.match(M3U8_PATTERN) ?? []),
            ...(html.match(MP4_PATTERN) ?? [])
        ])
    ];
}

function extractNestedUrls(html: string, baseUrl: string) {
    const urls = new Set<string>();

    for (const pattern of [IFRAME_SRC_PATTERN, DATA_SRC_PATTERN, SRC_PATTERN]) {
        for (const match of html.matchAll(pattern)) {
            const value = match[1];
            if (
                !value ||
                value.startsWith('data:') ||
                value.startsWith('javascript:') ||
                value === '#'
            ) {
                continue;
            }

            const resolved = resolveUrl(baseUrl, value);
            if (resolved) urls.add(resolved);
        }
    }

    const swishId = html.match(/stream\.vidapi\.xyz\/swish\?id=([^"'&]+)/)?.[1];
    if (swishId) {
        urls.add(`https://lookmovie2.skin/e/${swishId}`);
    }

    const vidoraId = html.match(/vidora\.stream\/embed\/([^"'/?]+)/)?.[1];
    if (vidoraId) {
        urls.add(`https://vidora.stream/embed/${vidoraId}`);
    }

    return [...urls];
}

async function fetchHtml(url: string, referer: string, timeoutMs = 6000) {
    const controller = new AbortController();
    const timer = setTimeout(() => controller.abort(), timeoutMs);
    try {
        const response = await fetch(url, {
            headers: {
                ...HEADERS,
                Referer: referer
            },
            redirect: 'follow',
            signal: controller.signal
        });
        if (!response.ok) return null;
        return await response.text();
    } catch {
        return null;
    } finally {
        clearTimeout(timer);
    }
}

async function scrapeEmbedChain(
    startUrl: string,
    referer: string,
    maxDepth = 2
): Promise<ResolvedStream | null> {
    const visited = new Set<string>();
    const queue: Array<{ url: string; referer: string; depth: number }> = [
        { url: startUrl, referer, depth: 0 }
    ];

    while (queue.length) {
        const current = queue.shift();
        if (!current || visited.has(current.url) || current.depth > maxDepth) {
            continue;
        }
        visited.add(current.url);

        const html = await fetchHtml(current.url, current.referer);
        if (!html) continue;

        const streams = extractStreams(html);
        if (streams.length) {
            return {
                url: streams[0],
                referer: current.url
            };
        }

        if (current.url.includes('stream.vidapi.xyz/swish')) {
            const frameId =
                html.match(/id="framesrc"[^>]+src="([^"]+)"/)?.[1] ??
                html.match(/iframe[^>]+src="([^"]+)"/)?.[1] ??
                current.url.match(/[?&]id=([^&]+)/)?.[1];

            if (frameId && !frameId.startsWith('http')) {
                queue.push({
                    url: `https://lookmovie2.skin/e/${frameId}`,
                    referer: current.url,
                    depth: current.depth + 1
                });
            }
        }

        for (const nextUrl of extractNestedUrls(html, current.url)) {
            if (!visited.has(nextUrl)) {
                queue.push({
                    url: nextUrl,
                    referer: current.url,
                    depth: current.depth + 1
                });
            }
        }
    }

    return null;
}

async function withTimeout<T>(promise: Promise<T>, ms: number): Promise<T | null> {
    let timer: ReturnType<typeof setTimeout> | undefined;
    try {
        return await Promise.race([
            promise,
            new Promise<null>((resolve) => {
                timer = setTimeout(() => resolve(null), ms);
            })
        ]);
    } finally {
        if (timer) clearTimeout(timer);
    }
}

async function delegateToProviders(
    media: ProviderMediaObject,
    providers: BaseProvider[]
): Promise<Source | null> {
    for (const provider of providers) {
        const result = await withTimeout(
            media.type === 'movie'
                ? provider.getMovieSources(media)
                : provider.getTVSources(media),
            8_000
        );

        if (result?.sources?.[0]) {
            return result.sources[0];
        }
    }

    return null;
}

function buildOnlyFlixSource(
    base: Source,
    player: OnlyFlixPlayer,
    host: string
): Source {
    return {
        ...base,
        quality: `${player.quality || 'Auto'} · Server ${player.number} (${player.name || host})`,
        provider: {
            id: 'onlyflix',
            name: 'OnlyFlix'
        }
    };
}

function providersForHost(host: string) {
    const h = host.toLowerCase();

    // VAPlayer is much faster than scraping vidapi.xyz embed backends.
    if (h.includes('vaplayer') || h.includes('vidapi')) {
        return [new VaplayerProvider(), new VidApiProvider()];
    }

    // CDNM / VidFast / VidKing commonly resolve through Icefy backends.
    if (h.includes('vidfast') || h.includes('vidking') || h.includes('cdnm') || h.includes('icefy')) {
        return [new IcefyProvider()];
    }

    if (h.includes('moviesapi') || h.includes('multiembed') || h.includes('embedmaster')) {
        return [new IcefyProvider(), new VidSrcProvider()];
    }

    if (h.includes('vidsrc')) {
        return [new VidSrcProvider(), new VidRockProvider()];
    }

    return [new IcefyProvider(), new VaplayerProvider()];
}

/** Prefer fast/reliable OnlyFlix servers first. */
export function rankOnlyFlixPlayers(players: OnlyFlixPlayer[]): OnlyFlixPlayer[] {
    const score = (player: OnlyFlixPlayer) => {
        const key = `${player.name || ''} ${player.url}`.toLowerCase();
        if (key.includes('vaplayer') || key.includes('vidapi')) return 0;
        if (key.includes('cdnm') || key.includes('icefy')) return 1;
        if (key.includes('vidfast') || key.includes('vidking')) return 2;
        return 3 + (player.number || 0);
    };
    return [...players].sort((a, b) => score(a) - score(b));
}

export async function resolveOnlyFlixPlayerSource(
    player: OnlyFlixPlayer,
    media: ProviderMediaObject,
    pageReferer: string,
    createProxyUrl: (url: string, headers?: Record<string, string>) => string
): Promise<Source | null> {
    let host = 'unknown';
    try {
        host = new URL(player.url).hostname.replace(/^www\./, '');
    } catch {
        return null;
    }

    const preferDelegate = DELEGATE_FIRST_HOSTS.test(host) || DELEGATE_FIRST_HOSTS.test(player.url);

    if (preferDelegate) {
        const delegated = await delegateToProviders(media, providersForHost(host));
        if (delegated) {
            return buildOnlyFlixSource(delegated, player, host);
        }
    }

    // Fallback: shallow embed scrape (helps when VAPlayer/Icefy catalog misses a title).
    const scraped = await scrapeEmbedChain(player.url, pageReferer);
    if (scraped) {
        return {
            url: createProxyUrl(scraped.url, {
                ...HEADERS,
                Referer: scraped.referer,
                Origin: new URL(scraped.referer).origin
            }),
            type: inferSourceType(scraped.url),
            quality: `${player.quality || 'Auto'} · Server ${player.number} (${player.name || host})`,
            audioTracks: [{ language: 'eng', label: 'English' }],
            provider: {
                id: 'onlyflix',
                name: 'OnlyFlix'
            }
        };
    }

    if (!preferDelegate) {
        const delegated = await delegateToProviders(media, providersForHost(host));
        if (delegated) {
            return buildOnlyFlixSource(delegated, player, host);
        }
    }

    return null;
}
