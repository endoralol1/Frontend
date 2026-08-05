# Worker relay admin (2h cache + multi-Worker pool)

Shared config for **Chillflix** and **Vuflix**:

`/var/www/cinepro/config/worker-relay.json`

## What it controls

| Setting | Effect |
|---|---|
| `enabled` | Master switch for Cloudflare Worker egress |
| `preferWorker` | Try Worker before direct DC fetch |
| `cacheTtlSeconds` | Default **7200** (2 hours). Cinepro in-memory VAPlayer cache + Worker `cacheTtl` query |
| `workers[]` | Pool of `{id,label,url,secret,enabled}` — round-robin |

Admin UIs (same file):

- Chillflix → Stream Sources → **Worker relay**
- Vuflix → Admin → Sources → **Worker relay**

Saving syncs the first enabled worker into:

- `/var/www/cinepro/.env` (`YORU_RELAY_*`, `VAPLAYER_PREFER_WORKER`)
- `/var/www/chillflix-newsite/.env` (`YORU_RELAY_*` for Cineplay Yoru)

## Who uses the Worker

| Site / path | Uses Worker? |
|---|---|
| Chillflix VAPlayer (via cinepro) | Yes — pool + 2h cache |
| Vuflix VAPlayer (via Chillflix/cinepro) | Yes — same cinepro path |
| Vuflix Cineplay Yoru (PHP) | Yes — reads pool from JSON (falls back to `.env`) |
| VixSrc | **No** — removed from Worker to save free-tier quota |

## Second Cloudflare account / Worker

1. Cloudflare dashboard (second account) → **Workers & Pages** → Create
2. Paste `chillflix-patches/newsite/workers/yoru-relay.js` (same script as primary)
3. Settings → Variables → add encrypted **`YORU_RELAY_SECRET`**
   - Can match primary, or a new secret (put that secret in admin for this worker row)
4. Deploy → copy `https://<name>.<subdomain>.workers.dev`
5. In either admin UI: **Add worker** → paste URL + secret → **Test** → **Save**

No more hand-editing `.env` on every change — admin is the source of truth.

## Redeploy Worker script (TTL support)

Primary Worker still on free plan must be updated once so it honors `cacheTtl` (default 2h instead of 120s edge cache):

1. Open existing Worker → Edit code
2. Paste latest `yoru-relay.js`
3. Save & Deploy

Repeat on the second Worker.

## Notes

- Free-tier pressure comes from **resolve fan-out**, not video segments.
- Biggest quota win is the **2h cinepro in-memory cache** + fewer duplicate resolves.
- Multi free accounts can split load via round-robin; still fragile vs paid Worker.
