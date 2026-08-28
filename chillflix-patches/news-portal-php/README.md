# Daily24 news portal — PHP (vuflix / chillflix-newsite)

24sata-inspired global news at **`/news`** on the PHP newsite (`/var/www/chillflix-newsite` → vuflix.co).

## Features
- Tabloid layout (hero + sidebar + latest grid)
- Country / language toggle (Google News editions; BBC for GLOBAL)
- Article page with **source credits** + original publisher link
- RSS aggregation only (no full-article scrape)

## Install on VPS
1. `Services/NewsFeed.php` → `app/Services/NewsFeed.php`
2. `require_once` NewsFeed in `app/bootstrap-services.php`
3. Append routes from `routes.news.php` into `app/routes.php`
4. `Views/layouts/news.php` → `app/Views/layouts/news.php`
5. `Views/pages/news-*.php` → `app/Views/pages/`
6. `assets/css/news-portal.css` → `public/assets/css/news-portal.css`
7. Reload php-fpm if needed (no build step)
