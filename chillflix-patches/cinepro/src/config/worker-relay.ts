import { readFileSync, statSync, existsSync, writeFileSync, mkdirSync } from 'node:fs';
import { dirname, join } from 'node:path';

export type WorkerRelayEntry = {
    id: string;
    label: string;
    url: string;
    secret: string;
    enabled: boolean;
    cfAccountId?: string;
    cfApiToken?: string;
    cfScriptName?: string;
};

export type WorkerRelayConfig = {
    enabled: boolean;
    preferWorker: boolean;
    /** How long cinepro keeps a successful VAPlayer resolve in memory. */
    cacheTtlSeconds: number;
    workers: WorkerRelayEntry[];
};

const CONFIG_PATH =
    process.env.WORKER_RELAY_CONFIG_PATH?.trim() ||
    join(process.cwd(), 'config', 'worker-relay.json');

const DEFAULT_CONFIG: WorkerRelayConfig = {
    enabled: true,
    preferWorker: true,
    cacheTtlSeconds: 7200,
    workers: []
};

type CachedFile = {
    mtimeMs: number;
    config: WorkerRelayConfig;
    loadedAt: number;
};

let fileCache: CachedFile | null = null;
let rrIndex = 0;

function clampTtl(value: unknown): number {
    const n = Number(value);
    if (!Number.isFinite(n)) return DEFAULT_CONFIG.cacheTtlSeconds;
    // 60s .. 24h
    return Math.min(86_400, Math.max(60, Math.floor(n)));
}

function normalizeWorkerUrl(raw: string): string {
    let url = String(raw || '')
        .trim()
        .replace(/\/$/, '');
    if (!url) return '';
    if (!/^https?:\/\//i.test(url)) {
        url = `https://${url}`;
    }
    return url;
}

function sanitizeWorker(raw: unknown, index: number): WorkerRelayEntry | null {
    if (!raw || typeof raw !== 'object') return null;
    const row = raw as Record<string, unknown>;
    const url = normalizeWorkerUrl(String(row.url || ''));
    const secret = String(row.secret || '').trim();
    if (!url || !secret) return null;
    const cfAccountId = String(row.cfAccountId || '').trim();
    const cfApiToken = String(row.cfApiToken || '').trim();
    const cfScriptName = String(row.cfScriptName || '').trim();
    return {
        id: String(row.id || `worker-${index + 1}`).trim() || `worker-${index + 1}`,
        label: String(row.label || `Worker ${index + 1}`).trim() || `Worker ${index + 1}`,
        url,
        secret,
        enabled: row.enabled !== false,
        cfAccountId: cfAccountId || undefined,
        cfApiToken: cfApiToken || undefined,
        cfScriptName: cfScriptName || undefined
    };
}

export function sanitizeWorkerRelayConfig(raw: unknown): WorkerRelayConfig {
    const row =
        raw && typeof raw === 'object' ? (raw as Record<string, unknown>) : {};
    const workers = Array.isArray(row.workers)
        ? row.workers
              .map((w, i) => sanitizeWorker(w, i))
              .filter((w): w is WorkerRelayEntry => Boolean(w))
        : [];

    return {
        enabled: row.enabled !== false,
        preferWorker: row.preferWorker !== false,
        cacheTtlSeconds: clampTtl(row.cacheTtlSeconds),
        workers
    };
}

function envFallbackConfig(): WorkerRelayConfig {
    const url = (
        process.env.YORU_RELAY_URL ||
        process.env.VAPLAYER_RELAY_URL ||
        ''
    )
        .trim()
        .replace(/\/$/, '');
    const secret = (
        process.env.YORU_RELAY_SECRET ||
        process.env.VAPLAYER_RELAY_SECRET ||
        ''
    ).trim();
    const preferWorker =
        (process.env.VAPLAYER_PREFER_WORKER || '1').toLowerCase() !== '0';
    const workers =
        url && secret
            ? [
                  {
                      id: 'env-primary',
                      label: 'Env primary',
                      url,
                      secret,
                      enabled: true
                  }
              ]
            : [];

    return {
        enabled: workers.length > 0,
        preferWorker,
        cacheTtlSeconds: clampTtl(
            process.env.VAPLAYER_RESOLVE_CACHE_TTL_SECONDS || 7200
        ),
        workers
    };
}

export function getWorkerRelayConfigPath() {
    return CONFIG_PATH;
}

