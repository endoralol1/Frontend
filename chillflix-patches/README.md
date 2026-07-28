# Chillflix patches — player popunder ads on mobile “Desktop site”

## Scope
Ads inject **only while the Watch player is open** (`SitePlayerAds`), not sitewide.

## Why Desktop site broke player ads
Chrome’s **Desktop site** option sends a desktop User-Agent on a phone. Monetag then
takes the **desktop popunder** path (`window.open` / blank-window parking) instead of
a mobile interstitial.

Our player ad guard was making that worse:

1. It passed `noopener,noreferrer` as `window.open` features — on mobile Chrome that
   often returns `null` and breaks Monetag’s parked `about:blank` window.
2. When `window.open` returned `null`, the guard called itself recursively and killed
   the ad click handler.

## Fix (player-only)
- Reverted sitewide `SitePlayerAdsPrime` early injection.
- Guard uses native `window.open` without noopener feature strings, retries bare
  `window.open(url, "_blank")`, then `<a target=_blank>` / form submit fallbacks.
- No recursive patched `window.open` calls.

## Deploy
Copy patched files onto `/var/www/chillflix.lol` and rebuild/restart `chillflix` PM2.
