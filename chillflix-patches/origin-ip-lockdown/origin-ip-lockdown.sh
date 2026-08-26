#!/bin/bash
# Re-apply origin IP lockdown: default nginx drop + UFW CF-only 80/443
# Safe for orange-cloud sites. Keep SSH open. Do NOT use WARP.
set -euo pipefail
CF4_URL=https://www.cloudflare.com/ips-v4
CF6_URL=https://www.cloudflare.com/ips-v6

mkdir -p /etc/ssl/origin-default
[[ -f /etc/ssl/origin-default/cert.pem ]] || openssl req -x509 -nodes -newkey rsa:2048 -days 3650 \
  -keyout /etc/ssl/origin-default/key.pem -out /etc/ssl/origin-default/cert.pem -subj "/CN=localhost"

cat > /etc/nginx/sites-available/00-default-drop <<'NGINX'
server {
    listen 80 default_server;
    listen [::]:80 default_server;
    server_name _;
    return 444;
}
server {
    listen 443 ssl default_server;
    listen [::]:443 ssl default_server;
    server_name _;
    ssl_certificate     /etc/ssl/origin-default/cert.pem;
    ssl_certificate_key /etc/ssl/origin-default/key.pem;
    return 444;
}
NGINX
ln -sfn /etc/nginx/sites-available/00-default-drop /etc/nginx/sites-enabled/00-default-drop
nginx -t && systemctl reload nginx

ufw --force reset
ufw default deny incoming
ufw default allow outgoing
ufw allow 22/tcp comment "SSH"
while read -r cidr; do
  [[ -z "$cidr" ]] && continue
  ufw allow from "$cidr" to any port 80 proto tcp comment "CF-HTTP"
  ufw allow from "$cidr" to any port 443 proto tcp comment "CF-HTTPS"
done < <(curl -fsSL "$CF4_URL")
while read -r cidr; do
  [[ -z "$cidr" ]] && continue
  ufw allow from "$cidr" to any port 80 proto tcp comment "CF-HTTP-v6"
  ufw allow from "$cidr" to any port 443 proto tcp comment "CF-HTTPS-v6"
done < <(curl -fsSL "$CF6_URL")
ufw allow from 127.0.0.1 comment "localhost"
ufw allow from ::1 comment "localhost-v6"
ufw --force enable
ufw status | head -20
