# Fail-fast empty / dead streams (Vuflix player)

## Empty API
When `/api/player/sources` returns empty / `*_EMPTY` / not found:
- no scrape-retry of the same provider
- cascade to the next source immediately

## Dead "ready" streams (e.g. Bingr hung lang-proxy)
Some providers return metadata (English · 1080p) with a URL that never becomes playable (0:00/0:00).
HLS previously had **no load timeout**, so the player stuck on that source.

Now `armLoadWatchdog()` abandons a source if it never becomes playable within Auto-wait (clamped 8–20s), marks it failed in the Source menu, and moves on immediately.

Live: `player.js?v=20260821-watchdog1`

## watchdog2
Do **not** clear the load watchdog on MANIFEST_PARSED / onReady alone.
Only clear when media is actually playable (`readyState >= 2` and real duration).
Otherwise Bingr-style hung streams stay selected at 0:00/0:00 forever.
Timeout tightened to 6–12s; Source menu marks the slot failed.
Live: `player.js?v=20260821-watchdog2`
