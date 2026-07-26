# Chillflix patches (VPS `/var/www/chillflix.lol`)

## First-party Monetag aclib (adblock bypass test)

- Site player uses Mapple-style first-party `/api/rum` (proxies Monetag `aclib.js`) + `aclib.runPop({ zoneId })`.
- Avoids EasyList blocks on `llvpn.com` / `acscdn.com` / `*/aclib.js` path rules.
- Embed surface still uses existing monetag-antiblock + jnbhi path.

Deploy: prefer `NEXT_DIST_DIR=.next.candidate` full build when the VPS has enough RAM. If `next build` OOMs (~11GB box), hot-patch the live `.next` bundles and restart `chillflix` only — do not leave PM2 stopped.
