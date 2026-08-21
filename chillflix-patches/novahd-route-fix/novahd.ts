import { BaseProvider } from '@omss/framework';
import type {
    ProviderCapabilities,
    ProviderMediaObject,
    ProviderResult,
    Source,
    SourceType
} from '@omss/framework';

const SITE = 'https://novahd.cc';
const API = `${SITE}/api/sources`;
const RESULT_TTL_MS = 12 * 60 * 1000;
/** Race at most this many CDN candidates (keeps latency + upstream load bounded). */
const MAX_RACE = 8;
const PROBE_TIMEOUT_MS = 7_000;
const PROBE_BYTES = 1_024;

const UA =
    'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36';

type NovaSource = {
    url?: string;
    quality?: string;
    type?: string;
    provider?: string;
    language?: string;
    name?: string;
    codecs?: string;
    hostKey?: string;
};

type ProbedSource = {
    item: NovaSource;
    url: string;
    ms: number;
    hevc: boolean;
    qualityRank: number;
};

const resultCache = new Map<string, { expiresAt: number; result: ProviderResult }>();

function inferType(raw: string | undefined, url: string): SourceType {
    const blob = `${raw || ''} ${url}`.toLowerCase();
    if (blob.includes('hls') || /\.m3u8(\?|$)/i.test(url) || /workers\.dev/i.test(url)) {
        return 'hls';
    }
    if (blob.includes('mkv') || /\.mkv(\?|$)/i.test(url)) return 'mkv';
    if (blob.includes('webm') || /\.webm(\?|$)/i.test(url)) return 'webm';
    if (blob.includes('mp4') || /\.mp4(\?|$)/i.test(url)) return 'mp4';
    return 'hls';
}

function qualityRank(q: string): number {
    const lower = q.toLowerCase();
    if (/\b2160\b|4k|uhd/.test(lower)) return 2160;
    if (/\b1080\b/.test(lower)) return 1080;
    if (/\b720\b/.test(lower)) return 720;
    if (/\b480\b/.test(lower)) return 480;
    if (/\b360\b/.test(lower)) return 360;
    // Adaptive masters usually advertise Auto — treat as high priority for racing.
    if (lower === 'auto' || lower === '') return 1080;
    const n = parseInt(q, 10);
    return Number.isFinite(n) ? n : 0;
}

/** Real quality label only — never Nova server/edge names. */
function cleanQuality(item: NovaSource): string {
    const q = String(item.quality || '').trim();
    if (!q) return 'Auto';
    if (/^(auto|unknown|default)$/i.test(q)) return 'Auto';
    if (/\b2160\b|4k|uhd/i.test(q)) return '2160p';
    if (/\b1080\b/i.test(q)) return '1080p';
    if (/\b720\b/i.test(q)) return '720p';
    if (/\b480\b/i.test(q)) return '480p';
    if (/\b360\b/i.test(q)) return '360p';
    // Reject server-ish labels that slipped into quality.
    if (/vega|orion|raven|falcon|marlin|helios|ember|alpha|beta|main/i.test(q)) {
        return 'Auto';
    }
    return q;
}

function isHevc(item: NovaSource): boolean {
    return /hevc|h\.?265/i.test(String(item.codecs || ''));
}

