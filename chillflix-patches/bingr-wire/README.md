# Bingr wire

## What
Adds **Bingr** (`api.bingr.one`) as a CinePro/player source (**Upsilon**).

## Flow
1. CinePro `POST https://api.bingr.one/api/stream` (Apollo → Edmunds → Sirius…)
2. PlayerSources unwraps CinePro proxy → **`StreamLangProxy` (lang-proxy)** (same path as HDGHAR)
3. lang-proxy rewrites HLS, forces `video/mp2t` for disguised TS, prefers English

## Playback fixes
- Sirius demuxed masters referenced missing `SUBTITLES="subs"` and served TS as `image/jpeg`
- Huge media playlists (1.6k segments × fat tokens) stalled HLS.js after duration loaded
- Prefer Apollo (muxed ladder); cache CDN headers server-side for short lang-proxy tokens
- `player.js` treats `bingr` as a slow provider

## Verify
```bash
curl -sS 'http://127.0.0.1:3001/v1/movies/1368337/provider/bingr?probe=true&fresh=true'
curl -sS 'https://vuflix.co/api/player/sources?type=movie&tmdbId=1368337&provider=bingr'
# expect lang-proxy URL + Apollo in diagnostics
```
