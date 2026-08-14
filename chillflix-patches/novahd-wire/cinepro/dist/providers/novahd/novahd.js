import { BaseProvider } from '@omss/framework';
const SITE = 'https://novahd.cc';
const API = `${SITE}/api/sources`;
const RESULT_TTL_MS = 8 * 60 * 1000;
const MAX_SOURCES = 6;
const UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36';
const resultCache = new Map();
function inferType(raw, url) {
    const blob = `${raw || ''} ${url}`.toLowerCase();
    if (blob.includes('hls') || /\.m3u8(\?|$)/i.test(url))
        return 'hls';
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
    if (lower === 'auto' || lower === '')
        return 900;
    const n = parseInt(q, 10);
    return Number.isFinite(n) ? n : 0;
}
function displayQuality(item) {
    const q = String(item.quality || 'Auto').trim() || 'Auto';
    const name = String(item.name || item.provider || '').trim();
    if (!name)
        return q;
    if (q.toLowerCase() === 'auto')
        return name;
    return `${q} · ${name}`;
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
        const controller = new AbortController();
        const timer = setTimeout(() => controller.abort(), 20_000);
        try {
            const response = await fetch(url, {
                headers: this.HEADERS,
                redirect: 'follow',
                signal: controller.signal
            });
            const text = await response.text();
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
            return {
                items: [],
                error: error instanceof Error ? error.message : 'fetch failed'
            };
        }
        finally {
            clearTimeout(timer);
        }
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
            const seen = new Set();
            const ranked = items
                .filter((item) => typeof item?.url === 'string' && /^https?:\/\//i.test(item.url))
                .map((item) => ({
                item,
                rank: qualityRank(String(item.quality || '')),
                hevc: /hevc|h\.?265/i.test(String(item.codecs || ''))
            }))
                .sort((a, b) => {
                if (a.hevc !== b.hevc)
                    return a.hevc ? 1 : -1;
                return b.rank - a.rank;
            });
            const sources = [];
            for (const { item } of ranked) {
                const url = String(item.url);
                const dedupe = String(item.hostKey || url.split('#')[0]);
                if (seen.has(dedupe))
                    continue;
                seen.add(dedupe);
                const lang = String(item.language || '').trim();
                sources.push({
                    url,
                    type: inferType(item.type, url),
                    quality: displayQuality(item),
                    audioTracks: lang
                        ? [{ label: lang, language: lang }]
                        : [{ label: 'Original', language: 'Original' }],
                    provider: { id: this.id, name: this.name }
                });
                if (sources.length >= MAX_SOURCES)
                    break;
            }
            if (!sources.length) {
                return this.emptyResult(error || 'No playable NovaHD URLs');
            }
            const result = { sources, subtitles: [], diagnostics: [] };
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