import { BaseProvider } from '@omss/framework';
const API = 'https://api.bingr.one/api';
const SITE = 'https://bingr.one';
const RESULT_TTL_MS = 10 * 60 * 1000;
/** Prefer reliable scrapers first; stop at first hit to avoid rate limits. */
const SERVERS = [
    // Apollo first: muxed quality ladder (avoids Sirius demuxed 1.6k-seg monsters).
    { id: 's30', name: 'Apollo' },
    { id: 's3', name: 'Edmunds' },
    { id: 's11', name: 'Sirius' },
    { id: 's12', name: 'Quasar' },
    { id: 's40', name: 'DarkMatter' },
    { id: 's1', name: 'Miller' },
    { id: 's2', name: 'Mann' },
    { id: 's4', name: 'Luna' },
    { id: 's5', name: 'Aditya' }
];
const UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36';
const resultCache = new Map();
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
    if (/auto|unknown|^$/.test(lower))
        return 1080;
    const n = parseInt(q, 10);
    return Number.isFinite(n) ? n : 0;
}
function cleanQuality(raw) {
    const q = String(raw || '').trim();
    if (!q || /^(auto|unknown|default)$/i.test(q))
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
    return q;
}
/** Prefer English / multi / original over single non-English labels. */
function languageScore(lang, label) {
    const blob = `${lang || ''} ${label || ''}`.toLowerCase();
    if (/\benglish\b|\beng\b/.test(blob) && !/\bhindi\b/.test(blob))
        return 100;
    if (/\bmulti\b/.test(blob))
        return 95;
    if (/\boriginal\b/.test(blob))
        return 85;
    if (/\bhindi\b/.test(blob))
        return 55;
    if (blob.trim())
        return 50;
    return 70;
}
/**
 * Bingr often wraps upstream HLS in wormhole.filmu.in — unwrap so our media-proxy
 * talks to the CDN directly with the baked-in headers.
 */
