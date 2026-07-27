import type { ProviderMediaObject } from '@omss/framework';

import {
    NOTORRENT_ADDON_BASE,
    NOTORRENT_HEADERS,
    NOTORRENT_STREAM_REFERER
} from './constants.js';

type StremioStream = {
    name?: string;
    title?: string;
    url?: string;
    externalUrl?: string;
    type?: string;
};

type StremioStreamResponse = {
    streams?: StremioStream[];
};

export type ResolvedNoTorrentStream = {
    title: string;
    playbackUrl: string;
    quality: string;
    type: 'hls' | 'mp4';
    referer: string;
};

function normalizeImdbId(value: string | undefined) {
    const trimmed = value?.trim() ?? '';
    if (!trimmed) return '';
    return trimmed.startsWith('tt') ? trimmed : `tt${trimmed}`;
}

function buildStreamId(media: ProviderMediaObject) {
    const imdbId = normalizeImdbId(media.imdbId);
    if (!imdbId) {
        throw new Error('Missing IMDb id for NoTorrent lookup');
    }

    if (media.type === 'tv') {
        const season = Number(media.s);
        const episode = Number(media.e);
        if (!Number.isFinite(season) || !Number.isFinite(episode)) {
            throw new Error('Missing season or episode for NoTorrent TV playback');
        }

        return {
            type: 'series' as const,
            id: `${imdbId}:${season}:${episode}`
        };
    }

    return {
        type: 'movie' as const,
        id: imdbId
    };
}

function isPaywallStream(stream: StremioStream) {
    const title = stream.title?.toLowerCase() ?? '';
    const external = stream.externalUrl?.toLowerCase() ?? '';

    if (external.includes('paypal.com')) return true;
    if (title.includes('upgrade to premium')) return true;
    if (title.includes('[premium]') && !stream.url) return true;

    return false;
}

function isBareFreeStream(title: string) {
    // Addon labels bait/decoy links as "[FREE]" and real embeds as "[FREE TRIAL]".
    // Bare "[FREE]" frequently resolves to unrelated clips (e.g. /img/.../nasty.m3u8).
    return title.includes('[free]') && !title.includes('free trial');
}

function streamScore(stream: StremioStream) {
    const title = stream.title?.toLowerCase() ?? '';
    const name = stream.name?.toLowerCase() ?? '';
    let score = 0;

    if (!stream.url) return -100;
    if (isPaywallStream(stream)) return -100;
    if (isBareFreeStream(title)) return -80;

    if (title.includes('free trial')) score += 45;
    if (title.includes('all players')) score += 30;
    if (title.includes('mpv') || title.includes('vlc')) score += 8;
    if (title.includes('premium')) score -= 30;

    // Quality lives on `name` in current addon payloads ("1080p - NoTorrent").
    if (name.includes('4k') || title.includes('4k')) score += 28;
    else if (name.includes('1080p') || title.includes('1080p')) score += 25;
    else if (name.includes('720p') || title.includes('720p')) score += 15;
    else if (name.includes('480p') || title.includes('480p')) score += 5;

    if (title.includes('original') || title.includes('multi')) score += 12;
    if (title.includes('english')) score += 10;
    if (
        title.includes('latino') ||
        title.includes('castellano') ||
        title.includes('portugu')
    ) {
        score -= 15;
    }

    return score;
}

function extractQualityLabel(...parts: Array<string | undefined>) {
    const haystack = parts.filter(Boolean).join(' ');
    const match = haystack.match(/\b(\d{3,4}p|4k)\b/i);
    return match?.[1]?.toUpperCase() ?? 'Auto';
}

const FETCH_TIMEOUT_MS = 12_000;
const MAX_CANDIDATES = 6;

async function fetchJson<T>(url: string): Promise<T | null> {
    try {
        const response = await fetch(url, {
            headers: NOTORRENT_HEADERS,
            redirect: 'follow',
            signal: AbortSignal.timeout(FETCH_TIMEOUT_MS)
        });

        if (!response.ok) {
            return null;
        }

        return (await response.json()) as T;
    } catch {
        return null;
    }
}

function detectStreamType(url: string, contentType = '') {
    if (url.includes('proxy-hls') || /\.m3u8(\?|$)/i.test(url)) {
        return 'hls' as const;
    }
    if (/\.(mkv|mp4|webm)(\?|$)/i.test(url)) return 'mp4' as const;
    if (contentType.includes('mpegurl') || contentType.includes('m3u8')) {
        return 'hls' as const;
    }
    if (
        contentType.includes('video/mp4') ||
        contentType.includes('video/webm') ||
        contentType.includes('video/x-matroska')
    ) {
        return 'mp4' as const;
    }
    return null;
}

