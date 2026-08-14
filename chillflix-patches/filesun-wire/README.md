# FileSuN wire

## What
Adds **FileSuN** (`filesun.sbs`) as a CinePro/player source.

## Flow (no Chromium)
1. Movie: `/embed/movie/{imdb}` (TMDB → IMDb via TMDB external_ids)  
   TV: `/embed/tv/{tmdb}/{season}/{episode}`
2. Read `RTOKEN` from embed HTML
3. `POST /api/resolve` `{ t: RTOKEN }` → servers (`embedsun.cc` / Vidmoly-family)
4. Extract plaintext `.m3u8` from server embed HTML
5. PlayerSources media-proxy with `Referer: https://embedsun.cc/`

## Verify
```bash
curl -sS 'http://127.0.0.1:3001/v1/movies/27205/provider/filesun?probe=true' | head -c 400
curl -sS 'https://vuflix.co/api/player/sources?type=movie&tmdbId=27205&provider=filesun'
```
