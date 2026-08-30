#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "$0")" && pwd)"
WWW="${WWW:-/var/www/chillflix.lol}"
ts="$(date -u +%Y%m%dT%H%M%SZ)"

mkdir -p /etc/nginx/bak
cp -a /etc/nginx/sites-enabled/chillflix.lol "/etc/nginx/bak/chillflix.lol.bak-persist-$ts"
cp -a /etc/nginx/snippets/chillflix.lol-bot-soft.conf "/etc/nginx/bak/chillflix.lol-bot-soft.conf.bak-persist-$ts"

# Nginx: region in cache key + do not strip Set-Cookie
if [[ -f "$ROOT/chillflix.lol.nginx" ]]; then
  cp "$ROOT/chillflix.lol.nginx" /etc/nginx/sites-enabled/chillflix.lol
fi
if [[ -f "$ROOT/chillflix.lol-bot-soft.conf" ]]; then
  cp "$ROOT/chillflix.lol-bot-soft.conf" /etc/nginx/snippets/chillflix.lol-bot-soft.conf
fi

cp -a "$WWW/app/actions.ts" "$WWW/app/actions.ts.bak-persist-$ts"
cp -a "$WWW/components/region-select.tsx" "$WWW/components/region-select.tsx.bak-persist-$ts"
cp -a "$WWW/components/language-select.tsx" "$WWW/components/language-select.tsx.bak-persist-$ts"

cp "$ROOT/actions.ts" "$WWW/app/actions.ts"
cp "$ROOT/region-select.tsx" "$WWW/components/region-select.tsx"
cp "$ROOT/language-select.tsx" "$WWW/components/language-select.tsx"

mkdir -p "$WWW/app/api/preferences/region" "$WWW/app/api/preferences/locale"
cp "$ROOT/api/preferences/region/route.ts" "$WWW/app/api/preferences/region/route.ts"
cp "$ROOT/api/preferences/locale/route.ts" "$WWW/app/api/preferences/locale/route.ts"

nginx -t
systemctl reload nginx
rm -rf /var/cache/nginx/chillflix_html/* || true

cd "$WWW"
NODE_OPTIONS="--max-old-space-size=6144" npm run build
pm2 restart chillflix --update-env
echo "region-persist applied $ts"
