# ScamGuard temporarily disabled (2026-08-04)

## What was done on the Chillflix VPS

1. **Crontab** — all `/var/www/chillflix.lol/scamguard/cron/*` jobs commented out (`# DISABLED 2026-08-04 scamguard: ...`). Backup under `/root/crontab.bak-before-scamguard-disable-*`.
2. **Nginx** — `/etc/nginx/snippets/chillflix.lol-scamguard.conf` returns **503** for `/scamguard` and `/scamguard/`. Previous snippet saved as `chillflix.lol-scamguard.conf.bak-enabled-*`.
3. **DB** — `scamguard.site_settings`: `discovery_auto_enabled=0`, `support_chat_enabled=0`.
4. **Marker** — `/var/www/chillflix.lol/scamguard/.disabled`

## Re-enable

1. Restore nginx snippet from `.bak-enabled-*` and `nginx -t && systemctl reload nginx`.
2. Uncomment the four scamguard lines in `crontab -e` (or restore crontab backup).
3. Optionally set `discovery_auto_enabled=1` in admin if discovery should resume.
4. Remove `/var/www/chillflix.lol/scamguard/.disabled`.
