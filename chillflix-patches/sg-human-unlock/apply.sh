#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "$0")" && pwd)"
ts="$(date -u +%Y%m%dT%H%M%SZ)"

cp -a /etc/nginx/conf.d/chillflix-bot-soft-zones.conf \
  "/etc/nginx/conf.d/chillflix-bot-soft-zones.conf.bak-human-$ts"
cp -a /etc/nginx/snippets/vuflix-sg-block.conf \
  "/etc/nginx/snippets/vuflix-sg-block.conf.bak-human-$ts"
cp -a /etc/nginx/snippets/chillflix.lol-bot-soft.conf \
  "/etc/nginx/snippets/chillflix.lol-bot-soft.conf.bak-human-$ts"
cp -a /var/www/chillflix-newsite/app/Services/SgBotGate.php \
  "/var/www/chillflix-newsite/app/Services/SgBotGate.php.bak-human-$ts"
cp -a /var/www/chillflix.lol/middleware.ts \
  "/var/www/chillflix.lol/middleware.ts.bak-human-$ts"

cp "$ROOT/chillflix-bot-soft-zones.conf" /etc/nginx/conf.d/chillflix-bot-soft-zones.conf
cp "$ROOT/vuflix-sg-block.conf" /etc/nginx/snippets/vuflix-sg-block.conf
cp "$ROOT/chillflix.lol-bot-soft.conf" /etc/nginx/snippets/chillflix.lol-bot-soft.conf
cp "$ROOT/SgBotGate.php" /var/www/chillflix-newsite/app/Services/SgBotGate.php
cp "$ROOT/middleware.ts" /var/www/chillflix.lol/middleware.ts
cp "$ROOT/sg-gate.html" /var/www/chillflix-newsite/public/sg-gate.html
cp "$ROOT/sg-gate.html" /var/www/chillflix.lol/public/sg-gate.html

nginx -t
systemctl reload nginx
systemctl reload php8.1-fpm 2>/dev/null || systemctl reload php8.2-fpm 2>/dev/null || systemctl reload php8.3-fpm 2>/dev/null || true
cd /var/www/chillflix.lol && pm2 restart chillflix --update-env
echo "sg-human-unlock applied $ts"