function unwrapWormhole(url) {
    try {
        const u = new URL(url);
        if (!/wormhole\.filmu\.in$/i.test(u.hostname))
            return null;
        if (!/\/proxy\/(m3u8|file)/i.test(u.pathname))
            return null;
        const nested = u.searchParams.get('url');
        if (!nested || !/^https?:\/\//i.test(nested))
            return null;
        const headers = {};
        const headersRaw = u.searchParams.get('headers');
        if (headersRaw) {
            try {
                const parsed = JSON.parse(headersRaw);
                for (const [k, v] of Object.entries(parsed)) {
                    if (typeof v === 'string' && v)
                        headers[k] = v;
                }
            }
            catch {
                /* ignore bad headers JSON */
            }
        }
        const referer = u.searchParams.get('referer');
        const origin = u.searchParams.get('origin');
        if (referer && !headers.Referer)
            headers.Referer = referer;
        if (origin && !headers.Origin)
            headers.Origin = origin;
        return { url: nested, headers };
    }
    catch {
        return null;
    }
}
function pickBest(sources) {
    const usable = sources.filter((s) => /^https?:\/\//i.test(String(s.url || '').trim()));
    if (!usable.length)
        return null;
    usable.sort((a, b) => {
        const ls = languageScore(b.language, b.label || b.name) -
            languageScore(a.language, a.label || a.name);
        if (ls !== 0)
            return ls;
        return qualityRank(String(b.quality || '')) - qualityRank(String(a.quality || ''));
    });
    return usable[0] ?? null;
}
export class BingrProvider extends BaseProvider {
    id = 'bingr';
    name = 'Bingr';
    enabled = true;
    BASE_URL = SITE;
    HEADERS = {
        'User-Agent': UA,
        Accept: 'application/json, text/plain, */*',
        'Accept-Language': 'en-US,en;q=0.9',
        Origin: SITE,
        Referer: `${SITE}/`
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
    async postStream(srv, media) {
        const query = {};
        const title = String(media.title || '').trim();
        const year = String(media.releaseYear || '').trim();
        if (title)
            query.title = title;
        if (year)
            query.year = year;
        if (media.type === 'tv') {
            query.season = Number(media.s ?? 1) || 1;
            query.episode = Number(media.e ?? 1) || 1;
        }
        const body = JSON.stringify({
            srv,
            t: media.type === 'tv' ? 'tv' : 'movie',
            id: String(media.tmdbId),
            query
        });
        const controller = new AbortController();
        const timer = setTimeout(() => controller.abort(), 22_000);
        try {
            const response = await fetch(`${API}/stream`, {
                method: 'POST',
                headers: {
                    ...this.HEADERS,
                    'Content-Type': 'application/json'
                },
                body,
                signal: controller.signal
            });
            const text = await response.text();
            let json = {};
            try {
                json = JSON.parse(text);
            }
            catch {
                throw new Error(`Non-JSON from ${srv} (HTTP ${response.status})`);
            }
            if (!response.ok) {
                throw new Error(json.error || `HTTP ${response.status} from ${srv}`);
            }
            return json;
        }
        finally {
            clearTimeout(timer);
        }
    }
    mapSubtitles(raw) {
        if (!Array.isArray(raw))
            return [];
        const out = [];
        for (const sub of raw.slice(0, 12)) {
            const url = String(sub?.url || '').trim();
            if (!/^https?:\/\//i.test(url))
                continue;
            const label = String(sub.label || sub.language || sub.lang || 'Subtitle').trim();
            const format = /\.srt(\?|$)/i.test(url)
                ? 'srt'
                : /\.ass(\?|$)/i.test(url)
                    ? 'ass'
                    : 'vtt';
            out.push({
                url: this.createProxyUrl(url, {
                    'User-Agent': UA,
                    Referer: `${SITE}/`,
                    Origin: SITE
                }),
                label,
                format
            });
        }
        return out;
    }
    buildPlayable(hit, scraperName) {
        let streamUrl = String(hit.url || '').trim();
        const headers = {
            'User-Agent': UA,
            ...(hit.headers || {})
        };
        if (!headers.Referer)
            headers.Referer = `${SITE}/`;
        if (!headers.Origin && headers.Referer) {
            try {
                headers.Origin = new URL(headers.Referer).origin;
            }
            catch {
                headers.Origin = SITE;
            }
        }
        const unwrapped = unwrapWormhole(streamUrl);
        if (unwrapped) {
            streamUrl = unwrapped.url;
            Object.assign(headers, unwrapped.headers);
        }
        const lang = String(hit.language || '').trim();
        const quality = cleanQuality(hit.quality);
        return {
            url: this.createProxyUrl(streamUrl, headers),
            type: 'hls',
            quality,
            audioTracks: lang
                ? [{ label: lang, language: lang }]
                : [{ label: 'Original', language: 'Original' }],
            provider: { id: this.id, name: this.name }
        };
    }
    async resolve(media) {
        const key = this.cacheKey(media);
        const cached = resultCache.get(key);
        if (cached && cached.expiresAt > Date.now())
            return cached.result;
        try {
            if (!media.tmdbId)
                return this.emptyResult('Missing TMDB id');
            const tried = [];
            let lastError = '';
            for (const server of SERVERS) {
                tried.push(server.id);
                try {
                    const json = await this.postStream(server.id, media);
                    const best = pickBest(json.sources || []);
                    if (!best) {
                        lastError = json.error || `${server.name} empty`;
                        continue;
                    }
                    const scraper = String(json.scraperName || server.name);
                    const source = this.buildPlayable(best, scraper);
                    const result = {
                        sources: [source],
                        subtitles: this.mapSubtitles(json.subtitles),
                        diagnostics: [
                            {
                                code: 'PARTIAL_SCRAPE',
                                message: `${this.name}: ${scraper} · ${source.quality} (tried ${tried.join(',')})`,
                                field: '',
                                severity: 'info'
                            }
                        ]
                    };
                    resultCache.set(key, { expiresAt: Date.now() + RESULT_TTL_MS, result });
                    return result;
                }
                catch (error) {
                    lastError = error instanceof Error ? error.message : 'server error';
                    // Continue to next server; do not abort the whole resolve.
                }
            }
            return this.emptyResult(lastError || `No Bingr streams (tried ${tried.join(',')})`);
        }
        catch (error) {
            return this.emptyResult(error instanceof Error ? error.message : 'provider error');
        }
    }
    async healthCheck() {
        try {
            const response = await fetch(`${API}/details/movie/27205`, {
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
//# sourceMappingURL=bingr.js.map