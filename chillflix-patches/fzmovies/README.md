# FzMovies provider (vuflix.co)

Deployed on VPS under `/var/www/chillflix-newsite`.

## What
- New `FzMoviesSources.php` — scrapes `fzmovies.host` movies (multi-quality + multi-mirror).
- Progressive MP4/MKV via `/api/player/v-relay` (Range streaming).
- TV wired via `mobiletvshows.site` (same family), but **upstream downloads are still offline**
  (site banner since 14 Jun: “Downloads are not working… 15 days”). Sister mirrors
  (`tvseries.in`, `fztvseries.live`) show the same outage. Movies work; TV cannot until CDN is restored.
- Vuflix-only (`hostAllowed`), enabled in Admin → Sources as `fzmovies`.

## Files touched on VPS
- `app/Services/FzMoviesSources.php` (new)
- `app/bootstrap-services.php`
- `app/Services/SourcesService.php` (catalog)
- `app/Services/PlayerSources.php` (branch + localProviderIds)
- `app/Services/VidmolySources.php` (`X-Vuflix-Ssl: insecure` for expired CDN certs)

## Smoke
```
curl -k -H 'Host: vuflix.co' -H 'User-Agent: Mozilla/5.0' \
  --resolve vuflix.co:443:127.0.0.1 \
  'https://vuflix.co/api/player/sources?type=movie&tmdbId=1924&provider=fzmovies'
```
Expect `provider: fzmovies`, qualities `720p`/`480p`, v-relay URL returning HTTP 206 on Range.


## Quality menu fix
Progressive MP4/file playback was wiping `state.qualityOptions`, so the Quality
panel showed **Nothing available yet**. `player.js` now keeps per-URL quality
packs for file sources. Cache-bust: `player.js?v=20260829-fzmovies-q1`.
