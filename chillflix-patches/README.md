# Chillflix 4K prefer4k fix (deployed on VPS)

Deployed to `/var/www/chillflix.lol` on 2026-07-18.

## Problem
`?prefer4k=1` showed “4K resolve request failed.” / no streams even when `/api/4k/resolve` returned streams (e.g. Enola Holmes 3 / TMDB `1202033`).

## Causes
1. `isFfmpegAvailable()` used dynamic `process.env[key]`, so `NEXT_PUBLIC_FOUR_K_*` never inlined in the browser — remux/transcode looked off and every MKV stream was dropped.
2. Client resolve used `credentials: "omit"` and absolute cross-host URLs; catch swallowed the real error as “4K resolve request failed.”
3. Provider scan timeout (15s) was tight for cold 4K resolve (~8s+).

## Fixes
- `lib/4khdhub/hls-flags.ts` — static `process.env.NEXT_PUBLIC_*` access
- `lib/providers/4khdhub-client-resolver.ts` — same-origin relative fetch, better errors
- `lib/playback-guard.ts` — allow `Sec-Fetch-Site: same-origin|same-site`
- `lib/source-probe-constants.ts` — provider fetch timeout 45s
