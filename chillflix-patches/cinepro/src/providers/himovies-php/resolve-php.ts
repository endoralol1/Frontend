import { spawn } from 'node:child_process';
import type { ProviderMediaObject } from '@omss/framework';

const PHP_HELPER =
    process.env.HIMOVIES_PHP_HELPER ||
    '/var/www/chillflix-newsite/bin/fetch-local-provider.php';
const PHP_BIN = process.env.PHP_BIN || 'php';

/** Cap concurrent UpCloud/Vidmoly PHP helpers — each can spawn heavy byse-resolve.mjs. */
const MAX_CONCURRENT = Math.max(
    1,
    Number(process.env.HIMOVIES_PHP_MAX_CONCURRENT ?? 1)
);

let active = 0;
const waitQueue: Array<() => void> = [];

async function withConcurrencyLimit<T>(fn: () => Promise<T>): Promise<T> {
    if (active >= MAX_CONCURRENT) {
        await new Promise<void>((resolve) => {
            waitQueue.push(resolve);
        });
    }
    active += 1;
    try {
        return await fn();
    } finally {
        active -= 1;
        const next = waitQueue.shift();
        if (next) next();
    }
}

export type HimoviesPhpSource = {
    url?: string;
    type?: string;
    quality?: string;
    meta?: {
        direct?: string;
        embed?: string;
        [key: string]: unknown;
    };
};

export type HimoviesPhpResult = {
    ok?: boolean;
    error?: string;
    sources?: HimoviesPhpSource[];
};

function runPhpOnce(
    provider: 'upcloud' | 'vidmoly',
    media: ProviderMediaObject,
    timeoutMs: number
): Promise<HimoviesPhpResult> {
    const type = media.type === 'tv' ? 'tv' : 'movie';
    const tmdbId = String(media.tmdbId ?? '');
    const args = [
        PHP_HELPER,
        provider,
        type,
        tmdbId,
        String(media.s ?? 1),
        String(media.e ?? 1)
    ];

    return new Promise((resolve) => {
        const child = spawn(PHP_BIN, args, {
            env: {
                ...process.env,
                HTTP_HOST: 'vuflix.co',
                HTTPS: 'on'
            },
            stdio: ['ignore', 'pipe', 'pipe']
        });

        let stdout = '';
        let stderr = '';
        const timer = setTimeout(() => {
            try {
                child.kill('SIGKILL');
            } catch {
                /* ignore */
            }
            resolve({ ok: false, error: `${provider} helper timeout`, sources: [] });
        }, timeoutMs);

        child.stdout.on('data', (chunk) => {
            stdout += String(chunk);
        });
        child.stderr.on('data', (chunk) => {
            stderr += String(chunk);
        });
        child.on('error', (err) => {
            clearTimeout(timer);
            resolve({
                ok: false,
                error: err.message || 'failed to spawn php helper',
                sources: []
            });
        });
        child.on('close', () => {
            clearTimeout(timer);
            const trimmed = stdout.trim();
            if (!trimmed) {
                resolve({
                    ok: false,
                    error:
                        stderr.trim().slice(0, 200) ||
                        `${provider} helper returned no JSON`,
                    sources: []
                });
                return;
            }
            try {
                resolve(JSON.parse(trimmed) as HimoviesPhpResult);
            } catch {
                const jsonStart = trimmed.indexOf('{');
                const jsonEnd = trimmed.lastIndexOf('}');
                if (jsonStart >= 0 && jsonEnd > jsonStart) {
                    try {
                        resolve(
                            JSON.parse(
                                trimmed.slice(jsonStart, jsonEnd + 1)
                            ) as HimoviesPhpResult
                        );
                        return;
                    } catch {
                        /* fall through */
                    }
                }
                resolve({
                    ok: false,
                    error: `${provider} helper bad JSON`,
                    sources: []
                });
            }
        });
    });
}

/**
 * Resolve UpCloud / Vidmoly via the Vuflix PHP scrapers on this VPS.
 * Concurrency-limited so Byse PoW workers cannot melt the host (502s).
 */
export async function resolveHimoviesPhp(
    provider: 'upcloud' | 'vidmoly',
    media: ProviderMediaObject,
    timeoutMs = 20000
): Promise<HimoviesPhpResult> {
    const tmdbId = String(media.tmdbId ?? '');
    if (!tmdbId || tmdbId === '0') {
        return { ok: false, error: 'Missing tmdbId', sources: [] };
    }

    return withConcurrencyLimit(async () => {
        // Single attempt — retries under load spawn more byse-resolve storms.
        return runPhpOnce(provider, media, timeoutMs);
    });
}

export function pickDirectUrl(source: HimoviesPhpSource): string {
    const direct = source.meta?.direct;
    if (typeof direct === 'string' && /^https?:\/\//i.test(direct)) {
        return direct;
    }
    const url = source.url;
    if (
        typeof url === 'string' &&
        /^https?:\/\//i.test(url) &&
        !url.includes('/api/player/media-proxy')
    ) {
        return url;
    }
    return '';
}
