# FzMovies provider (vuflix.co)

Deployed on VPS under `/var/www/chillflix-newsite`.

## What
- New `FzMoviesSources.php` — scrapes `fzmovies.host` movies (multi-quality + multi-mirror).
- Progressive MP4/MKV via `/api/player/v-relay` (Range streaming).
- TV skipped: `mobiletvshows.site` downloads currently offline upstream.
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
