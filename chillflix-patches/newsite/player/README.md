# Newsite watch-page player

Playback runs **inline on the watch page** (not a separate `/player` route).

## Routes
- Watch: `/newsite/movie|tv/...` — Play / Watch Now mounts hls.js player in `#movie-player`
- Auto-start: `?play=1`
- Sources API: `GET /newsite/api/player/sources`
- Legacy `/newsite/player/...` redirects to the watch URL with `?play=1`

## Providers
VAPlayer + Huhu via main Chillflix sources API.

## UI
Compact settings popover, dark timeline, SVG volume icons, quality/audio/subs + delay/size/bg, Auto Next.

## Deploy
```bash
python3 deploy_watch_embed.py   # on VPS; LOCAL dir = this folder
```
