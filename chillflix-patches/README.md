# Chillflix patches (VPS `/var/www/chillflix.lol`)

## First-party Monetag anti-adblock (zone 11200416)

Retry of Mapple-style delivery for the **same zone 11200416** (not a different zone):

1. Server cron refreshes Monetag anti-adblock lib from `adbpage.com` → `public/cdn/rum.js`
2. nginx serves it first-party as `/api/rum`
3. Site player loads `/api/rum` then `aclib.runPop({ zoneId: "11200416" })`
4. Site-player `window.open` guard is **skipped** on this path (it can break pops)

Live check:
- `/api/embed/ads?surface=site-player` → `aclib-firstparty` + zone `11200416`
- `/api/rum` → ~625KB anti-adblock JS

If it fails again, revert compiled `.bak-*` bundles to restore llvpn.
