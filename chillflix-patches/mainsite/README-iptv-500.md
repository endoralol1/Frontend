# IPTV `/play/.../index.m3u8` 500 fix

## Cause
Middleware rewrote `/play/*` to an absolute `https://localhost:3000/api/iptv/live/...` when Cloudflare sent `X-Forwarded-Proto: https`. Next then tried TLS against the plain HTTP Node listener → `EPROTO wrong version number` → **500**.

Secondary: manifests sometimes advertised `http://` segment URLs (mixed content) or `localhost`.

## Fix
1. Middleware: force internal rewrite to `http://127.0.0.1:3000`
2. Manifest rewrite: always `https://www.chillflix.lol` (or public host) for live-fetch URLs
3. nginx: dedicated uncached `/play/` + `/api/iptv/` locations

## Verify
`/play/<id>/index.m3u8` → 200 with `https://www.chillflix.lol/api/iptv/live-fetch?...` lines.
