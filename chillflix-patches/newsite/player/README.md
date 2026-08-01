# Newsite `/player` stack

Separated player for `/newsite` (not the main React MediaPlayer).

## Live routes
- `GET /newsite/player/movie/{slug}/{id}`
- `GET /newsite/player/tv/{slug}/{id}?s=&e=`
- `GET /newsite/api/player/sources?type=&tmdbId=&season=&episode=`

## Providers
VAPlayer + Huhu via main Chillflix `/api/cinepro/sources` (loopback-friendly).

## Deploy
```bash
python3 deploy_newsite_player.py   # on VPS with ROOT=/var/www/chillflix-newsite
```

## Features
hls.js player with source/quality/audio/subtitles, delay/size/background, autoplay, Auto Next + Next button. Watch page Play / Watch Now launch `/player`; Trailer stays YouTube-only.
