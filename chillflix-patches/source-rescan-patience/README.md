# Source rescan patience (VSEmbed + cascade)

## Problem
VSEmbed / MovieBox / proxy HLS were often marked dead too early:
- Load watchdog clamped to **6–12s** (watchdog2), so real streams never got Admin `auto_wait_sec`
- Any empty API response was `definitiveEmpty` (including 429 / PROVIDER_ERROR) → no retry
- End of list showed **No playable sources** and stopped forever

Live measurements (Fight Club / TMDB 550):
- VSEmbed scrape ~0.5–6.5s when present; empty Mutiny ~6.7s (`VSEMBED_EMPTY`)
- MovieBox can 429 (`PROVIDER_ERROR` + `MOVIEBOX_EMPTY`) — must be retryable
- media-proxy playlist for VSEmbed ~0.4s warm; cold CDN needs more than 12s

## Fix (`player.js?v=20260821-rescan1`)
1. **Watchdog honors Auto-wait** — floor 8–15s, ceiling 45–60s; media-proxy / vsembed / bingr / moviebox / onlyflix get ≥15s; Huhu cold budget unchanged (45–90s)
2. **Soft vs hard empty** — `*_EMPTY` / not found skips this round; 429 / 5xx / PROVIDER_ERROR stays retryable
3. **VSEmbed** — client scrape timeout follows Admin (18–45s), included in soft-retry set
4. **`mediaLooksPlayable`** — accepts live/Infinity duration and advancing playback (not only finite VOD duration)
5. **Background rescan** — at end of list show **No playable sources**, then quietly re-walk the same list up to 8 rounds (6s → 45s backoff). If a later round finds a stream, playback starts.

## Files
- `public/assets/js/player.js`
- `app/Views/layouts/player.php` cache bump
- `app/routes.php` asset version (if listed)
