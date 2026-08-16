/**
 * Cinejoy / shegu "lumen-gate-v2" client.
 * Requires Bun (crush.wasm uses stringref — Node cannot instantiate it).
 *
 * Usage:
 *   bun scripts/cinejoy-gate.mjs '{"type":"movie","tmdbId":"603","title":"The Matrix","year":"1999","imdbId":"tt0133093"}'
 */
import fs from 'fs';
import path from 'path';
import { webcrypto } from 'crypto';
import { fileURLToPath } from 'url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const API = process.env.CINEJOY_API || 'https://api.shegu.st';
const ORIGIN = process.env.CINEJOY_ORIGIN || 'https://cinejoy.to';
const WASM_PATH =
    process.env.CINEJOY_WASM || path.join(__dirname, '..', 'storage', 'crush.wasm');

const RAND_LEN = 44;
const KEY_LEN = 32;
const KEY_ID_SIZE = 1;
const EPH_LEN = 65;
const HEADER_LEN = KEY_LEN + KEY_ID_SIZE + EPH_LEN; // 98
const IV_LEN = 12;
const AAD_PREFIX = Buffer.from('lumen-gate-v2');
const GATE_VERSION = Buffer.from([0x00, 0x02]);

const headers = {
    Origin: ORIGIN,
    Referer: `${ORIGIN}/`,
    'User-Agent':
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/137.0.0.0 Safari/537.36'
};

async function loadWasm() {
    let buf;
    if (fs.existsSync(WASM_PATH)) {
        buf = fs.readFileSync(WASM_PATH);
    } else {
        const res = await fetch(`${API}/crush.wasm`, { headers });
        if (!res.ok) throw new Error(`cinejoy wasm HTTP ${res.status}`);
        buf = Buffer.from(await res.arrayBuffer());
        fs.mkdirSync(path.dirname(WASM_PATH), { recursive: true });
        fs.writeFileSync(WASM_PATH, buf);
    }
    const { instance } = await WebAssembly.instantiate(buf, {});
    return instance.exports;
}

function writeBytes(mem, ptr, bytes) {
    new Uint8Array(mem.buffer, ptr, bytes.length).set(bytes);
}

async function sealRequest(exp, reqPath, payload = null) {
    const plaintext = Buffer.from(JSON.stringify({ path: reqPath, payload }), 'utf8');
    const rand = Buffer.alloc(RAND_LEN);
    webcrypto.getRandomValues(rand);
    const outCap = plaintext.length + 512;
    const inPtr = exp.alloc(plaintext.length);
    const randPtr = exp.alloc(rand.length);
    const outPtr = exp.alloc(outCap);
    if (!inPtr || !randPtr || !outPtr) throw new Error('cinejoy: wasm alloc failed');
    writeBytes(exp.memory, inPtr, plaintext);
    writeBytes(exp.memory, randPtr, rand);
    const n = exp.seal_request(inPtr, plaintext.length, randPtr, rand.length, outPtr, outCap);
    if (!(n > 0) || n > outCap) throw new Error(`cinejoy: seal_request failed (${n})`);
    const sealed = Buffer.from(new Uint8Array(exp.memory.buffer, outPtr, n));
    return {
        responseKey: sealed.subarray(0, KEY_LEN),
        keyId: sealed[KEY_LEN],
        ephemeralPublic: sealed.subarray(KEY_LEN + KEY_ID_SIZE, HEADER_LEN),
        body: sealed.subarray(HEADER_LEN)
    };
}

function buildAad(keyId, ephemeralPublic) {
    return Buffer.concat([AAD_PREFIX, GATE_VERSION, Buffer.from([keyId]), ephemeralPublic]);
}

async function unseal(raw, sealed) {
    const iv = raw.subarray(0, IV_LEN);
    const ct = raw.subarray(IV_LEN);
    const key = await webcrypto.subtle.importKey(
        'raw',
        sealed.responseKey,
        { name: 'AES-GCM' },
        false,
        ['decrypt']
    );
    const aad = buildAad(sealed.keyId, sealed.ephemeralPublic);
    const pt = Buffer.from(
        await webcrypto.subtle.decrypt(
            { name: 'AES-GCM', iv, additionalData: aad, tagLength: 128 },
            key,
            ct
        )
    );
    return JSON.parse(pt.toString('utf8'));
}

async function gateFetch(exp, reqPath, payload = null) {
    const sealed = await sealRequest(exp, reqPath, payload);
    const res = await fetch(`${API}/g`, {
        method: 'POST',
        headers: {
            ...headers,
            'Content-Type': 'text/plain;charset=UTF-8'
        },
        body: sealed.body
    });
    if (!res.ok) throw new Error(`cinejoy gate HTTP ${res.status}`);
    const raw = Buffer.from(await res.arrayBuffer());
    return unseal(raw, sealed);
}

function qualityFromPlaylist(m3u) {
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

async function listServers() {
    const res = await fetch(`${API}/servers`, { headers });
    if (!res.ok) throw new Error(`cinejoy servers HTTP ${res.status}`);
    const data = await res.json();
    return (data.servers ?? [])
        .filter((s) => (s.status ?? 'ok') === 'ok' && s.name)
        .map((s) => String(s.name));
}

export async function resolveCinejoyPlaylist(opts) {
    const servers = await listServers();
    const preferred = ['Lisbon', 'Solara', 'Athens', 'Castle', 'Joy', 'Sakura', 'Canaias'];
    const ordered = [
        ...preferred.filter((n) => servers.includes(n)),
        ...servers.filter((n) => !preferred.includes(n))
    ];
    if (!ordered.length) throw new Error('cinejoy: no servers');

    const exp = await loadWasm();
    const title = encodeURIComponent(opts.title);
    const year = opts.year ? String(opts.year) : '';
    const imdb = opts.imdbId ? encodeURIComponent(opts.imdbId) : '';
    const tmdb = encodeURIComponent(opts.tmdbId);

    let lastErr;
    for (const server of ordered) {
        const reqPath =
            opts.type === 'tv'
                ? `/${server}/series?episode=${opts.episode ?? 1}&imdb=${imdb}&season=${opts.season ?? 1}&title=${title}&tmdb=${tmdb}&year=${year}`
                : `/${server}/movie?imdb=${imdb}&title=${title}&tmdb=${tmdb}&year=${year}`;
        try {
            const out = await gateFetch(exp, reqPath, null);
            const playlist = out?.data?.stream?.[0]?.playlist;
            if (!playlist) throw new Error('empty playlist');
            const m3uRes = await fetch(playlist, {
                headers: { Referer: `${ORIGIN}/`, Origin: ORIGIN }
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

const rawArg = process.argv[2];
if (import.meta.main || rawArg) {
    const opts = JSON.parse(rawArg || '{}');
    resolveCinejoyPlaylist(opts)
        .then((r) => {
            process.stdout.write(JSON.stringify({ ok: true, ...r }));
        })
        .catch((e) => {
            process.stdout.write(
                JSON.stringify({ ok: false, error: e instanceof Error ? e.message : String(e) })
            );
            process.exit(1);
        });
}
