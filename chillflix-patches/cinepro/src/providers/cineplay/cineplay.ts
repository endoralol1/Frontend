import { spawn } from 'node:child_process';
import { existsSync, mkdirSync, readFileSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';

import { BaseProvider } from '@omss/framework';
import type {
    ProviderCapabilities,
    ProviderMediaObject,
    ProviderResult,
    Source
} from '@omss/framework';

import {
    cineplayCacheKey,
    getCachedVaplayerResolve,
    listEnabledWorkers,
    pickWorker,
    readWorkerRelayConfig,
    setCachedVaplayerResolve,
    type WorkerRelayEntry
} from '../../config/worker-relay.js';
import { inferSourceType } from '../vidapi/embed-resolver.js';

const EMBED_ORIGIN = 'https://www.vidking.net';
const EMBED_REFERER = 'https://www.vidking.net/';

const DEFAULT_NODE_RESOLVE_SCRIPT =
    '/var/www/chillflix-newsite/bin/cineplay-yoru-resolve.mjs';

/** Shared with Vuflix PHP `CineplaySources` raw cache. */
const SHARED_YORU_CACHE_DIR =
    process.env.CINEPLAY_YORU_CACHE_DIR?.trim() ||
    '/var/www/chillflix-newsite/storage/cache/cineplay-yoru';
/** Fallback when Worker relay TTL is unavailable (matches admin default 2h). */
const SHARED_YORU_CACHE_TTL_SEC = 7200;

type RelaySource = {
    url?: string;
    quality?: string;
    type?: string;
};

type RelayResponse = {
    ok?: boolean;
    error?: string;
    sources?: RelaySource[];
};

function sharedCacheKey(
    type: string,
    tmdbId: string,
    season: number,
    episode: number
): string {
    return `${type}:${tmdbId}:${Math.max(1, season)}:${Math.max(1, episode)}`;
}

function sharedCachePath(key: string): string {
    const safe = key.replace(/[^a-zA-Z0-9:_-]+/g, '_') || 'x';
    return join(SHARED_YORU_CACHE_DIR, `${safe}.json`);
}

function readSharedYoruCache(key: string): RelaySource[] {
    try {
        const raw = readFileSync(sharedCachePath(key), 'utf8');
        const wrap = JSON.parse(raw) as {
            exp?: number;
            data?: RelayResponse;
        };
        if (!wrap?.exp || wrap.exp < Math.floor(Date.now() / 1000)) {
            return [];
        }
        const sources = wrap.data?.sources;
        if (!wrap.data?.ok || !Array.isArray(sources) || !sources.length) {
            return [];
        }
        return sources;
    } catch {
        return [];
    }
}

function writeSharedYoruCache(
    key: string,
    sources: RelaySource[],
    ttlSeconds = SHARED_YORU_CACHE_TTL_SEC
): void {
    try {
        const ttl = Math.min(86_400, Math.max(60, Math.floor(ttlSeconds)));
        mkdirSync(SHARED_YORU_CACHE_DIR, { recursive: true });
        writeFileSync(
            sharedCachePath(key),
            JSON.stringify({
                exp: Math.floor(Date.now() / 1000) + ttl,
                data: { ok: true, sources }
            }),
            'utf8'
        );
    } catch {
        // best-effort share with Vuflix
    }
}

function qualityRank(quality: string): number {
    const q = quality.toLowerCase();
    if (q.includes('2160') || q.includes('4k') || q.includes('uhd')) return 400;
    if (q.includes('1080')) return 300;
    if (q.includes('720')) return 200;
    if (q.includes('480')) return 100;
    if (q.includes('360')) return 50;
    return 10;
}

/** Retry other Workers only on auth/config failures — not upstream seed 429 (avoids burning free-tier quota). */
function shouldTryNextWorker(error: string | undefined, status: number): boolean {
    const msg = (error || '').toLowerCase();
    if (status === 401 || status === 403) return true;
    return msg.includes('unauthorized') || msg.includes('invalid key') || msg.includes('forbidden');
}

function resolveNodeScriptPath(): string | null {
    const candidates = [
        process.env.CINEPLAY_YORU_RESOLVE_SCRIPT?.trim() || '',
        DEFAULT_NODE_RESOLVE_SCRIPT,
        '/var/www/chillflix-newsite/db-system/bin/cineplay-yoru-resolve.mjs'
    ].filter(Boolean);

    for (const path of candidates) {
        if (existsSync(path)) return path;
    }
    return null;
}

/**
 * Same local Yoru scrape Vuflix uses when Workers hit seed 429 / CF blocks.
 */
function scrapeViaNode(args: {
    type: 'movie' | 'tv';
    tmdbId: string;
    season: number;
    episode: number;
    title?: string;
    year?: string;
}): Promise<{ sources: RelaySource[]; error?: string }> {
    const script = resolveNodeScriptPath();
    if (!script) {
        return Promise.resolve({ sources: [], error: 'node resolver script missing' });
    }

    const nodeBin = process.env.NODE_BINARY?.trim() || process.execPath || 'node';
    const cli = [
        script,
        '--type',
        args.type,
        '--tmdb',
        args.tmdbId,
        '--season',
        String(args.season),
        '--episode',
        String(args.episode)
    ];
    if (args.title) {
        cli.push('--title', args.title);
    }
    if (args.year) {
        cli.push('--year', args.year);
    }

    return new Promise((resolve) => {
        const child = spawn(nodeBin, cli, {
            env: process.env,
            stdio: ['ignore', 'pipe', 'pipe']
        });

        let stdout = '';
        let stderr = '';
        const timer = setTimeout(() => {
            child.kill('SIGKILL');
        }, 45_000);

        child.stdout.on('data', (chunk) => {
            stdout += String(chunk);
        });
        child.stderr.on('data', (chunk) => {
            stderr += String(chunk);
        });
        child.on('error', (error) => {
            clearTimeout(timer);
            resolve({ sources: [], error: error.message || 'failed to start node resolver' });
        });
        child.on('close', () => {
            clearTimeout(timer);
            let json: RelayResponse | null = null;
            try {
                json = JSON.parse(stdout.trim()) as RelayResponse;
            } catch {
                resolve({
                    sources: [],
                    error: stderr.trim() || 'node resolver returned invalid JSON'
                });
                return;
            }

            if (!json?.ok || !Array.isArray(json.sources) || !json.sources.length) {
                resolve({
                    sources: [],
                    error: json?.error || 'node resolver returned no streams'
                });
                return;
            }

            resolve({ sources: json.sources });
        });
    });
}

/**
 * Cineplay / Yoru (same source Vuflix uses).
 * Prefer shared Cloudflare Worker `/resolve`, then fall back to the same local
 * node scrape Vuflix uses when Workers get seed 429.
 */
export class CineplayProvider extends BaseProvider {
    readonly id = 'cineplay';
    readonly name = 'Cineplay';
    readonly enabled = true;
    readonly BASE_URL = EMBED_ORIGIN;
    readonly HEADERS = {
        'User-Agent':
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/137.0.0.0 Safari/537.36',
        Accept: 'application/json, */*; q=0.01',
        Referer: EMBED_REFERER,
        Origin: EMBED_ORIGIN
    };

    readonly capabilities: ProviderCapabilities = {
        supportedContentTypes: ['movies', 'tv']
    };

    async getMovieSources(media: ProviderMediaObject): Promise<ProviderResult> {
        return this.getSources(media);
    }

    async getTVSources(media: ProviderMediaObject): Promise<ProviderResult> {
        return this.getSources(media);
    }

    private async getSources(media: ProviderMediaObject): Promise<ProviderResult> {
        try {
            const type = media.type === 'tv' ? 'tv' : 'movie';
            const tmdbId = String(media.tmdbId ?? '');
            const season = media.s ?? 1;
            const episode = media.e ?? 1;
            let lastError = 'Cineplay returned no streams';

            const relay = readWorkerRelayConfig();
            const memKey = cineplayCacheKey({ type, tmdbId, season, episode });
            const diskKey = sharedCacheKey(type, tmdbId, season, episode);
            const ttlSeconds = relay.cacheTtlSeconds || SHARED_YORU_CACHE_TTL_SEC;

            const remember = (raw: RelaySource[]): ProviderResult | null => {
                const mapped = this.mapSources(raw);
                if (!mapped.length) return null;
                setCachedVaplayerResolve(memKey, raw, ttlSeconds);
                writeSharedYoruCache(diskKey, raw, ttlSeconds);
                return { sources: mapped, subtitles: [], diagnostics: [] };
            };

            // Same 2h in-memory resolve cache VAPlayer uses (admin Worker TTL).
            const memHit = getCachedVaplayerResolve<RelaySource[]>(memKey);
            if (Array.isArray(memHit) && memHit.length) {
                const mapped = this.mapSources(memHit);
                if (mapped.length) {
                    return { sources: mapped, subtitles: [], diagnostics: [] };
                }
            }

            const workers = relay.enabled ? listEnabledWorkers(relay) : [];

            if (workers.length) {
                // Start from round-robin pick, then walk the rest of the pool on soft failures.
                const start = pickWorker(relay) || workers[0];
                const startIdx = Math.max(
                    0,
                    workers.findIndex((w) => w.id === start.id)
                );
                const ordered: WorkerRelayEntry[] = [
                    ...workers.slice(startIdx),
                    ...workers.slice(0, startIdx)
                ];

                for (const worker of ordered) {
                    const url = new URL(`${worker.url}/resolve`);
                    url.searchParams.set('type', type);
                    url.searchParams.set('tmdb', tmdbId);
                    url.searchParams.set('season', String(season));
                    url.searchParams.set('episode', String(episode));
                    url.searchParams.set('cacheTtl', String(relay.cacheTtlSeconds));
                    if (media.title) url.searchParams.set('title', String(media.title));
                    if (media.releaseYear) {
                        url.searchParams.set('year', String(media.releaseYear));
                    }
                    if (media.imdbId?.startsWith('tt')) {
                        url.searchParams.set('imdb', media.imdbId);
                    }

                    let response: Response;
                    let json: RelayResponse | null;
                    try {
                        response = await fetch(url.toString(), {
                            headers: {
                                Accept: 'application/json',
                                'User-Agent': 'VuflixYoruRelay/1.0',
                                'X-Yoru-Key': worker.secret
                            },
                            signal: AbortSignal.timeout(45_000)
                        });
                        json = (await response.json().catch(() => null)) as RelayResponse | null;
                    } catch (error) {
                        lastError =
                            error instanceof Error
                                ? `${worker.label}: ${error.message}`
                                : `${worker.label}: network error`;
                        continue;
                    }

                    if (
                        response.ok &&
                        json?.ok &&
                        Array.isArray(json.sources) &&
                        json.sources.length
                    ) {
                        const remembered = remember(json.sources);
                        if (remembered) return remembered;
                        lastError = `${worker.label}: no usable streams`;
                        continue;
                    }

                    lastError = json?.error
                        ? `${worker.label}: ${json.error}`
                        : `${worker.label}: HTTP ${response.status}`;

                    if (!shouldTryNextWorker(json?.error, response.status)) {
                        break;
                    }
                }
            } else {
                lastError = relay.enabled
                    ? 'No Worker configured for Cineplay/Yoru'
                    : 'Worker relay disabled';
            }

            // Share Vuflix/Chillflix disk cache — reopen without re-scraping.
            const cached = readSharedYoruCache(diskKey);
            if (cached.length) {
                const remembered = remember(cached);
                if (remembered) {
                    console.info(
                        `[cineplay] Worker miss (${lastError}); shared Yoru cache hit (${remembered.sources.length})`
                    );
                    return remembered;
                }
            }

            // Same local node scrape Vuflix uses after relay seed 429 / empty.
            const node = await scrapeViaNode({
                type,
                tmdbId,
                season,
                episode,
                title: media.title ? String(media.title) : undefined,
                year: media.releaseYear ? String(media.releaseYear) : undefined
            });
            if (node.sources.length) {
                const remembered = remember(node.sources);
                if (remembered) {
                    console.info(
                        `[cineplay] Worker miss (${lastError}); node fallback returned ${remembered.sources.length} stream(s)`
                    );
                    return remembered;
                }
            }

            return this.emptyResult(
                node.error
                    ? `Cineplay relay: ${lastError}; node: ${node.error}`
                    : `Cineplay relay: ${lastError}`
            );
        } catch (error) {
            return this.emptyResult(
                error instanceof Error ? error.message : 'Cineplay provider error'
            );
        }
    }

    private mapSources(raw: RelaySource[]): Source[] {
        const headers = { ...this.HEADERS };
        const items = raw
            .map((src) => {
                const streamUrl = typeof src?.url === 'string' ? src.url.trim() : '';
                if (!streamUrl || streamUrl.includes('strategicgrowthpartners')) {
                    return null;
                }
                const quality = String(src.quality || 'Auto').trim() || 'Auto';
                return {
                    url: this.createProxyUrl(streamUrl, headers),
                    type: inferSourceType(streamUrl),
                    quality,
                    audioTracks: [{ language: 'en', label: 'English' }],
                    provider: { id: this.id, name: this.name },
                    _rank: qualityRank(quality)
                };
            })
            .filter((s): s is NonNullable<typeof s> => Boolean(s))
            .sort((a, b) => b._rank - a._rank)
            .map(({ _rank, ...source }) => source);

        return items;
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
                    severity: 'error'
                }
            ]
        };
    }
}
