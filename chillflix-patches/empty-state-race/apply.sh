#!/usr/bin/env bash
# Stop premature "title isn't available" flash while sources are still arriving.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")" && pwd)"
WWW="${WWW:-/var/www/chillflix.lol}"
ts=$(date +%Y%m%d%H%M%S)

cp -a "$WWW/components/player/utils/playback.ts" "$WWW/components/player/utils/playback.ts.bak-empty-$ts"
cp -a "$WWW/hooks/useMediaSources.ts" "$WWW/hooks/useMediaSources.ts.bak-empty-$ts"
cp -a "$WWW/components/media-player-modal.tsx" "$WWW/components/media-player-modal.tsx.bak-empty-$ts"
cp -a "$WWW/components/cinepro-watch-player.tsx" "$WWW/components/cinepro-watch-player.tsx.bak-empty-$ts"

cp "$ROOT/playback.ts" "$WWW/components/player/utils/playback.ts"
cp "$ROOT/useMediaSources.ts" "$WWW/hooks/useMediaSources.ts"
cp "$ROOT/media-player-modal.tsx" "$WWW/components/media-player-modal.tsx"
cp "$ROOT/cinepro-watch-player.tsx" "$WWW/components/cinepro-watch-player.tsx"

cd "$WWW"
npm run build
pm2 restart chillflix --update-env
echo DONE
