#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "$0")" && pwd)"
WWW="${WWW:-/var/www/chillflix.lol}"
ts=$(date +%Y%m%d%H%M%S)

cp -a "$WWW/components/region-select.tsx" "$WWW/components/region-select.tsx.bak-stick-$ts"
cp -a "$WWW/components/language-select.tsx" "$WWW/components/language-select.tsx.bak-stick-$ts"
cp -a "$WWW/app/actions.ts" "$WWW/app/actions.ts.bak-stick-$ts"

cp "$ROOT/region-select.tsx" "$WWW/components/region-select.tsx"
cp "$ROOT/language-select.tsx" "$WWW/components/language-select.tsx"
cp "$ROOT/actions.ts" "$WWW/app/actions.ts"

cd "$WWW"
npm run build
pm2 restart chillflix --update-env
echo DONE
