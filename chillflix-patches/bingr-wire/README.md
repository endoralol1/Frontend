# Bingr wire

## What
Adds **Bingr** (`bingr.one` / `api.bingr.one`) as a CinePro/player source (**Upsilon**).

## Flow (no Chromium)
1. `POST https://api.bingr.one/api/stream` with `{ srv, t: movie|tv, id: tmdbId, query }`
2. Try scrapers in order: Sirius → Apollo → Edmunds → … (stop at first hit)
3. Prefer English/multi + higher quality; unwrap `wormhole.filmu.in` when present
4. `createProxyUrl` with CDN Referer headers → PlayerSources media-proxy

## Verify
```bash
curl -sS 'http://127.0.0.1:3001/v1/movies/27205/provider/bingr?probe=true' | head -c 400
curl -sS 'https://vuflix.co/api/player/sources?type=movie&tmdbId=27205&provider=bingr'
```

## Notes
- Do not hammer — provider caches 10m and only probes until one server works.
- Turnstile on bingr.one is for auth only; stream API is open.

## Playback fix (media-proxy)
Sirius segments are MPEG-TS disguised as `.jpg` with `Content-Type: image/jpeg`.
`VidmolySources` now forces `video/mp2t` (same idea as HDGHAR/`StreamLangProxy`) and
strips dangling `SUBTITLES="subs"` so HLS.js can play.

```bash
python3 chillflix-patches/bingr-wire/patch_vidmoly_ts_mime.py
```