function isDecoyPlaybackUrl(url: string) {
    const lower = url.toLowerCase();
    if (lower.includes('/nasty')) return true;
    if (lower.includes('streamraiwind.stream/img/')) return true;
    if (/\/img\/[^/]+\/[^/]+\.m3u8/i.test(lower)) return true;
    return false;
}

export function refererForPlayback(playbackUrl: string) {
    try {
        const { hostname } = new URL(playbackUrl);
        if (
            hostname.includes('hakunaymatata') ||
            hostname.includes('ciniverse')
        ) {
            return 'https://ciniverse.site/';
        }
        if (
            hostname.includes('ernax') ||
            hostname.includes('onlinevisibility') ||
            hostname.includes('quietmidnight') ||
            hostname.includes('strategicgrowth') ||
            hostname.includes('nextgencloudfabric')
        ) {
            return 'https://nextgencloudfabric.com/';
        }
        if (hostname.includes('hostingersite.com')) {
            return `https://${hostname}/`;
        }
        if (hostname.includes('voxzer.org')) {
            return NOTORRENT_ADDON_BASE + '/';
        }
    } catch {
        // fall through
    }

    return NOTORRENT_STREAM_REFERER;
}

export async function resolveNoTorrentRedirect(
    redirectUrl: string
): Promise<{ playbackUrl: string; type: 'hls' | 'mp4' } | null> {
    try {
        const response = await fetch(redirectUrl, {
            headers: NOTORRENT_HEADERS,
            redirect: 'follow',
            signal: AbortSignal.timeout(FETCH_TIMEOUT_MS)
        });

        const finalUrl = response.url?.trim();
        if (!finalUrl) {
            return null;
        }

        // Rate-limit / error JSON from the addon redirect worker.
        const contentType = response.headers.get('content-type') ?? '';
        if (contentType.includes('application/json')) {
            return null;
        }

        // Do not accept .mp4/.m3u8 URLs that actually returned 4xx/5xx HTML.
        if (!response.ok && response.status !== 206) {
            return null;
        }

        const detected = detectStreamType(finalUrl, contentType);
        if (detected) {
            if (isDecoyPlaybackUrl(finalUrl)) return null;
            return { playbackUrl: finalUrl, type: detected };
        }

        const body = await response.text();
        if (body.includes('Demasiadas solicitudes') || body.includes('retry_after')) {
            return null;
        }

        const match = body.match(/https?:\/\/[^\s"'<>]+\.(?:m3u8|mp4|mkv)[^\s"'<>]*/i);
        if (!match?.[0]) {
            // hostinger vid1.php style: relative /vid/...m3u8 in query already final
            if (/[?&]url=\/[^&\s]+\.m3u8/i.test(finalUrl)) {
                return { playbackUrl: finalUrl, type: 'hls' };
            }
            return null;
        }

        const playbackUrl = match[0];
        if (isDecoyPlaybackUrl(playbackUrl)) return null;

        const type = detectStreamType(playbackUrl, '');
        if (!type) {
            return null;
        }

        return { playbackUrl, type };
    } catch {
        return null;
    }
}

export async function fetchNoTorrentStreams(
    media: ProviderMediaObject
): Promise<ResolvedNoTorrentStream[]> {
    const { type, id } = buildStreamId(media);
    const payload = await fetchJson<StremioStreamResponse>(
        `${NOTORRENT_ADDON_BASE}/stream/${type}/${encodeURIComponent(id)}.json`
    );

    const candidates = (payload?.streams ?? [])
        .filter((stream) => Boolean(stream.url) && !isPaywallStream(stream))
        .sort((a, b) => streamScore(b) - streamScore(a))
        .slice(0, MAX_CANDIDATES);

    const resolved: ResolvedNoTorrentStream[] = [];

    for (const stream of candidates) {
        const redirectUrl = stream.url?.trim();
        if (!redirectUrl) continue;

        const playback = await resolveNoTorrentRedirect(redirectUrl);
        if (!playback) continue;
        if (isDecoyPlaybackUrl(playback.playbackUrl)) continue;

        resolved.push({
            title: stream.title?.trim() || 'NoTorrent',
            playbackUrl: playback.playbackUrl,
            quality: extractQualityLabel(stream.name, stream.title),
            type: playback.type,
            referer: refererForPlayback(playback.playbackUrl)
        });

        // One good stream is enough for the player; keep trying only on failures.
        break;
    }

    if (resolved.length === 0) {
        throw new Error('NoTorrent returned no playable streams');
    }

    return resolved;
}

export function notorrentProxyHeaders(playbackUrl?: string) {
    const referer = playbackUrl
        ? refererForPlayback(playbackUrl)
        : NOTORRENT_STREAM_REFERER;

    return {
        ...NOTORRENT_HEADERS,
        Referer: referer,
        Origin: new URL(referer).origin
    };
}
