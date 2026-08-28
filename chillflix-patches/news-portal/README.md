# Daily24 news portal (`/news`)

24sata-inspired global news portal for Chillflix Next.js (`/var/www/chillflix.lol`).

## Features

- Tabloid layout inspired by 24sata.hr (hero + sidebar + latest grid)
- Country / language toggle (Google News editions + BBC World for GLOBAL)
- Category nav: World, Business, Sci/Tech, Sport, Show, Life
- Article pages with **source credits** + link to original publisher
- Aggregates public RSS feeds (no full-article scrape)

## Deploy paths

Copy into the Chillflix app root:

- `lib/news/*` → `lib/news/`
- `components/news/*` → `components/news/`
- `app/news/*` → `app/news/`
- `app/api/news/feed/*` → `app/api/news/feed/`
- `styles/news-portal.css` → `styles/news-portal.css`
- Patch `components/site-access-guard.tsx` so `/news` uses a minimal shell (no streaming chrome)

Then: `npm run build && pm2 restart chillflix`
