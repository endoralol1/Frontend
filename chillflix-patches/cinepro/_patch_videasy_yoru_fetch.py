#!/usr/bin/env python3
"""Harden Videasy Yoru fetch: shared provider-fetch helper + seed cache/retry."""
from pathlib import Path

p = Path("/var/www/cinepro/src/providers/videasy/videasy.ts")
t = p.read_text(encoding="utf-8")

if "from '../../utils/provider-fetch.js'" not in t:
    t = t.replace(
        "import { decryptResponse } from './decryptor.js';\n",
        "import { decryptResponse } from './decryptor.js';\n"
        "import { fetchText } from '../../utils/provider-fetch.js';\n",
        1,
    )

old_seed = """    private async fetchSeed(tmdbId: string): Promise<string | null> {
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
"""

new_seed = """    private seedCache = new Map<string, { seed: string; exp: number }>();

    private async fetchSeed(tmdbId: string): Promise<string | null> {
        const hit = this.seedCache.get(tmdbId);
        if (hit && hit.exp > Date.now()) {
            return hit.seed;
        }

        for (let attempt = 0; attempt < 3; attempt++) {
            try {
                // Prefer residential/outbound proxy when configured (CF rate-limits datacenter IPs).
                const text = await fetchText(
                    `${VIDEASY_API}/seed?mediaId=${encodeURIComponent(tmdbId)}`,
                    this.HEADERS,
                    { useProxy: true }
                );
                if (!text || text.trim().startsWith('<')) {
                    // fallback direct once proxy missing/failed
                    const res = await fetch(
                        `${VIDEASY_API}/seed?mediaId=${encodeURIComponent(tmdbId)}`,
                        { headers: this.HEADERS }
                    );
                    if (!res.ok) {
                        await new Promise((r) => setTimeout(r, 400 * (attempt + 1)));
                        continue;
                    }
                    const json = (await res.json()) as { seed?: string; ttlMs?: number };
                    if (typeof json.seed === 'string' && json.seed) {
                        this.seedCache.set(tmdbId, {
                            seed: json.seed,
                            exp: Date.now() + Math.min(Number(json.ttlMs ?? 25000), 25000)
                        });
                        return json.seed;
                    }
                    continue;
                }
                const json = JSON.parse(text) as { seed?: string; ttlMs?: number };
                if (typeof json.seed === 'string' && json.seed) {
                    this.seedCache.set(tmdbId, {
                        seed: json.seed,
                        exp: Date.now() + Math.min(Number(json.ttlMs ?? 25000), 25000)
                    });
                    return json.seed;
                }
            } catch {
                await new Promise((r) => setTimeout(r, 400 * (attempt + 1)));
            }
        }
        return null;
    }
"""

if old_seed not in t:
    raise SystemExit("fetchSeed block missing for harden patch")
t = t.replace(old_seed, new_seed, 1)

# use proxy for source blob fetch too
old_blob = """        const url = this.buildRequestUrl(server, media, seed);
        const response = await fetch(url, { headers: this.HEADERS });

        if (!response.ok) {
            return null;
        }

        // api returns plain text hex/blob, not json
        const blob = await response.text();
"""
new_blob = """        const url = this.buildRequestUrl(server, media, seed);
        let blob =
            (await fetchText(url, this.HEADERS, { useProxy: true })) ||
            null;
        if (!blob) {
            const response = await fetch(url, { headers: this.HEADERS });
            if (!response.ok) {
                return null;
            }
            blob = await response.text();
        }
"""
if old_blob not in t:
    raise SystemExit("blob fetch block missing")
t = t.replace(old_blob, new_blob, 1)

p.write_text(t, encoding="utf-8")
print("hardened videasy.ts")

# newsite catalog
ss = Path("/var/www/chillflix-newsite/app/Services/SourcesService.php")
st = ss.read_text(encoding="utf-8")
if "'videasy'" not in st:
    if "'stremify' => 'Stremify'" in st:
        st = st.replace(
            "        'vidrock' => 'VidRock',\n        'stremify' => 'Stremify',\n",
            "        'vidrock' => 'VidRock',\n        'videasy' => 'Videasy',\n        'stremify' => 'Stremify',\n",
            1,
        )
    else:
        st = st.replace(
            "        'vidrock' => 'VidRock',\n    ];",
            "        'vidrock' => 'VidRock',\n        'videasy' => 'Videasy',\n    ];",
            1,
        )
    if "'videasy'" not in st:
        raise SystemExit("failed to add videasy catalog")
    ss.write_text(st, encoding="utf-8")
    print("newsite catalog +videasy")
else:
    print("newsite catalog already has videasy")

# pm2 ecosystem
eco = Path("/var/www/chillflix.lol/infra/pm2/ecosystem.config.cjs")
if eco.exists():
    et = eco.read_text(encoding="utf-8")
    old = """            name: \"cinepro\",
            cwd: \"/var/www/cinepro\",
            script: \"npm\",
            args: \"start\",
            env: {
                NODE_ENV: \"production\",
                PORT: 3001,
            },
"""
    new = """            name: \"cinepro\",
            cwd: \"/var/www/cinepro\",
            script: \"dist/server.js\",
            env: {
                NODE_ENV: \"production\",
                PORT: 3001,
                HOST: \"127.0.0.1\",
                CINEPRO_PROVIDER_ALLOWLIST:
                    \"vidapiru,flixhqz,vaplayer,fsharetv,notorrent,videasy\",
            },
"""
    if old in et:
        eco.write_text(et.replace(old, new, 1), encoding="utf-8")
        print("ecosystem patched")
    else:
        print("ecosystem already different / patched")

# chillflix.lol env allowlist if present
envl = Path("/var/www/chillflix.lol/.env")
if envl.exists():
    e = envl.read_text(encoding="utf-8")
    line = "CINEPRO_PROVIDER_ALLOWLIST=vidapiru,flixhqz,vaplayer,fsharetv,notorrent"
    if line + ",videasy" not in e and line in e:
        envl.write_text(e.replace(line, line + ",videasy", 1), encoding="utf-8")
        print("chillflix.lol .env allowlist patched")

print("OK")
