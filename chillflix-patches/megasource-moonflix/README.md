# MegaSource + Moonflix / HDGHAR

## MegaSource
Cinemove “Mega” → WatchPlay (`v1.watchplay.shop`). Direct scrape; CDN `*.hclod.qzz.io` works from VPS.

## Moonflix / HDGHAR
Cinemove “Moon” family. Live upstream is **`https://hdghartv.cc`** public API (no auth):

- `GET /api/search?q=…` → match `tmdbId` → `_id`
- `GET /api/movies/public/{id}` → `streamingLinks[]`
- `GET /api/series/public/{id}` → `seasons[].episodes[].streamingLinks[]`
- CDN: `*.streamraiwind.stream` (Referer `hdghartv.cc`)

`HdgharSources` scrapes that. `MoonflixSources` prefers HDGharTV (VixSrc only if `MOONFLIX_TRY_VIXSRC=1`).
Optional legacy: set `HDGHAR_API_BASE` for Railway-style `/movie/{tmdb}` JSON.

## Playback / ProxyGuard (Moonflix “Failed — tap retry”)
Root cause was **Proxy binding failed** on `a-relay` (session sid mismatch or HLS omitting cookie), not an empty scrape.

Fixes:
- Mint raw CDN in scrapers; mint `a-relay` in the web request (`PlayerSources::mintHdgharPlayUrl`)
- `watch_page` + `/api/player/session` mint `vf_ps` before the race
- Sticky sid per IP with **flock** so parallel `/sources` share one sid
- `assertTokenBinding`: allow **sidOk OR ipOk** (Safari/HLS may omit cookie on same IP)
- `player.js`: `xhrSetup.withCredentials`, remint once on binding 403; cache `?v=20260822-moon3`

### moon4
- Do not mark Source as Failed when scrape succeeded but playback blipped
- Treat HDGHAR `.jpg` segments as binary in a-relay
- Public labels: Moon / Mega (avoid Castle “Source 40” clash)

### moon5
Moonflix was finding streams then Auto-switched after a few seconds: HDGHAR **1080p** first segments are ~10MB via a-relay (phones can’t buffer before the 15s wait). Prefer **720p** default for a-relay/moonflix/hdghar; fat-proxy wait ~45s; dedupe duplicate subtitle rows.
