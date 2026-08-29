# chillflix IPTV + page speed

## IPTV (restored Live TV)
- Mediahub / kool.to is Cloudflare-blocked from the VPS IP.
- Live TV tries Mediahub first, then falls back to the **local kool scrape**
  (`television/network_scrape/channels.json`) — same Live TV channel IDs/names/countries as before.
- Playback still uses `/play/{id}` + IPTV service worker (browser talks to huhu/kool).
- No public iptv-org dump.

## Speed (kept)
- Eager first home trend rows / faster scroll-reveal & page-enter
