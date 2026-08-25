# Bot Shield (dumb scrapers)

Client-side shield inspired by FluxTV `bot-shield.js`, deployed on:

- **chillflix.lol** — `/public/bot-shield.js` via `next/script` `beforeInteractive` in `app/layout.tsx`
- **vuflix.co** — `/public/assets/js/bot-shield.js` in `main.php` + `player.php` head

## Behavior
- Flags bad UAs (curl/scrapy/censys/copyright crawlers/AI bots), `navigator.webdriver`, automation globals
- Hidden honeypot links (`bot_trap`, `copyright_audit`, `dmca_scan`)
- On hit: 30-day localStorage hard block + decoy redirect to `/tv/1930`
- Skips `/embed`, `/api`, `/admin`, `/_next`, `/assets`
- Clear with `?reset_shield`

Cloudflare remains the primary scraper filter.
