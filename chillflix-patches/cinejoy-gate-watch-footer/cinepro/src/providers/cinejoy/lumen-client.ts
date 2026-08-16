import { spawn } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const BUN_BIN = process.env.BUN_PATH || 'bun';
const GATE_TIMEOUT_MS = 45_000;

function resolveGateScript(): string {
    const candidates = [
        process.env.CINEJOY_GATE_SCRIPT,
        path.resolve(__dirname, '../../../scripts/cinejoy-gate.mjs'),
        path.resolve(process.cwd(), 'scripts/cinejoy-gate.mjs'),
        '/var/www/cinepro/scripts/cinejoy-gate.mjs'
    ].filter(Boolean) as string[];
    for (const candidate of candidates) {
        if (fs.existsSync(candidate)) return candidate;
    }
    return candidates[0] || path.resolve(__dirname, '../../../scripts/cinejoy-gate.mjs');
}

function whichBun(): string {
    if (process.env.BUN_PATH && fs.existsSync(process.env.BUN_PATH)) {
        return process.env.BUN_PATH;
    }
    for (const candidate of ['/usr/local/bin/bun', '/root/.bun/bin/bun', 'bun']) {
        if (candidate === 'bun') return candidate;
        if (fs.existsSync(candidate)) return candidate;
    }
    return BUN_BIN;
}

function runBunGate(opts: Record<string, unknown>): Promise<{
    ok: boolean;
    playlist?: string;
    quality?: string;
    server?: string;
    error?: string;
}> {
    return new Promise((resolve, reject) => {
        const gateScript = resolveGateScript();
        if (!fs.existsSync(gateScript)) {
            reject(new Error(`cinejoy gate script missing: ${gateScript}`));
            return;
        }
        const bun = whichBun();
        const child = spawn(bun, [gateScript, JSON.stringify(opts)], {
            stdio: ['ignore', 'pipe', 'pipe'],
            env: {
                ...process.env,
                CINEJOY_API: process.env.CINEJOY_API || 'https://api.shegu.st',
                CINEJOY_ORIGIN: process.env.CINEJOY_ORIGIN || 'https://cinejoy.to'
            }
        });

        let stdout = '';
        let stderr = '';
        const timer = setTimeout(() => {
            child.kill('SIGKILL');
            reject(new Error('cinejoy: gate timeout'));
        }, GATE_TIMEOUT_MS);

        child.stdout.on('data', (chunk) => {
            stdout += String(chunk);
        });
        child.stderr.on('data', (chunk) => {
            stderr += String(chunk);
        });
        child.on('error', (err) => {
            clearTimeout(timer);
            reject(err);
        });
        child.on('close', (code) => {
            clearTimeout(timer);
            const text = stdout.trim();
            if (!text) {
                reject(
                    new Error(
                        stderr.trim() ||
                            `cinejoy gate exited ${code ?? 'unknown'} with empty output`
                    )
                );
                return;
            }
            try {
                resolve(JSON.parse(text));
            } catch {
                reject(new Error(`cinejoy gate bad JSON: ${text.slice(0, 180)}`));
            }
        });
    });
}

export function qualityFromPlaylist(m3u: string): { quality: string; maxHeight: number } {
    let maxH = 0;
    for (const m of m3u.matchAll(/RESOLUTION=\d+x(\d+)/gi)) {
        const h = Number(m[1]);
        if (Number.isFinite(h) && h > maxH) maxH = h;
    }
    if (maxH >= 2160) return { quality: '4K', maxHeight: maxH };
    if (maxH >= 1440) return { quality: '1440p', maxHeight: maxH };
    if (maxH >= 1080) return { quality: '1080p', maxHeight: maxH };
    if (maxH >= 720) return { quality: '720p', maxHeight: maxH };
    if (maxH > 0) return { quality: `${maxH}p`, maxHeight: maxH };
    return { quality: '1080p', maxHeight: 1080 };
}

/**
 * Resolve a Cinejoy HLS playlist via shegu lumen-gate-v2 (crush.wasm + POST /g).
 * The legacy /challenge PoW endpoint was removed upstream (HTTP 404).
 */
export async function resolveCinejoyPlaylist(opts: {
    type: 'movie' | 'tv';
    tmdbId: string;
    imdbId?: string;
    title: string;
    year?: string | number;
    season?: number;
    episode?: number;
}): Promise<{ playlist: string; quality: string; server: string }> {
    const result = await runBunGate({
        type: opts.type,
        tmdbId: String(opts.tmdbId),
        imdbId: opts.imdbId ? String(opts.imdbId) : undefined,
        title: String(opts.title),
        year: opts.year != null ? String(opts.year) : undefined,
        season: opts.season,
        episode: opts.episode
    });

    if (!result.ok || !result.playlist) {
        throw new Error(result.error || 'cinejoy: no playlist');
    }

    return {
        playlist: result.playlist,
        quality: result.quality || '1080p',
        server: result.server || 'Lisbon'
    };
}
