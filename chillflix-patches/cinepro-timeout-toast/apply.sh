#!/usr/bin/env bash
# Suppress "CinePro did not return extra sources" toast when a stream already plays.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")" && pwd)"
WWW="${WWW:-/var/www/chillflix.lol}"

ts=$(date +%Y%m%d%H%M%S)
cp -a "$WWW/lib/playback-user-messages.ts" "$WWW/lib/playback-user-messages.ts.bak-toast-$ts"
cp -a "$WWW/app/api/cinepro/sources/route.ts" "$WWW/app/api/cinepro/sources/route.ts.bak-toast-$ts"
cp -a "$WWW/components/media-player-modal.tsx" "$WWW/components/media-player-modal.tsx.bak-toast-$ts"
cp -a "$WWW/components/cinepro-watch-player.tsx" "$WWW/components/cinepro-watch-player.tsx.bak-toast-$ts"
cp -a "$WWW/components/player/MediaPlayer.tsx" "$WWW/components/player/MediaPlayer.tsx.bak-toast-$ts"

cp "$ROOT/playback-user-messages.ts" "$WWW/lib/playback-user-messages.ts"
cp "$ROOT/route.ts" "$WWW/app/api/cinepro/sources/route.ts"
cp "$ROOT/media-player-modal.tsx" "$WWW/components/media-player-modal.tsx"
cp "$ROOT/cinepro-watch-player.tsx" "$WWW/components/cinepro-watch-player.tsx"
cp "$ROOT/MediaPlayer.tsx" "$WWW/components/player/MediaPlayer.tsx"

cd "$WWW"
npm run build
pm2 restart chillflix --update-env
echo DONE
