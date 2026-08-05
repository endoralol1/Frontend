import type { ProviderMediaObject } from '@omss/framework';

import {
    getCachedVaplayerResolve,
    pickWorker,
    readWorkerRelayConfig,
    setCachedVaplayerResolve,
    vaplayerCacheKey
} from '../../config/worker-relay.js';
import { generateRandomUserAgent } from '../../utils/ua.js';
import type { DefaultSub, VidApiResponse } from './vidapi.types.js';

export const VAPLAYER_BASE = 'https://vaplayer.ru';
export const STREAMDATA_API = 'https://streamdata.vaplayer.ru/api.php';
export const EMBED_ORIGIN = 'https://nextgencloudfabric.com';

export type VaplayerResolveResult = {
    streams: string[];
    subtitles: DefaultSub[];
    fileName?: string;
    referer: string;
    failureReason?: string;
    via?: 'direct' | 'worker';
};

type StreamdataParseOk = {
    ok: true;
    json: VidApiResponse;
};

type StreamdataParseFail = {
    ok: false;
    failureReason: string;
};

function buildEmbedReferer(media: ProviderMediaObject, preferImdb: boolean) {
    const useImdb = preferImdb && media.imdbId?.startsWith('tt');
    const mediaId = useImdb ? media.imdbId! : media.tmdbId;

    if (media.type === 'tv' && media.s != null && media.e != null) {
        return `${EMBED_ORIGIN}/embed/tv/${mediaId}/${media.s}/${media.e}`;
    }

    return `${EMBED_ORIGIN}/embed/movie/${mediaId}`;
}

function buildStreamdataHeaders(referer: string) {
    return {
        'User-Agent': generateRandomUserAgent(),
        Accept: 'application/json, text/plain, */*',
        Referer: referer,
        Origin: EMBED_ORIGIN,
        'X-Requested-With': 'XMLHttpRequest'
    };
}

function buildApiUrl(media: ProviderMediaObject, preferImdb: boolean) {
    const url = new URL(STREAMDATA_API);
    const type = media.type === 'movie' ? 'movie' : 'tv';
    url.searchParams.set('type', type);

    if (preferImdb && media.imdbId?.startsWith('tt')) {
        url.searchParams.set('imdb', media.imdbId);
    } else {
        url.searchParams.set('tmdb', media.tmdbId);
    }

    if (media.type === 'tv' && media.s != null && media.e != null) {
        url.searchParams.set('season', String(media.s));
        url.searchParams.set('episode', String(media.e));
    }

    return url;
}

async function parseStreamdataResponse(
    response: Response
): Promise<StreamdataParseOk | StreamdataParseFail> {
    const raw = await response.text();
    const trimmed = raw.trim();
    if (!trimmed) {
        return { ok: false, failureReason: 'streamdata returned an empty body' };
    }

    let json: unknown;
    try {
        json = JSON.parse(trimmed);
    } catch {
        const contentType = response.headers.get('content-type')?.toLowerCase() ?? '';
        return {
            ok: false,
            failureReason: contentType.includes('json')
                ? 'streamdata returned malformed JSON'
                : `streamdata returned non-JSON body (${contentType || 'unknown type'})`
        };
    }

    if (!json || typeof json !== 'object') {
        return { ok: false, failureReason: 'streamdata returned invalid JSON payload' };
    }

    const payload = json as Partial<VidApiResponse> & { status_code?: string | number };
    const status = String(payload.status_code ?? '');

    // Upstream often returns HTTP 200 with {"status_code":404} when the title/episode
    // is missing from their catalog — that is valid JSON, not an API outage.
    if (status === '404') {
        return {
            ok: false,
            failureReason: 'no streams for this title/episode (streamdata status 404)'
        };
    }

    if (status !== '200') {
        return {
            ok: false,
            failureReason: status
                ? `streamdata status ${status}`
                : 'streamdata response missing status_code'
        };
    }

    const streamUrls = payload.data?.stream_urls;
    if (!Array.isArray(streamUrls) || streamUrls.length === 0) {
        return {
            ok: false,
            failureReason: 'streamdata returned no usable stream URLs'
        };
    }

    return { ok: true, json: payload as VidApiResponse };
}

async function fetchStreamdataOnce(
    media: ProviderMediaObject,
    preferImdb: boolean
): Promise<VaplayerResolveResult> {
    const referer = buildEmbedReferer(media, preferImdb);
    const headers = buildStreamdataHeaders(referer);
    const apiUrl = buildApiUrl(media, preferImdb);

    try {
        const response = await fetch(apiUrl.toString(), { headers });

        if (!response.ok) {
            return {
                streams: [],
                subtitles: [],
                referer,
                via: 'direct',
                failureReason: `streamdata HTTP ${response.status}`
            };
        }

        const parsed = await parseStreamdataResponse(response);
        if (!parsed.ok) {
            return {
                streams: [],
                subtitles: [],
                referer,
                via: 'direct',
                failureReason: parsed.failureReason
            };
        }

        const streams = (parsed.json.data.stream_urls ?? []).filter(
            (streamUrl) => !streamUrl.includes('strategicgrowthpartners')
        );

        if (!streams.length) {
            return {
                streams: [],
                subtitles: [],
                referer,
                via: 'direct',
                failureReason: 'streamdata returned no usable stream URLs'
            };
        }

        return {
            streams,
            subtitles: parsed.json.default_subs ?? [],
            fileName: parsed.json.data.file_name,
            referer,
            via: 'direct'
        };
    } catch (error) {
        return {
            streams: [],
            subtitles: [],
            referer,
            via: 'direct',
            failureReason:
                error instanceof Error ? error.message : 'streamdata request failed'
        };
    }
}

