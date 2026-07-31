# Newsite watch page polish

Live path: `/var/www/chillflix-newsite/`

## What changed

On movie/TV watch pages (e.g. `/newsite/movie/supergirl/1081003`):

1. **You may also like** sidebar for movies (uses TMDB similar/recommendations already loaded).
2. **More like this** poster grid under the detail block.
3. **Cast row** with photos + character names (CSS was already present).
4. **Genre links** into `/movies` or `/tv-series` discover filters.
5. Removed empty **Comments coming soon**; added a **Report an issue** button instead.
6. Clearer **Trailer** label on the player poster.
7. Richer JSON-LD (`genre`, `director`, `actor`, `duration`).

## Deploy

```bash
cp chillflix-patches/newsite/app/Views/pages/watch.php /var/www/chillflix-newsite/app/Views/pages/watch.php
cp chillflix-patches/newsite/app/Views/layouts/main.php /var/www/chillflix-newsite/app/Views/layouts/main.php
cp chillflix-patches/newsite/public/assets/css/app.css /var/www/chillflix-newsite/public/assets/css/app.css
```

No PM2 restart needed (PHP + static assets).
