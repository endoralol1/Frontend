# Chillflix patches — mobile desktop-site popunder ads

## Problem
On phones using **Request Desktop Site**, Monetag popunders often failed because:

1. Ads scripts only injected after the Watch modal opened (after the gesture was spent).
2. `site-player-ad-guard` recursed when `window.open` returned `null` (common on mobile), which broke the ad click handler.
3. Card Watch buttons called `stopPropagation()`, hiding the tap from Monetag’s document listeners.

## Fix
- Early-prime Monetag via `SitePlayerAdsPrime` + `prefetchSitePlayerAdsEnabled()` injecting scripts when enabled.
- Guard uses native `window.open` + `<a target=_blank>` fallback (no recursion).
- Watch card clicks no longer stop propagation.
- Embed parent delegate uses the same popup helper.

## Deploy
Copy patched files onto `/var/www/chillflix.lol` and rebuild/restart `chillflix` PM2.
