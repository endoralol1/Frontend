#!/usr/bin/env python3
"""Fix cinepro Videasy → Yoru/cdn (speedracelight + seed) for 4K streams."""
from pathlib import Path

# --- patch videasy.ts ---
p = Path("/var/www/cinepro/src/providers/videasy/videasy.ts")
t = p.read_text(encoding="utf-8")

old_api = "const VIDEASY_API = 'https://api.videasy.to';"
new_api = "const VIDEASY_API = 'https://api.speedracelight.com';"
if old_api not in t:
    raise SystemExit("VIDEASY_API constant missing")
t = t.replace(old_api, new_api, 1)

old_servers = """const VIDEASY_SERVERS: readonly VideasyServer[] = [
    {
        name: 'mb-flix',
        url: `${VIDEASY_API}/mb-flix/sources-with-title`,
        language: 'english'
    },
    {
        name: 'cdn',
        url: `${VIDEASY_API}/cdn/sources-with-title`,
        language: 'english'
    },
    {
        name: 'downloader2',
        url: `${VIDEASY_API}/downloader2/sources-with-title`,
        language: 'english'
    },
    {
        name: 'cuevana',
        url: `${VIDEASY_API}/cuevana/sources-with-title`,
        language: 'english'
    },
    {
        name: 'lamovie',
        url: `${VIDEASY_API}/lamovie/sources-with-title`,
        language: 'english'
    }
] as const;
"""
new_servers = """const VIDEASY_SERVERS: readonly VideasyServer[] = [
    // Yoru CDN — movies often include 2160p on moon.ironwallnet.net
    {
        name: 'cdn',
        url: `${VIDEASY_API}/cdn/sources-with-title`,
        language: 'english'
    },
    {
        name: 'mb-flix',
        url: `${VIDEASY_API}/mb-flix/sources-with-title`,
        language: 'english'
    },
    {
        name: 'm4uhd',
        url: `${VIDEASY_API}/m4uhd/sources-with-title`,
        language: 'english'
    }
] as const;
"""
if old_servers not in t:
    raise SystemExit("VIDEASY_SERVERS block missing")
t = t.replace(old_servers, new_servers, 1)

old_fetch = """    // fetches one server, reads plain text blob, decrypts via enc-dec.app
    private async fetchFromServer(
        server: VideasyServer,
        media: ProviderMediaObject
    ): Promise<ProviderResult | null> {
        const url = this.buildRequestUrl(server, media);
        const response = await fetch(url, { headers: this.HEADERS });

        if (!response.ok) {
            return null;
        }

        // api returns plain text hex blob, not json
        const blob = await response.text();

        if (!blob || blob.length < 10) {
            return null;
        }

        const decrypted = await decryptResponse(blob, String(media.tmdbId));
"""
new_fetch = """    // fetches one server, reads plain text blob, decrypts via enc-dec.app
    private async fetchFromServer(
        server: VideasyServer,
        media: ProviderMediaObject
    ): Promise<ProviderResult | null> {
        const seed = await this.fetchSeed(String(media.tmdbId));
        if (!seed) {
            return null;
        }
        const url = this.buildRequestUrl(server, media, seed);
        const response = await fetch(url, { headers: this.HEADERS });

        if (!response.ok) {
            return null;
        }

        // api returns plain text hex/blob, not json
        const blob = await response.text();

        if (!blob || blob.length < 10) {
            return null;
        }

        const decrypted = await decryptResponse(blob, String(media.tmdbId), seed);
"""
if old_fetch not in t:
    raise SystemExit("fetchFromServer block missing")
t = t.replace(old_fetch, new_fetch, 1)

