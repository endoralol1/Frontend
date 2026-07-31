# Newsite watch page polish

Live path: `/var/www/chillflix-newsite/`

## What changed

On movie/TV watch pages (e.g. `/newsite/movie/supergirl/1081003`):

1. Current title details sit directly under the player; **You may also like** is below that (desktop sidebar / mobile bottom).
2. **Cast row** with photos + character names.
3. **Genre links** into `/movies` or `/tv-series` discover filters.
4. Removed empty **Comments coming soon**; added a **Report an issue** button instead.
5. Clearer **Trailer** label on the player poster.
6. Richer JSON-LD (`genre`, `director`, `actor`, `duration`).
7. No duplicate **More like this** poster grid.

## Deploy

```bash
cp chillflix-patches/newsite/app/Views/pages/watch.php /var/www/chillflix-newsite/app/Views/pages/watch.php
cp chillflix-patches/newsite/app/Views/layouts/main.php /var/www/chillflix-newsite/app/Views/layouts/main.php
cp chillflix-patches/newsite/public/assets/css/app.css /var/www/chillflix-newsite/public/assets/css/app.css
```

No PM2 restart needed (PHP + static assets).
