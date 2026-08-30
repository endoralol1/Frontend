#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "$0")" && pwd)"
WWW="${WWW:-/var/www/chillflix.lol}"
ts="$(date -u +%Y%m%dT%H%M%SZ)"

cp -a "$WWW/app/actions.ts" "$WWW/app/actions.ts.bak-persist-$ts"
cp -a "$WWW/components/region-select.tsx" "$WWW/components/region-select.tsx.bak-persist-$ts"
cp -a "$WWW/components/language-select.tsx" "$WWW/components/language-select.tsx.bak-persist-$ts"

cp "$ROOT/actions.ts" "$WWW/app/actions.ts"
cp "$ROOT/region-select.tsx" "$WWW/components/region-select.tsx"
cp "$ROOT/language-select.tsx" "$WWW/components/language-select.tsx"

cd "$WWW"
pm2 restart chillflix --update-env
echo "region-persist applied $ts"
