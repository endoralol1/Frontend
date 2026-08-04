import { spawn } from 'node:child_process';
import type { ProviderMediaObject } from '@omss/framework';

const PHP_HELPER =
    process.env.HIMOVIES_PHP_HELPER ||
    '/var/www/chillflix-newsite/bin/fetch-local-provider.php';
const PHP_BIN = process.env.PHP_BIN || 'php';

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
                            JSON.parse(trimmed.slice(jsonStart, jsonEnd + 1)) as HimoviesPhpResult
                        );
                        return;
                    } catch {
                        /* fall through */
                    }
                }
                resolve({ ok: false, error: `${provider} helper bad JSON`, sources: [] });
            }
        });
    });
}

/**
 * Resolve UpCloud / Vidmoly via the Vuflix PHP scrapers on this VPS.
 * UpCloud/Byse PoW is flaky — retry a few times before giving up.
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

    const attempts = provider === 'upcloud' ? 2 : 1;
    let last: HimoviesPhpResult = { ok: false, error: 'unknown', sources: [] };
    for (let i = 0; i < attempts; i++) {
        last = await runPhpOnce(provider, media, timeoutMs);
        if (last.ok && (last.sources?.length ?? 0) > 0) {
            return last;
        }
        const err = String(last.error || '');
        const retriable =
            provider === 'upcloud' &&
            (/PoW|captcha|timeout|wrappers failed|challenge/i.test(err) ||
                !(last.sources?.length));
        if (!retriable) break;
    }
    return last;
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