/**
 * Prefer Cloudflare Worker egress when configured — helps if vaplayer.ru
 * rate-limits the VPS datacenter IP. Falls back to direct on any failure.
 * Supports a pool of Workers (round-robin) from admin config.
 */
async function fetchViaWorker(
    media: ProviderMediaObject
): Promise<VaplayerResolveResult | null> {
    const relay = readWorkerRelayConfig();
    if (!relay.enabled) return null;

    const worker = pickWorker(relay);
    if (!worker) return null;

    const type = media.type === 'tv' ? 'tv' : 'movie';
    const url = new URL(`${worker.url}/vaplayer`);
    url.searchParams.set('type', type);
    url.searchParams.set('tmdb', String(media.tmdbId ?? ''));
    url.searchParams.set('key', worker.secret);
    url.searchParams.set('cacheTtl', String(relay.cacheTtlSeconds));
    if (media.imdbId?.startsWith('tt')) {
        url.searchParams.set('imdb', media.imdbId);
    }
    if (type === 'tv') {
        url.searchParams.set('season', String(media.s ?? 1));
        url.searchParams.set('episode', String(media.e ?? 1));
    }

    try {
        const response = await fetch(url.toString(), {
            headers: {
                Accept: 'application/json',
                'x-yoru-key': worker.secret
            }
        });
        const raw = await response.text();
        let json: any;
        try {
            json = JSON.parse(raw);
        } catch {
            return {
                streams: [],
                subtitles: [],
                referer: EMBED_ORIGIN,
                via: 'worker',
                failureReason: `worker returned non-JSON (HTTP ${response.status})`
            };
        }

        if (!response.ok || !json?.ok) {
            return {
                streams: [],
                subtitles: [],
                referer: String(json?.referer || EMBED_ORIGIN),
                via: 'worker',
                failureReason: String(json?.error || `worker HTTP ${response.status}`)
            };
        }

        const streams = (Array.isArray(json.sources) ? json.sources : [])
            .map((s: any) => (typeof s?.url === 'string' ? s.url : ''))
            .filter((u: string) => u && !u.includes('strategicgrowthpartners'));

        if (!streams.length) {
            return {
                streams: [],
                subtitles: [],
                referer: String(json.referer || EMBED_ORIGIN),
                via: 'worker',
                failureReason: 'worker returned no usable stream URLs'
            };
        }

        return {
            streams,
            subtitles: Array.isArray(json.subtitles) ? json.subtitles : [],
            fileName: typeof json.fileName === 'string' ? json.fileName : undefined,
            referer: String(json.referer || EMBED_ORIGIN),
            via: 'worker'
        };
    } catch (error) {
        return {
            streams: [],
            subtitles: [],
            referer: EMBED_ORIGIN,
            via: 'worker',
            failureReason:
                error instanceof Error ? error.message : 'worker relay request failed'
        };
    }
}

export async function resolveVaplayerStreams(
    media: ProviderMediaObject
): Promise<VaplayerResolveResult | null> {
    const relay = readWorkerRelayConfig();
    const cacheKey = vaplayerCacheKey({
        type: media.type === 'tv' ? 'tv' : 'movie',
        tmdbId: String(media.tmdbId ?? ''),
        imdbId: media.imdbId?.startsWith('tt') ? media.imdbId : '',
        season: media.s ?? 1,
        episode: media.e ?? 1
    });

    const cached = getCachedVaplayerResolve<VaplayerResolveResult>(cacheKey);
    if (cached?.streams?.length) {
        return cached;
    }

    const preferWorker = relay.enabled && relay.preferWorker;

    if (preferWorker) {
        const viaWorker = await fetchViaWorker(media);
        if (viaWorker?.streams.length) {
            setCachedVaplayerResolve(cacheKey, viaWorker, relay.cacheTtlSeconds);
            return viaWorker;
        }
    }

    const hasImdb = Boolean(media.imdbId?.startsWith('tt'));
    // Prefer IMDb when present (matches embed), then fall back to TMDB once.
    const attempts = hasImdb ? [true, false] : [false];

    let last: VaplayerResolveResult | null = null;
    for (const preferImdb of attempts) {
        const result = await fetchStreamdataOnce(media, preferImdb);
        last = result;
        if (result.streams.length > 0) {
            setCachedVaplayerResolve(cacheKey, result, relay.cacheTtlSeconds);
            return result;
        }
    }

    // If Worker was skipped or failed and direct failed, try Worker as fallback.
    if (!preferWorker && relay.enabled) {
        const viaWorker = await fetchViaWorker(media);
        if (viaWorker?.streams.length) {
            setCachedVaplayerResolve(cacheKey, viaWorker, relay.cacheTtlSeconds);
            return viaWorker;
        }
        if (viaWorker && !last?.streams.length) return viaWorker;
    }

    return last;
}

/** @deprecated Use EMBED_ORIGIN — old brightpathsignals.com referer no longer works. */
export const IFRAME_URL = EMBED_ORIGIN;