export function readWorkerRelayConfig(force = false): WorkerRelayConfig {
    const now = Date.now();
    try {
        if (!existsSync(CONFIG_PATH)) {
            return envFallbackConfig();
        }
        const st = statSync(CONFIG_PATH);
        if (
            !force &&
            fileCache &&
            fileCache.mtimeMs === st.mtimeMs &&
            now - fileCache.loadedAt < 5_000
        ) {
            return fileCache.config;
        }
        const parsed = JSON.parse(readFileSync(CONFIG_PATH, 'utf8'));
        const config = sanitizeWorkerRelayConfig(parsed);
        // If file has empty workers/secrets, merge env primary as fallback.
        if (!config.workers.length) {
            const envCfg = envFallbackConfig();
            config.workers = envCfg.workers;
            if (!config.enabled && envCfg.workers.length) config.enabled = true;
        } else {
            // Fill blank secrets from env for the first worker (migration helper).
            const envSecret = (
                process.env.YORU_RELAY_SECRET ||
                process.env.VAPLAYER_RELAY_SECRET ||
                ''
            ).trim();
            if (envSecret) {
                for (const w of config.workers) {
                    if (!w.secret) w.secret = envSecret;
                }
            }
        }
        fileCache = { mtimeMs: st.mtimeMs, config, loadedAt: now };
        return config;
    } catch {
        return envFallbackConfig();
    }
}

export function writeWorkerRelayConfig(raw: unknown): WorkerRelayConfig {
    const config = sanitizeWorkerRelayConfig(raw);
    mkdirSync(dirname(CONFIG_PATH), { recursive: true });
    writeFileSync(CONFIG_PATH, JSON.stringify(config, null, 2) + '\n', 'utf8');
    fileCache = {
        mtimeMs: statSync(CONFIG_PATH).mtimeMs,
        config,
        loadedAt: Date.now()
    };
    return config;
}

export function listEnabledWorkers(config = readWorkerRelayConfig()): WorkerRelayEntry[] {
    if (!config.enabled) return [];
    return config.workers.filter((w) => w.enabled && w.url && w.secret);
}

/** Round-robin across enabled workers. */
export function pickWorker(
    config = readWorkerRelayConfig()
): WorkerRelayEntry | null {
    const enabled = listEnabledWorkers(config);
    if (!enabled.length) return null;
    rrIndex = (rrIndex + 1) % enabled.length;
    return enabled[rrIndex] ?? enabled[0] ?? null;
}

// ---- in-memory resolve cache (cinepro process) ----

type ResolveCacheEntry = {
    expiresAt: number;
    payload: unknown;
};

const resolveCache = new Map<string, ResolveCacheEntry>();

export function vaplayerCacheKey(parts: {
    type: string;
    tmdbId?: string;
    imdbId?: string;
    season?: number | string;
    episode?: number | string;
}) {
    const type = parts.type === 'tv' ? 'tv' : 'movie';
    if (type === 'tv') {
        return `vap:${type}:${parts.tmdbId || ''}:${parts.imdbId || ''}:s${parts.season ?? 1}:e${parts.episode ?? 1}`;
    }
    return `vap:${type}:${parts.tmdbId || ''}:${parts.imdbId || ''}`;
}

/** Same TTL pool as VAPlayer (admin Worker relay cache hours). */
export function cineplayCacheKey(parts: {
    type: string;
    tmdbId?: string;
    season?: number | string;
    episode?: number | string;
}) {
    const type = parts.type === 'tv' ? 'tv' : 'movie';
    if (type === 'tv') {
        return `cineplay:${type}:${parts.tmdbId || ''}:s${parts.season ?? 1}:e${parts.episode ?? 1}`;
    }
    return `cineplay:${type}:${parts.tmdbId || ''}`;
}

export function getCachedVaplayerResolve<T>(key: string): T | null {
    const hit = resolveCache.get(key);
    if (!hit) return null;
    if (hit.expiresAt <= Date.now()) {
        resolveCache.delete(key);
        return null;
    }
    return hit.payload as T;
}

export function setCachedVaplayerResolve(
    key: string,
    payload: unknown,
    ttlSeconds?: number
) {
    const ttl = clampTtl(
        ttlSeconds ?? readWorkerRelayConfig().cacheTtlSeconds
    );
    resolveCache.set(key, {
        expiresAt: Date.now() + ttl * 1000,
        payload
    });
    // Prevent unbounded growth
    if (resolveCache.size > 5_000) {
        const first = resolveCache.keys().next().value;
        if (first) resolveCache.delete(first);
    }
}

export function clearVaplayerResolveCache() {
    resolveCache.clear();
}
