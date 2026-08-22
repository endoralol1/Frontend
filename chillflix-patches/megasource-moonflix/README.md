# MegaSource + Moonflix / HDGHAR + OpStream

## Why VSEmbed / OpStream sometimes “fail”

### VSEmbed (Sigma)
Scrape can succeed then play fail, or look empty even when the title exists:
1. **Upstream 521** — vsembed’s CDN/API behind Cloudflare is often down (`stream_urls API failed with 521`). We retry once.
2. **IP-bound CDN** — streams must go through our `v-relay`; binding 403s if the session cookie is missing and the client IP looks like a Cloudflare colo (fixed in ProxyGuard).
3. **Probe timeout / PHP-FPM kill** under load — request dies mid-scrape (nginx `recv failed`).

### Torrentio / Rapid (VidPlay fast path)
`stream.torrentio.to` cache API returns torrents **already uploaded** to Byse/Lulu/VOE:
`GET /api/v1/torrent/cache?imdbId=tt…` (+ season/episode for TV).
We pick the best **Byse** embed and resolve HLS via `ByseSources::resolveFromEmbed`.
Only works for titles they have cached (uncached → empty, not Failed).

### OpStream / Flash
1. **SpeedRace rate-limit** — `api.speedracelight.com` 429s our VPS when many viewers hit Flash.
   **Fix:** scrape via the shared **6 CF Yoru workers** (`/resolve`, same as Cineplay), cache ~5 min, VPS direct only as fallback.
2. **One-shot seeds** — seed expires / invalid between seed and decrypt; we retry on direct path.
3. **v-relay binding** — same ProxyGuard issue as above (peakstorm requires proxy, no browser Origin).

UI: soft/transient errors stay **pending** (not Failed) for vsembed + opstream + torrentio.
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

### moon6 — why their site felt fast and ours didn’t
hdghartv plays **CDN segments directly**. We were piping every `.jpg` TS chunk through PHP `a-relay` (double hop) so Moonflix “wanted to play” then Auto gave up.

Fix: a-relay only rewrites **playlists** (English default); **segments stay on streamraiwind** (CORS already allows `vuflix.co`). Seg fetch ~0.3s vs ~60s via PHP.

### moon7
Failed again: CDN `.jpg` TS + Content-Type `image/jpeg` broke demux after direct segments; soft errors also stamped **Failed**.
- Return **raw streamraiwind** URLs (no a-relay) — same path as hdghartv
- hls.js `overrideMimeType('video/mp2t')`, `enableWorker:false` for that CDN
- Moonflix soft errors → `pending` (not Failed)
