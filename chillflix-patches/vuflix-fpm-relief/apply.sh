#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "$0")" && pwd)"
ts=$(date -u +%Y%m%dT%H%M%SZ)
mkdir -p /etc/nginx/bak
cp -a /etc/nginx/sites-enabled/vuflix.co "/etc/nginx/bak/vuflix.co.bak-$ts"
cp "$ROOT/vuflix-api-limits.conf" /etc/nginx/conf.d/vuflix-api-limits.conf
cp "$ROOT/vuflix.co" /etc/nginx/sites-enabled/vuflix.co
cp "$ROOT/routes.php" /var/www/chillflix-newsite/app/routes.php
cp "$ROOT/ProxyGuard.php" /var/www/chillflix-newsite/app/Services/ProxyGuard.php
# Apply pm settings if snippet present
if [[ -f "$ROOT/www.conf.pm-snippet" ]]; then
  while IFS= read -r line; do
    key="${line%% =*}"
    sed -i "s|^${key} = .*|${line}|" /etc/php/8.1/fpm/pool.d/www.conf || true
  done < "$ROOT/www.conf.pm-snippet"
fi
nginx -t
systemctl reload nginx
systemctl restart php8.1-fpm
echo "vuflix-fpm-relief applied $ts"
