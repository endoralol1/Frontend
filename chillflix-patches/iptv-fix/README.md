# chillflix IPTV + page speed

## Live playback (chillflix.lol + vuflix.co)
Both sites proxy through `/api/iptv/live/{id}/index.m3u8`.

- Resolve via **huhu.to/mediaurl-resolve.json** + `huhu-iptv/play/{id}`
  (kool mediahub is Cloudflare-blocked from the VPS).
- chillflix Live catalog now uses **huhu MediaURL** (same as vuflix), so
  channel IDs match what playback can resolve.
- Falls back: kool mediahub → huhu catalog → local kool scrape.

## Free-TV
Unchanged (GitHub Free-TV playlist).
