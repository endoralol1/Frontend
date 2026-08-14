# Huhu + CineSu

## Huhu — fixed
Vuflix routes Huhu through Chillflix `/api/cinepro/sources`. Chillflix
`site_settings.stream_sources_config` had `"huhu": enabled=false`, which returned
`HUHU_DISABLED` even though vuflix’s own `sources` row was enabled.

**Fix:** set `huhu.enabled=true` in Chillflix `stream_sources_config`.
Verified: Fight Club returns EN/DE HLS via `chillflix.lol/api/huhu/stream`.

## CineSu — cannot restore (upstream)
Old CinePro path `https://cine.su/v1/stream/master/movie/{tmdb}.m3u8` now **404**.
Site still serves metadata (`/v1/movies/{id}`, `/en/watch-movie/{id}`) but playback
moved to obfuscated `glendale-plumbing.com` (`/c/v1/{token}/master.m3u8`, `/px/...`).
That CDN **times out from the VPS** (and via Yoru worker), so server-side scrape is dead.

**Mitigation:**
- Disabled `cinesu` in vuflix `sources` + Chillflix stream config
- Removed from CinePro allowlist
- PlayerSources returns `CINESU_UPSTREAM_DEAD` instead of a vague empty scan
