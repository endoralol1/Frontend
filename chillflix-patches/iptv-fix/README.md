# chillflix IPTV + page speed

## IPTV live playback (vuflix / chillflix)
- vuflix Live TV uses huhu MediaURL IDs and proxies through
  `/api/iptv/live/{id}/index.m3u8` on chillflix.
- Fix: resolve via **huhu.to/mediaurl-resolve.json** + `huhu-iptv/play/{id}`
  (kool mediahub is Cloudflare-blocked from the VPS).
- Also allows `vuflix.co` as a playback referer host.

## Catalog
- chillflix Live tab: mediahub → local kool scrape fallback.
- Free-TV unchanged.