old_build = """    // Videasy requires double-encoded title (Fight Club → Fight%2520Club).
    private buildRequestUrl(
        server: VideasyServer,
        media: ProviderMediaObject
    ): string {
        const title = encodeURIComponent(
            encodeURIComponent(media.title ?? '')
        );
        const params = new URLSearchParams({
            mediaType: media.type === 'movie' ? 'movie' : 'tv',
            tmdbId: String(media.tmdbId),
            imdbId: media.imdbId ?? '',
            episodeId: String(media.type === 'tv' ? (media.e ?? 1) : 1),
            seasonId: String(media.type === 'tv' ? (media.s ?? 1) : 1)
        });

        if (media.type === 'movie') {
            params.set('year', String(media.releaseYear ?? ''));
        }

        if (server.language) {
            params.set('language', server.language);
        }

        return `${server.url}?title=${title}&${params.toString()}`;
    }
"""
new_build = """    // Current Videasy/Yoru API (speedracelight) needs short-lived seed + enc=2.
    // Minimal query works: mediaType + tmdbId + enc + seed (cdn/Yoru).
    private async fetchSeed(tmdbId: string): Promise<string | null> {
        try {
            const res = await fetch(
                `${VIDEASY_API}/seed?mediaId=${encodeURIComponent(tmdbId)}`,
                { headers: this.HEADERS }
            );
            if (!res.ok) return null;
            const json = (await res.json()) as { seed?: string };
            return typeof json.seed === 'string' && json.seed ? json.seed : null;
        } catch {
            return null;
        }
    }

    private buildRequestUrl(
        server: VideasyServer,
        media: ProviderMediaObject,
        seed: string
    ): string {
        const params = new URLSearchParams({
            mediaType: media.type === 'movie' ? 'movie' : 'tv',
            tmdbId: String(media.tmdbId),
            enc: '2',
            seed
        });

        if (media.type === 'tv') {
            params.set('seasonId', String(media.s ?? 1));
            params.set('episodeId', String(media.e ?? 1));
        }

        // Optional extras help some non-cdn servers
        if (media.imdbId) params.set('imdbId', media.imdbId);
        if (media.title) {
            params.set(
                'title',
                encodeURIComponent(encodeURIComponent(media.title))
            );
        }
        if (media.type === 'movie' && media.releaseYear) {
            params.set('year', String(media.releaseYear));
        }
        if (server.language) {
            params.set('language', server.language);
        }

        return `${server.url}?${params.toString()}`;
    }
"""
if old_build not in t:
    raise SystemExit("buildRequestUrl block missing")
t = t.replace(old_build, new_build, 1)

t = t.replace(
    "all videasy servers returned no sources (api.videasy.to; VPS/datacenter IPs often get HTTP 403)",
    "all videasy servers returned no sources (Yoru/cdn via speedracelight)",
)

p.write_text(t, encoding="utf-8")
print("patched videasy.ts")

# --- decryptor: pass optional seed ---
d = Path("/var/www/cinepro/src/providers/videasy/decryptor.ts")
dt = d.read_text(encoding="utf-8")
old_dec = """export async function decryptResponse(
    blob: string,
    tmdbId: string
): Promise<DecryptedPayload | null> {
    if (!blob || blob.length < 10) return null;

    const key = blobKey(tmdbId, blob);
    if (cache.has(key)) return cache.get(key)!;

    try {
        const res = await fetch(DEC_API, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ text: blob, id: tmdbId })
        });
"""
new_dec = """export async function decryptResponse(
    blob: string,
    tmdbId: string,
    seed?: string
): Promise<DecryptedPayload | null> {
    if (!blob || blob.length < 10) return null;

    const key = blobKey(tmdbId, blob);
    if (cache.has(key)) return cache.get(key)!;

    try {
        const res = await fetch(DEC_API, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(
                seed
                    ? { text: blob, id: tmdbId, seed }
                    : { text: blob, id: tmdbId }
            )
        });
"""
if old_dec not in dt:
    raise SystemExit("decryptor signature missing")
d.write_text(dt.replace(old_dec, new_dec, 1), encoding="utf-8")
print("patched decryptor.ts")

# --- allowlist ---
env = Path("/var/www/cinepro/.env")
et = env.read_text(encoding="utf-8")
old_al = "CINEPRO_PROVIDER_ALLOWLIST=vidapiru,flixhqz,vaplayer,fsharetv,notorrent"
new_al = "CINEPRO_PROVIDER_ALLOWLIST=vidapiru,flixhqz,vaplayer,fsharetv,notorrent,videasy"
if old_al not in et:
    if "videasy" in et.lower() and "CINEPRO_PROVIDER_ALLOWLIST" in et:
        print("allowlist already mentions videasy")
    else:
        raise SystemExit("allowlist line missing")
else:
    env.write_text(et.replace(old_al, new_al, 1), encoding="utf-8")
    print("patched .env allowlist")

print("OK")
