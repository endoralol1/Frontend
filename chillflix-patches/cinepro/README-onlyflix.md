# OnlyFlix provider fix (2026-08)

## Problem
OnlyFlix was disabled in Chillflix Admin → Stream Sources, so CinePro never scraped it.
When forced on, the old resolver scraped every embed chain first and routinely hit
`PROVIDER_TIMEOUT_MS` (60s) under load → 0 streams.

## Fix
1. Enable `onlyflix` in `stream_sources_config` (`enabled` + `builtin: true`).
2. Add `onlyflix` to `CINEPRO_PROVIDER_ALLOWLIST` (after `cinesu`).
3. Fast-path known hosts via VAPlayer / Icefy, shallow scrape as fallback.
4. Rank servers and stop after the first solid stream (budget ~28s).

## Files
- `src/providers/onlyflix/onlyflix.ts`
- `src/providers/onlyflix/embed-resolver.ts`

## Deploy
```bash
cd /var/www/cinepro
npm run build
# CINEPRO_PROVIDER_ALLOWLIST=...,onlyflix,...
pm2 restart cinepro --update-env && pm2 save
```
