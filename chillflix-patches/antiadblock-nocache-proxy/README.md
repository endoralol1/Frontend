# Anti-adblock delivery (no-cache + Brave-friendly path)

## What broke Brave

Earlier today Brave worked while the lib was at **`/assets/js/{random}.js`** (30‑min rotate).

Later path/timer experiments moved it to:
1. `/cdn/{random}.js` — Cloudflare was HIT-caching for ~4h (`max-age=14400`)
2. `/n/{random}.js` — PHP proxy (adds `Set-Cookie`, looks less like first‑party static JS)

Same file bytes; Brave is often path/list sensitive. Restoring **`/assets/js/`** matches what worked.

## Live setup (VPS)

1. **Rotate** installs under `public/assets/js/{8char}.js`, sets `antiAdblockSrc` to `/assets/js/…`
2. **Nginx** `assets-antiadblock-nocache.conf` — quoted regex so 8‑char libs get `Cache-Control: no-store` (not the `/assets/` 7d immutable rule). Regex must be quoted because nginx treats bare `{8}` as a block.
3. **CDN** `/cdn/` still has no-store (fallback). PHP `/n/` route remains but is **not** published by rotate.

Verified: public `/assets/js/….js` → `cf-cache-status: BYPASS`, `cache-control: no-store`.

## Files

- `adcash-rotate-antiadblock`
- `assets-antiadblock-nocache.conf`
- `cdn-antiadblock-nocache.conf`
- `SiteAds.php` (includes unused `/n/` helper)
- `routes-snippet.php` (`/n/{name}.js` fallback)

## Ops note

10 vs 30 min does not explain Brave. Prefer **30 min** to reduce churn once stable. Test logged-out; staff never get ads. If Brave still blocks after this restore, Shields likely fingerprint the AdCash payload itself — only AdCash updates / Rotate now help then.
