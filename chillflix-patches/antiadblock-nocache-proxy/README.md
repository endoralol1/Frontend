# Anti-adblock delivery (no-cache + Brave-friendly path)

## What broke Brave

Earlier today Brave worked while the lib was at **`/assets/js/{random}.js`** (30‑min rotate).

Later path/timer experiments moved it to:
1. `/cdn/{random}.js` — Cloudflare was HIT-caching for ~4h (`max-age=14400`)
2. `/n/{random}.js` — PHP proxy (adds `Set-Cookie`, looks less like first‑party static JS)

Same file bytes; Brave is often path/list sensitive. Restoring **`/assets/js/`** matches what worked.

## Live setup (VPS)

Per AdCash docs (`adbpage.com/adblock?v=3&format=js`):

1. **Cron** refreshes the cached lib every 5 min on disk.
2. **Inject SOURCE in `<head>`** — `SiteAds::antiAdblockInlineJs()` inlines the file into a `<script>` tag (not `<script src>`). External src is fallback only.
3. **Rotate** still renames the on-disk file under `/assets/js/` (helps older URL blockers / ops); page no longer depends on that URL loading.
4. **Nginx** no-store for 8‑char `/assets/js/` and `/cdn/` libs.

Verified: guest HTML head ~640KB with ~620KB inline `aclib` payload; no external 8‑char script src.

## Files

- `adcash-rotate-antiadblock`
- `assets-antiadblock-nocache.conf`
- `cdn-antiadblock-nocache.conf`
- `SiteAds.php` (includes unused `/n/` helper)
- `routes-snippet.php` (`/n/{name}.js` fallback)

## Ops note

10 vs 30 min does not explain Brave. Prefer **30 min** to reduce churn once stable. Test logged-out; staff never get ads. If Brave still blocks after this restore, Shields likely fingerprint the AdCash payload itself — only AdCash updates / Rotate now help then.
