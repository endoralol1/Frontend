#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "$0")" && pwd)"
WWW=/var/www/chillflix.lol

cp "$ROOT/globals.css" "$WWW/styles/globals.css"
cp "$ROOT/template.tsx" "$WWW/app/template.tsx"
cp "$ROOT/layout.tsx" "$WWW/app/layout.tsx"
cp "$ROOT/site-header.tsx" "$WWW/components/site-header.tsx"
cp "$ROOT/site-bottom-nav.tsx" "$WWW/components/site-bottom-nav.tsx"
cp "$ROOT/smooth-client-nav.tsx" "$WWW/components/smooth-client-nav.tsx"
cp "$ROOT/list-page-paths.ts" "$WWW/lib/list-page-paths.ts"

cd "$WWW"
npm run build
pm2 restart chillflix --update-env
echo DONE
