# Fail-fast empty sources (Vuflix player)

When `/api/player/sources` returns empty / `*_EMPTY` / not found:
- do **not** scrape-retry the same provider
- mark slot empty and cascade to the next source immediately

When HLS reports a hard miss (404/410 or manifest load/parse error):
- skip Auto-wait countdown and move to the next source immediately

Live: `/var/www/chillflix-newsite/public/assets/js/player.js` (`?v=20260821-failfast1`)
