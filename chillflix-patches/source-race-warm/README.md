# Source race (Cinemove-style)

Player scrapes the first **3** `auto_load` sources in **parallel** and plays the first usable hit, then cascades sequentially for the rest.

Live: `player.js?v=20260822-race1`

## Why
Sequential cascade + long auto-wait made first paint slow when source #1 was empty/cold.
Cinemove races a small pack via `/api/play` NDJSON; we race our existing `/api/player/sources?provider=` calls.

## Not included (yet)
- MegaSource / NetMirror scrapers (we do not have those providers wired)
- ReallyFast is a Cinemove label that maps to their `novastream` family — closest we have is NovaHD (manual) / similar
- Server-side warm cache / NDJSON `/api/play` multiplex
