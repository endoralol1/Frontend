# Chillflix patches (VPS `/var/www/chillflix.lol`)

## First-party Monetag aclib (adblock bypass test)

- Site player uses Mapple-style first-party `/api/rum` + `aclib.runPop({ zoneId })`.
- Avoids EasyList blocks on `llvpn.com` / `acscdn.com` / `*/aclib.js` path rules.
- Live fast path (no full rebuild): `public/cdn/rum.js` + nginx `location = /api/rum` (see `scripts/nginx-api-rum.conf`) + hot-patch `.next` inject/config chunks.
- Source also includes `app/api/rum/route.ts` for a proper Next proxy after a successful candidate build.
- Embed surface still uses existing monetag-antiblock + jnbhi path.

Deploy: keep `chillflix` online during builds; only stop `cinepro` for RAM. Prefer `NEXT_DIST_DIR=.next.candidate` then atomic swap. If build OOMs, use the nginx + hot-patch path above — do not leave PM2 stopped.
