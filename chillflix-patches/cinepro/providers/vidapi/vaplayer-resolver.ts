import type { ProviderMediaObject } from '@omss/framework';

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
                failureReason: `streamdata HTTP ${response.status}`
            };
        }

        const parsed = await parseStreamdataResponse(response);
        if (!parsed.ok) {
            return {
                streams: [],
                subtitles: [],
                referer,
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
                failureReason: 'streamdata returned no usable stream URLs'
            };
        }

        return {
            streams,
            subtitles: parsed.json.default_subs ?? [],
            fileName: parsed.json.data.file_name,
            referer
        };
    } catch (error) {
        return {
            streams: [],
            subtitles: [],
            referer,
            failureReason:
                error instanceof Error ? error.message : 'streamdata request failed'
        };
    }
}

export async function resolveVaplayerStreams(
    media: ProviderMediaObject
): Promise<VaplayerResolveResult | null> {
    const hasImdb = Boolean(media.imdbId?.startsWith('tt'));
    // Prefer IMDb when present (matches embed), then fall back to TMDB once.
    const attempts = hasImdb ? [true, false] : [false];

    let last: VaplayerResolveResult | null = null;
    for (const preferImdb of attempts) {
        const result = await fetchStreamdataOnce(media, preferImdb);
        last = result;
        if (result.streams.length > 0) {
            return result;
        }
    }

    return last;
}

/** @deprecated Use EMBED_ORIGIN — old brightpathsignals.com referer no longer works. */
export const IFRAME_URL = EMBED_ORIGIN;
