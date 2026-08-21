# Anti-adblock no-cache proxy

Live fix for Cloudflare caching `/cdn/*.js` with `max-age=14400` (`cf-cache-status: HIT`), which made filename/content rotation ineffective for hours.

## What changed (VPS, already deployed)

1. **Nginx** `include /etc/nginx/snippets/cdn-antiadblock-nocache.conf;` in `vuflix.co` HTTPS server → `/cdn/` served with `Cache-Control: no-store`.
2. **PHP** `GET /n/{name}.js` → `SiteAds::serveAntiAdblockNocache()` reads `public/cdn/{name}.js` (or legacy assets) with `no-store` headers.
3. **Rotate** `/usr/local/sbin/adcash-rotate-antiadblock` still installs under `/cdn/{random}.js`, but sets `antiAdblockSrc` to `/n/{random}.js`.

Verified: public `/n/….js` returns `cf-cache-status: BYPASS` and `cache-control: no-store`.

## Files in this patch

- `SiteAds.php` — full live service (includes `serveAntiAdblockNocache`)
- `routes-snippet.php` — route to add near admin ads routes
- `adcash-rotate-antiadblock` — rotate helper
- `cdn-antiadblock-nocache.conf` — nginx snippet

## Note on timer (10 vs 30 min)

Switching 30→10 does **not** fix browser adblock catching the lib by content. Rotate + no-cache helps URL/list staleness; Brave/uBlock may still block by behavior. Test logged-out (non-staff), hard refresh / incognito; use **Rotate now** when blocked.
