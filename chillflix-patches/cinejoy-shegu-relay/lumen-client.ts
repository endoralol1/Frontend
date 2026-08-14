import crypto from 'node:crypto';

import { relayAwareFetch } from '../../utils/worker-http.js';

const API = 'https://api.shegu.st';
const ORIGIN = 'https://cinejoy.to';
const MASTER = Buffer.from([
    78, 70, 186, 138, 152, 227, 144, 80, 143, 171, 27, 217, 32, 125, 21, 81, 107, 236, 17, 184, 160,
    178, 31, 143, 193, 225, 230, 108, 158, 149, 171, 239
]);
const INFO_C2S = Buffer.from('lumen-wire-v2|c2s');
const INFO_S2C = Buffer.from('lumen-wire-v2|s2c');

export type CinejoyStream = {
    type: string;
    id: string;
    playlist: string;
    captions?: unknown[];
};

type Challenge = {
    v: number;
    b: string;
    s: string;
    e: number;
    n: number;
    r: number;
    p: number;
    d: number;
    k: string;
    g: string;
};

function b64url(buf: Buffer): string {
    return buf
        .toString('base64')
        .replace(/\+/g, '-')
        .replace(/\//g, '_')
        .replace(/=+$/g, '');
}

function fromB64url(s: string): Buffer {
    let t = s.replace(/-/g, '+').replace(/_/g, '/');
    while (t.length % 4) t += '=';
    return Buffer.from(t, 'base64');
}

function hkdf(salt: Buffer, info: Buffer, length = 80): Buffer {
    return Buffer.from(crypto.hkdfSync('sha256', MASTER, salt, info, length));
}

function packC2S(plaintext: string): string {
    const salt = crypto.randomBytes(16);
    const material = hkdf(salt, INFO_C2S, 80);
    const gcmKey = material.subarray(0, 32);
    const ctrKey = material.subarray(32, 64);
    const ctrIV = material.subarray(64, 80);
    const iv = crypto.randomBytes(12);
    const cipher = crypto.createCipheriv('aes-256-gcm', gcmKey, iv);
    cipher.setAAD(INFO_C2S);
    const enc = Buffer.concat([
        cipher.update(Buffer.from(plaintext, 'utf8')),
        cipher.final(),
        cipher.getAuthTag()
    ]);
    const inner = Buffer.concat([Buffer.from([2]), iv, enc]);
    const ctr = crypto.createCipheriv('aes-256-ctr', ctrKey, ctrIV);
    const outer = Buffer.concat([ctr.update(inner), ctr.final()]);
    return b64url(Buffer.concat([salt, outer]));
}

function unpackS2C(token: string): string {
    const raw = fromB64url(token);
    const salt = raw.subarray(0, 16);
    const outer = raw.subarray(16);
    const material = hkdf(salt, INFO_S2C, 80);
    const gcmKey = material.subarray(0, 32);
    const ctrKey = material.subarray(32, 64);
    const ctrIV = material.subarray(64, 80);
    const ctr = crypto.createDecipheriv('aes-256-ctr', ctrKey, ctrIV);
    const inner = Buffer.concat([ctr.update(outer), ctr.final()]);
    if (inner[0] !== 2) throw new Error(`cinejoy: bad wire version ${inner[0]}`);
    const iv = inner.subarray(1, 13);
    const gcmData = inner.subarray(13);
    const tag = gcmData.subarray(gcmData.length - 16);
    const enc = gcmData.subarray(0, gcmData.length - 16);
    const decipher = crypto.createDecipheriv('aes-256-gcm', gcmKey, iv);
    decipher.setAAD(INFO_S2C);
    decipher.setAuthTag(tag);
    return Buffer.concat([decipher.update(enc), decipher.final()]).toString('utf8');
}

function leadingZeroBits(buf: Buffer): number {
    let z = 0;
    for (const byte of buf) {
        if (byte === 0) {
            z += 8;
            continue;
        }
        for (let i = 7; i >= 0; i--) {
            if ((byte >> i) & 1) break;
            z++;
        }
        break;
    }
    return z;
}

function solvePow(ch: Challenge): number {
    const salt = crypto.createHash('sha256').update(`pow2-salt|${ch.s}|${ch.b}`).digest();
    for (let c = 0; c < 2_000_000; c++) {
        const out = crypto.scryptSync(`pow2|${ch.b}|${ch.s}|${c}`, salt, 32, {
            N: ch.n,
            r: ch.r,
            p: ch.p,
            maxmem: 256 * 1024 * 1024
        });
        if (leadingZeroBits(out) >= ch.d) return c;
    }
    throw new Error('cinejoy: pow failed');
}

async function listServers(): Promise<string[]> {
    const res = await relayAwareFetch(`${API}/servers`, {
        headers: { Origin: ORIGIN, Referer: `${ORIGIN}/` },
        signal: AbortSignal.timeout(10_000)
    });
    if (!res.ok) throw new Error(`cinejoy servers HTTP ${res.status}`);
    const data = (await res.json()) as { servers?: Array<{ name?: string; status?: string }> };
    return (data.servers ?? [])
        .filter((s) => (s.status ?? 'ok') === 'ok' && s.name)
        .map((s) => String(s.name));
}

async function fetchPath(path: string): Promise<{ stream: CinejoyStream[] }> {
    const rid = packC2S(path);
    const chRes = await relayAwareFetch(`${API}/challenge?rid=${rid}`, {
        headers: { Origin: ORIGIN, Referer: `${ORIGIN}/` },
        signal: AbortSignal.timeout(15_000)
    });
    if (!chRes.ok) throw new Error(`cinejoy challenge HTTP ${chRes.status}`);
    const ch = (await chRes.json()) as Challenge;
    const c = solvePow(ch);
    const at = Buffer.from(
        JSON.stringify({
            v: ch.v,
            b: ch.b,
            s: ch.s,
            e: ch.e,
            n: ch.n,
            r: ch.r,
            p: ch.p,
            d: ch.d,
            k: ch.k,
            g: ch.g,
            c
        })
    ).toString('base64');

    const res = await relayAwareFetch(`${API}/${rid}`, {
        headers: {
            'X-At': at,
            Origin: ORIGIN,
            Referer: `${ORIGIN}/`,
            'User-Agent':
                'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/137.0.0.0 Safari/537.36'
        },
        signal: AbortSignal.timeout(20_000)
    });
    const text = await res.text();
    if (!res.ok) {
        throw new Error(`cinejoy stream HTTP ${res.status}: ${text.slice(0, 120)}`);
    }
    return JSON.parse(unpackS2C(text)) as { stream: CinejoyStream[] };
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

export async function resolveCinejoyPlaylist(opts: {
    type: 'movie' | 'tv';
    tmdbId: string;
    imdbId?: string;
    title: string;
    year?: string | number;
    season?: number;
    episode?: number;
}): Promise<{ playlist: string; quality: string; server: string }> {
    const servers = await listServers();
    // Prefer Lisbon (stable US) then the rest — still surfaces 4K when a server has it.
    const preferred = ['Lisbon', 'Solara', 'Athens', 'Castle', 'Joy', 'Sakura', 'Canaias'];
    const ordered = [
        ...preferred.filter((n) => servers.includes(n)),
        ...servers.filter((n) => !preferred.includes(n))
    ];
    if (!ordered.length) throw new Error('cinejoy: no servers');

    const title = encodeURIComponent(opts.title);
    const year = opts.year ? String(opts.year) : '';
    const imdb = opts.imdbId ? encodeURIComponent(opts.imdbId) : '';
    const tmdb = encodeURIComponent(opts.tmdbId);

    let lastErr: unknown;
    for (const server of ordered) {
        const path =
            opts.type === 'tv'
                ? `/${server}/series?episode=${opts.episode ?? 1}&imdb=${imdb}&season=${opts.season ?? 1}&title=${title}&tmdb=${tmdb}&year=${year}`
                : `/${server}/movie?imdb=${imdb}&title=${title}&tmdb=${tmdb}&year=${year}`;
        try {
            const data = await fetchPath(path);
            const playlist = data.stream?.[0]?.playlist;
            if (!playlist) throw new Error('empty playlist');
            const m3uRes = await relayAwareFetch(playlist, {
                headers: { Referer: `${ORIGIN}/`, Origin: ORIGIN },
                signal: AbortSignal.timeout(15_000)
            });
            if (!m3uRes.ok) throw new Error(`playlist HTTP ${m3uRes.status}`);
            const m3u = await m3uRes.text();
            const { quality } = qualityFromPlaylist(m3u);
            return { playlist, quality, server };
        } catch (error) {
            lastErr = error;
        }
    }
    throw lastErr instanceof Error ? lastErr : new Error('cinejoy: all servers failed');
}