function looksPlayable(bytes: Uint8Array): boolean {
    if (!bytes.length) return false;
    const head = Buffer.from(bytes.subarray(0, Math.min(bytes.length, 256))).toString('utf8');
    if (/#EXTM3U/i.test(head)) return true;
    if (bytes.length >= 8) {
        // ISO BMFF / MP4
        const box = Buffer.from(bytes.subarray(4, 8)).toString('ascii');
        if (box === 'ftyp' || box === 'moov' || box === 'mdat') return true;
    }
    return bytes.length >= 200;
}

export class NovahdProvider extends BaseProvider {
    readonly id = 'novahd';
    readonly name = 'NovaHD';
    readonly enabled = true;
    readonly BASE_URL = SITE;
    readonly HEADERS: Record<string, string> = {
        'User-Agent': UA,
        Accept: 'application/x-ndjson, application/json, text/plain, */*',
        'Accept-Language': 'en-US,en;q=0.9',
        Referer: `${SITE}/`,
        Origin: SITE
    };

    /** Fresh visitor id per request — reused/static/"1" gets decoy streams only. */
    private static freshVisitorId(): string {
        return (
            'nv' +
            Math.random().toString(36).slice(2, 10) +
            Date.now().toString(36)
        );
    }

    readonly capabilities: ProviderCapabilities = {
        supportedContentTypes: ['movies', 'tv']
    };

    async getMovieSources(media: ProviderMediaObject): Promise<ProviderResult> {
        return this.resolve(media);
    }

    async getTVSources(media: ProviderMediaObject): Promise<ProviderResult> {
        return this.resolve(media);
    }

    private cacheKey(media: ProviderMediaObject): string {
        return media.type === 'tv'
            ? `tv:${media.tmdbId}:s${media.s ?? 1}:e${media.e ?? 1}`
            : `movie:${media.tmdbId}`;
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
                    severity: 'warning'
                }
            ]
        };
    }

    private buildUrl(media: ProviderMediaObject): string | null {
        const tmdbId = String(media.tmdbId || '').trim();
        if (!tmdbId) return null;

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

    private async fetchSources(url: string): Promise<{ items: NovaSource[]; error?: string }> {
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
                    headers: {
                        ...this.HEADERS,
                        'x-nova-visitor': NovahdProvider.freshVisitorId()
                    },
                    redirect: 'follow',
                    signal: controller.signal
                });
                const text = await response.text();
                if (response.status === 429 || response.status === 403) {
                    try {
                        const j = JSON.parse(text) as {
                            error?: string;
                            message?: string;
                            hours?: number;
                        };
                        const detail = String(j.message || j.error || '').trim();
                        lastError = detail
                            ? `HTTP ${response.status}: ${detail}`
                            : `HTTP ${response.status} (rate-limited)`;
                        const waitMatch = detail.match(/retry in\s+(\d+)\s*(second|minute|hour)/i);
                        if (waitMatch) {
                            let ms = parseInt(waitMatch[1], 10) * 1000;
                            const unit = waitMatch[2].toLowerCase();
                            if (unit.startsWith('minute')) ms *= 60;
                            if (unit.startsWith('hour')) ms *= 3600;
                            await new Promise((r) => setTimeout(r, Math.min(ms, 90_000)));
                        }
                    } catch {
                        lastError = `HTTP ${response.status} (rate-limited)`;
                    }
                    continue;
                }
                if (!response.ok) {
                    return { items: [], error: `HTTP ${response.status}` };
                }
                let items: NovaSource[] = [];
                let err = '';
                const ctype = (response.headers.get('content-type') || '').toLowerCase();
                try {
                    if (ctype.includes('ndjson') || text.includes('\n{')) {
                        // NDJSON stream: one JSON object per line (and/or a final done line).
                        for (const line of text.split('\n')) {
                            const row = line.trim();
                            if (!row || row.charCodeAt(0) === 58) continue;
                            let obj: { sources?: NovaSource[]; error?: string; message?: string; done?: boolean };
                            try {
                                obj = JSON.parse(row) as typeof obj;
                            } catch {
                                continue;
                            }
                            if (Array.isArray(obj.sources)) items.push(...obj.sources);
                            const msg = String(obj.error || obj.message || '').trim();
                            if (msg) err = msg;
                        }
                    } else {
                        const json = JSON.parse(text) as {
                            sources?: NovaSource[];
                            error?: string;
                            message?: string;
                        };
                        err = String(json.error || json.message || '').trim();
                        items = Array.isArray(json.sources) ? json.sources : [];
                    }
                } catch {
                    return { items: [], error: 'Non-JSON response' };
                }
                // Nova returns a decoy playlist to bots / bad visitor ids — never treat as playable.
                items = items.filter((s) => {
                    const u = String(s?.url || '');
                    return /^https?:\/\//i.test(u) && !/\/api\/decoy\//i.test(u);
                });
                if (!items.length && !err) {
                    err = 'Only decoy sources returned (visitor/route blocked)';
                    lastError = err;
                    continue;
                }
                return { items, error: err || undefined };
            } catch (error) {
                lastError = error instanceof Error ? error.message : 'fetch failed';
            } finally {
                clearTimeout(timer);
            }
        }
        return { items: [], error: lastError };
    }

    /** Short GET of playlist/MP4 head — measures TTFB + confirms stream is alive. */
    private async probeCandidate(item: NovaSource): Promise<ProbedSource | null> {
        const url = String(item.url || '');
        if (!/^https?:\/\//i.test(url) || /\/api\/decoy\//i.test(url)) return null;
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
            if (!response.ok && response.status !== 206) return null;
            const buf = new Uint8Array(await response.arrayBuffer());
            if (!looksPlayable(buf)) return null;
            return {
                item,
                url,
                ms: Date.now() - started,
                hevc: isHevc(item),
                qualityRank: qualityRank(String(item.quality || ''))
            };
        } catch {
            return null;
        }
    }

    /**
     * Race CDN edges in parallel and keep the fastest healthy stream.
     * Quality UI should come from HLS levels — not from Nova server names.
     */
    private async pickFastest(items: NovaSource[]): Promise<{
        winner: ProbedSource | null;
        raced: number;
        alive: number;
    }> {
        const seen = new Set<string>();
        const candidates: NovaSource[] = [];
        const pre = items
            .filter((item) => typeof item?.url === 'string' && /^https?:\/\//i.test(String(item.url)))
            .map((item) => ({
                item,
                hevc: isHevc(item),
                rank: qualityRank(String(item.quality || ''))
            }))
            .sort((a, b) => {
                if (a.hevc !== b.hevc) return a.hevc ? 1 : -1;
                return b.rank - a.rank;
            });

        for (const { item } of pre) {
            const key = String(item.hostKey || String(item.url).split('#')[0]);
            if (seen.has(key)) continue;
            seen.add(key);
            candidates.push(item);
            if (candidates.length >= MAX_RACE) break;
        }

        const probed = await Promise.all(candidates.map((c) => this.probeCandidate(c)));
        const alive = probed.filter((p): p is ProbedSource => p !== null);
        alive.sort((a, b) => {
            if (a.hevc !== b.hevc) return a.hevc ? 1 : -1;
            if (a.ms !== b.ms) return a.ms - b.ms;
            return b.qualityRank - a.qualityRank;
        });

        return { winner: alive[0] || null, raced: candidates.length, alive: alive.length };
    }

    private async resolve(media: ProviderMediaObject): Promise<ProviderResult> {
        const key = this.cacheKey(media);
        const hit = resultCache.get(key);
        if (hit && hit.expiresAt > Date.now()) return hit.result;

        const apiUrl = this.buildUrl(media);
        if (!apiUrl) return this.emptyResult('Missing TMDB id');

        try {
            const { items, error } = await this.fetchSources(apiUrl);
            if (!items.length) {
                return this.emptyResult(error || `No sources for TMDB ${media.tmdbId}`);
            }

            const { winner, raced, alive } = await this.pickFastest(items);
            if (!winner) {
                return this.emptyResult(
                    error || `No healthy NovaHD edges (${raced} raced, ${alive} alive)`
                );
            }

            const quality = cleanQuality(winner.item);
            const lang = String(winner.item.language || '').trim();
            const source: Source = {
                url: winner.url,
                type: inferType(winner.item.type, winner.url),
                quality,
                audioTracks: lang
                    ? [{ label: lang, language: lang }]
                    : [{ label: 'Original', language: 'Original' }],
                provider: { id: this.id, name: this.name }
            };

            const edge = String(winner.item.name || winner.item.provider || 'edge').trim();
            const result: ProviderResult = {
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
        } catch (error) {
            return this.emptyResult(
                error instanceof Error ? error.message : 'provider error'
            );
        }
    }

    async healthCheck(): Promise<boolean> {
        try {
            const response = await fetch(`${SITE}/api/config`, {
                headers: {
                    ...this.HEADERS,
                    'x-nova-visitor': NovahdProvider.freshVisitorId()
                },
                signal: AbortSignal.timeout(8_000)
            });
            return response.ok;
        } catch {
            return false;
        }
    }
}
