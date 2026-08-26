# Auto-source race pack: 3 → 4

## Bug that looked like “4th not triggered”
Watch pages load player via `routes.php` `extraJs`, which still pointed at
`player.js?v=20260822-yes1` (packSize 3). Layout `player.php` was updated, but
watch never used that tag — so Alpha stayed **Tap to load**.

## Fix
- `packSize: 4` in `player.js`
- Race UI keeps `Racing Sigma, Mega, Pi, Alpha…` and marks all 4 loading
- Bump **all** player.js cache tags to `?v=20260826-race4c`:
  - `app/routes.php` (watch `extraJs`) ← critical
  - `layouts/player.php`
  - `app.docksearch-1236.js` / `app.js` injectors

Hard-refresh a watch page; Source tab should scrape Alpha in the first pack.
