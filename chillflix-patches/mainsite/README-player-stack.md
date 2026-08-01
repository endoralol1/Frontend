# Player stack decision + next steps

## Video.js or Plyr?

**Neither.** Chillflix already ships a custom **hls.js `MediaPlayer`** with:

- HLS quality switching
- Multi-audio (`hls.audioTracks`)
- Custom subtitle overlay
- Autoplay gesture unlock
- Auto-next for TV
- Source probing / failover wired to `/api/cinepro/sources`

Video.js would be a large rewrite for little gain. Plyr is weaker for HLS + multi-audio. Keep extending the existing player.

## This patch

- Subtitle **delay** (±10s), **size**, **background** opacity (persisted in `localStorage`)
- Visible **Auto next** control button on the player chrome (TV)
- Settings → Subs panel for fine control

## Architecture (keep proxy lean)

```
Browser MediaPlayer (hls.js)
  → GET /api/cinepro/sources   (Next :3000, clean JSON merge)
  → HLS playlists/segments
       nginx → stream-proxy :3003
         /api/cinepro/proxy
         /api/huhu/proxy
         /api/huhu/stream
```

Scrapers:

- **VAPlayer** → cinepro provider (`/var/www/cinepro/.../vaplayer`)
- **Huhu** → native Next provider (`lib/providers/huhu`) + stream-proxy

Proxy goals: signed `px` URLs, m3u8 rewrite only when needed, streaming passthrough for segments (no buffering whole files in memory), keep Node stream-proxy thin.

## Still todo (next iterations)

1. Tighten stream-proxy segment path (lower CPU/memory under load)
2. VAPlayer/Huhu scrape reliability + cleaner sources API diagnostics
3. Newsite watch page: stop trailer-only; reuse main player / sources API
4. Autoplay reliability audit on mobile Safari
