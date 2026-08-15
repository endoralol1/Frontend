import { BaseProvider } from '@omss/framework';
const SITE = 'https://novahd.cc';
const API = `${SITE}/api/sources`;
const RESULT_TTL_MS = 12 * 60 * 1000;
/** Race at most this many CDN candidates (keeps latency + upstream load bounded). */
const MAX_RACE = 8;
const PROBE_TIMEOUT_MS = 7_000;
const PROBE_BYTES = 1_024;
const UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36';
const resultCache = new Map();
function inferType(raw, url) {
    const blob = `${raw || ''} ${url}`.toLowerCase();
    if (blob.includes('hls') || /\.m3u8(\?|$)/i.test(url) || /workers\.dev/i.test(url)) {
        return 'hls';
    }
    if (blob.includes('mkv') || /\.mkv(\?|$)/i.test(url))
        return 'mkv';
    if (blob.includes('webm') || /\.webm(\?|$)/i.test(url))
        return 'webm';
    if (blob.includes('mp4') || /\.mp4(\?|$)/i.test(url))
        return 'mp4';
    return 'hls';
}
function qualityRank(q) {
    const lower = q.toLowerCase();
    if (/\b2160\b|4k|uhd/.test(lower))
        return 2160;
    if (/\b1080\b/.test(lower))
        return 1080;
    if (/\b720\b/.test(lower))
        return 720;
    if (/\b480\b/.test(lower))
        return 480;
    if (/\b360\b/.test(lower))
        return 360;
    // Adaptive masters usually advertise Auto — treat as high priority for racing.
    if (lower === 'auto' || lower === '')
        return 1080;
    const n = parseInt(q, 10);
    return Number.isFinite(n) ? n : 0;
}
/** Real quality label only — never Nova server/edge names. */
function cleanQuality(item) {
    const q = String(item.quality || '').trim();
    if (!q)
        return 'Auto';
    if (/^(auto|unknown|default)$/i.test(q))
        return 'Auto';
    if (/\b2160\b|4k|uhd/i.test(q))
        return '2160p';
    if (/\b1080\b/i.test(q))
        return '1080p';
    if (/\b720\b/i.test(q))
        return '720p';
    if (/\b480\b/i.test(q))
        return '480p';
    if (/\b360\b/i.test(q))
        return '360p';
    // Reject server-ish labels that slipped into quality.
    if (/vega|orion|raven|falcon|marlin|helios|ember|alpha|beta|main/i.test(q)) {
        return 'Auto';
    }
    return q;
}
function isHevc(item) {
    return /hevc|h\.?265/i.test(String(item.codecs || ''));
}
function looksPlayable(bytes) {
    if (!bytes.length)
        return false;
    const head = Buffer.from(bytes.subarray(0, Math.min(bytes.length, 256))).toString('utf8');
    if (/#EXTM3U/i.test(head))
        return true;
    if (bytes.length >= 8) {
        // ISO BMFF / MP4
        const box = Buffer.from(bytes.subarray(4, 8)).toString('ascii');
        if (box === 'ftyp' || box === 'moov' || box === 'mdat')
            return true;
    }
    return bytes.length >= 200;
}
export class NovahdProvider extends BaseProvider {
    id = 'novahd';
    name = 'NovaHD';
    enabled = true;
    BASE_URL = SITE;
    HEADERS = {
        'User-Agent': UA,
        Accept: 'application/json, text/plain, */*',
        'Accept-Language': 'en-US,en;q=0.9',
        Referer: `${SITE}/`,
        Origin: SITE,
        'x-nova-visitor': '1'
    };
    capabilities = {
        supportedContentTypes: ['movies', 'tv']
    };
    async getMovieSources(media) {
        return this.resolve(media);
    }
    async getTVSources(media) {
        return this.resolve(media);
    }
    cacheKey(media) {
        return media.type === 'tv'
            ? `tv:${media.tmdbId}:s${media.s ?? 1}:e${media.e ?? 1}`
            : `movie:${media.tmdbId}`;
    }
    emptyResult(message) {
        return {
            sources: [],
            subtitles: [],
            diagnostics: [
                {
                    code: 'PROVIDER_ERROR',
                    message: `${this.name}: ${message}`,
                    field: '',
                    severity: 'warning'
                }
            ]
        };
    }
    buildUrl(media) {
        const tmdbId = String(media.tmdbId || '').trim();
        if (!tmdbId)
            return null;
        if (media.type === 'tv') {
            const season = Number(media.s ?? 1) || 1;
            const episode = Number(media.e ?? 1) || 1;
            const qs = new URLSearchParams({
                type: 'show',
                tmdbId,
                season: String(season),
                episode: String(episode)
            });
            return `${API}?${qs.toString()}`;
        }
        const qs = new URLSearchParams({ type: 'movie', tmdbId });
        return `${API}?${qs.toString()}`;
    }
    async fetchSources(url) {
        // Must hit novahd.cc from the VPS IP — CDN JWTs are IP-bound to the same egress
        // used later by media-proxy. Retry briefly on CF 429/1015 rate limits.
        let lastError = 'fetch failed';
        for (let attempt = 0; attempt < 3; attempt++) {
            if (attempt > 0) {
                await new Promise((r) => setTimeout(r, 1500 * attempt));
            }
            const controller = new AbortController();
            const timer = setTimeout(() => controller.abort(), 20_000);
            try {
                const response = await fetch(url, {
                    headers: this.HEADERS,
                    redirect: 'follow',
                    signal: controller.signal
                });
                const text = await response.text();
                if (response.status === 429 || response.status === 403) {
                    try {
                        const j = JSON.parse(text);
                        const detail = String(j.message || j.error || '').trim();
                        lastError = detail
                            ? `HTTP ${response.status}: ${detail}`
                            : `HTTP ${response.status} (rate-limited)`;
                    }
                    catch {
                        lastError = `HTTP ${response.status} (rate-limited)`;
                    }
                    continue;
                }
                if (!response.ok) {
                    return { items: [], error: `HTTP ${response.status}` };
                }
                let json;
                try {
                    json = JSON.parse(text);
                }
                catch {
                    return { items: [], error: 'Non-JSON response' };
                }
                const err = String(json.error || json.message || '').trim();
                const items = Array.isArray(json.sources) ? json.sources : [];
                return { items, error: err || undefined };
            }
            catch (error) {
                lastError = error instanceof Error ? error.message : 'fetch failed';
            }
            finally {
                clearTimeout(timer);
            }
        }
        return { items: [], error: lastError };
    }
    /** Short GET of playlist/MP4 head — measures TTFB + confirms stream is alive. */
    async probeCandidate(item) {
        const url = String(item.url || '');
        if (!/^https?:\/\//i.test(url))
            return null;
        const started = Date.now();
        try {
            const response = await fetch(url, {
                headers: {
                    'User-Agent': UA,
                    Accept: '*/*',
                    Referer: `${SITE}/`,
                    Origin: SITE,
                    Range: `bytes=0-${PROBE_BYTES - 1}`
                },
                redirect: 'follow',
                signal: AbortSignal.timeout(PROBE_TIMEOUT_MS)
            });
            if (!response.ok && response.status !== 206)
                return null;
            const buf = new Uint8Array(await response.arrayBuffer());
            if (!looksPlayable(buf))
                return null;
            return {
                item,
                url,
                ms: Date.now() - started,
                hevc: isHevc(item),
                qualityRank: qualityRank(String(item.quality || ''))
            };
        }
        catch {
            return null;
        }
    }
    /**
     * Race CDN edges in parallel and keep the fastest healthy stream.
     * Quality UI should come from HLS levels — not from Nova server names.
     */
    async pickFastest(items) {
        const seen = new Set();
        const candidates = [];
        const pre = items
            .filter((item) => typeof item?.url === 'string' && /^https?:\/\//i.test(String(item.url)))
            .map((item) => ({
            item,
            hevc: isHevc(item),
            rank: qualityRank(String(item.quality || ''))
        }))
            .sort((a, b) => {
            if (a.hevc !== b.hevc)
                return a.hevc ? 1 : -1;
            return b.rank - a.rank;
        });
        for (const { item } of pre) {
            const key = String(item.hostKey || String(item.url).split('#')[0]);
            if (seen.has(key))
                continue;
            seen.add(key);
            candidates.push(item);
            if (candidates.length >= MAX_RACE)
                break;
        }
        const probed = await Promise.all(candidates.map((c) => this.probeCandidate(c)));
        const alive = probed.filter((p) => p !== null);
        alive.sort((a, b) => {
            if (a.hevc !== b.hevc)
                return a.hevc ? 1 : -1;
            if (a.ms !== b.ms)
                return a.ms - b.ms;
            return b.qualityRank - a.qualityRank;
        });
        return { winner: alive[0] || null, raced: candidates.length, alive: alive.length };
    }
    async resolve(media) {
        const key = this.cacheKey(media);
        const hit = resultCache.get(key);
        if (hit && hit.expiresAt > Date.now())
            return hit.result;
        const apiUrl = this.buildUrl(media);
        if (!apiUrl)
            return this.emptyResult('Missing TMDB id');
        try {
            const { items, error } = await this.fetchSources(apiUrl);
            if (!items.length) {
                return this.emptyResult(error || `No sources for TMDB ${media.tmdbId}`);
            }
            const { winner, raced, alive } = await this.pickFastest(items);
            if (!winner) {
                return this.emptyResult(error || `No healthy NovaHD edges (${raced} raced, ${alive} alive)`);
            }
            const quality = cleanQuality(winner.item);
            const lang = String(winner.item.language || '').trim();
            const source = {
                url: winner.url,
                type: inferType(winner.item.type, winner.url),
                quality,
                audioTracks: lang
                    ? [{ label: lang, language: lang }]
                    : [{ label: 'Original', language: 'Original' }],
                provider: { id: this.id, name: this.name }
            };
            const edge = String(winner.item.name || winner.item.provider || 'edge').trim();
            const result = {
                sources: [source],
                subtitles: [],
                diagnostics: [
                    {
                        code: 'PARTIAL_SCRAPE',
                        message: `${this.name}: picked ${edge} in ${winner.ms}ms (${alive}/${raced} healthy)`,
                        field: '',
                        severity: 'info'
                    }
                ]
            };
            resultCache.set(key, { expiresAt: Date.now() + RESULT_TTL_MS, result });
            return result;
        }
        catch (error) {
            return this.emptyResult(error instanceof Error ? error.message : 'provider error');
        }
    }
    async healthCheck() {
        try {
            const response = await fetch(`${SITE}/api/config`, {
                headers: this.HEADERS,
                signal: AbortSignal.timeout(8_000)
            });
            return response.ok;
        }
        catch {
            return false;
        }
    }
}
//# sourceMappingURL=novahd.js.map