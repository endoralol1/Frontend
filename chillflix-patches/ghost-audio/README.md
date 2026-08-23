# Ghost / double audio fix

## Symptom
Sometimes while watching, the same stream is also heard in the background. Pausing the main player does not stop it. Intermittent.

## Cause
1. Card hover previews can be unmuted. Softnav used to tear down only `activeCard` *after* wiping `.wrapper` via `innerHTML`, which can leave ghost audio from a removed playing `<video>`.
2. Navigating to Watch without pausing an unmuted preview first.
3. Player `destroyHls()` did not pause/clear the video element; overlapping `playUrl` races could also leave stale HLS callbacks.

## Fix
- `card-hover-preview.js`: `killAllPreviews()` on `cf:before-softnav`, `cf:softnav`, `pagehide`, `beforeunload`, visibility hidden, and before Play/Watch navigation. Exposes `window.__cfKillCardPreviews`.
- `player.js`: hard-stop video on HLS destroy, `playGen` guard on `playUrl`, kill card previews on boot/`playUrl`.

## Deploy
Copy JS to VPS and bump cache-bust query on layouts (see deploy notes).
