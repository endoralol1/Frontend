# Fix: vuflix.co homepage not opening (PHP pool saturated)

## Cause
`/api/huhu/` on vuflix proxied out to `https://www.chillflix.lol`, which resolved
to Cloudflare **IPv6**. Those connects hung (`connect() ... failed (101)`), kept
nginx busy, and PHP-FPM hit **50/50 active / 0 idle** — so `/` timed out.

## Fix
Proxy `/api/huhu/` to local Next `http://127.0.0.1:3000` with short connect timeout.
Restart php-fpm after deploy if the pool is already saturated.

## Apply
```bash
cp chillflix-patches/vuflix-huhu-local/vuflix-huhu-proxy.conf /etc/nginx/snippets/
nginx -t && systemctl reload nginx
systemctl restart php8.1-fpm
```
