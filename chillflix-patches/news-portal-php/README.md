# Daily24 news portal — PHP (vuflix)

Live: https://vuflix.co/news

## Features
- 24sata-inspired tabloid UI (mobile-first)
- Country / language toggle
- Image-rich RSS (BBC, Guardian, NPR, …) + Google News editions
- **Full article reader** — fetches publisher HTML (BBC text/image blocks, JSON-LD, generic article body)
- Image proxy `/api/news/image` (hotlink-safe)
- Source credits on every article

## Key files
- `Services/NewsFeed.php`
- `Services/NewsArticleReader.php`
- `Views/layouts/news.php`
- `Views/pages/news-home.php`, `news-article.php`
- `assets/css/news-portal.css`
- `routes.news.php` (+ require in `bootstrap-services.php`)
