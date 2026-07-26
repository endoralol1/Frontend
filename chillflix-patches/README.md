# Chillflix patches (VPS `/var/www/chillflix.lol`)

## First-party Monetag anti-adblock lib (Mapple-style)

Mapple does **not** use a service worker for this. They:
1. Download Monetag’s **anti-adblock** JS from `adbpage.com` on the server (cron every ~5 min)
2. Serve it first-party as `/api/rum` (random/innocent name)
3. Call `aclib.runPop({ zoneId })`

Important: standard `acscdn.com/script/aclib.js` is **not** enough — adblockers still kill its follow-up adserver calls. The `adbpage.com` build includes `decoyDomain` / `AutoTagRotation`.

Live:
- `public/cdn/rum.js` refreshed by `/opt/chillflix/anti-adblock.sh` cron
- nginx `location = /api/rum` → that file
- site-player hot-patched to load `/api/rum` + `runPop`
- Zone should have “Support monetization of Adblock Traffic” enabled in Monetag

Do not leave PM2 stopped for builds.
