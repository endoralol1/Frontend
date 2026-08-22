# cfhls leak fix + proxy scraper check

## Leak root cause
`StreamLangProxy::serveHlsEdge` downloaded **full HLS segments** (50–100MB+) into `/tmp/cfhls_*`, then `readfile()`.
PHP-FPM `request_terminate_timeout = 120` kills workers on long transfers → `finally { unlink }` never runs → multi-GB orphans.

## Fix (live)
1. **Segments passthrough** — no disk for `.ts/.m4s/...`; only small playlists use temp
2. **Tracked temps** — `makeTemp` + `register_shutdown_function` + `finally`
3. **Cron sweeper** — `/usr/local/sbin/sweep-cfhls-temps.sh` every 5 min (files older than 10 min)
4. One-shot purge freed **~6.6GB → ~40MB**

## Scraper audit (2026-08-21 vuflix.access.log)
| Path | Volume | Notes |
|------|--------|-------|
| media-proxy | ~1.2M hits | mostly browsers |
| lang-proxy | ~81k | mostly browsers |
| sources | ~77k | mostly browsers + vuflix referer |

**Suspicious:** `curl/8.14.1` pulled **~2.45 GB** across **3162** media-proxy requests (hours 08–16), walking sequential CDN pages (`page-0.html`…). That is ripper behavior using signed proxy URLs (e.g. from DevTools), not normal playback.

**Not seen:** mass `/sources` scraping by bots (only ~15 bot UA hits).

## Mitigation
- 403 scraper UAs (`curl|wget|python|scrapy|…`) on `/api/player/media-proxy` and `lang-proxy`
- Tokens still expire/sign as before; this just blocks the dumb rippers

## Files
- `app/Services/StreamLangProxy.php`
- `app/routes.php` (UA guard snippet)
- `/usr/local/sbin/sweep-cfhls-temps.sh` + crontab
