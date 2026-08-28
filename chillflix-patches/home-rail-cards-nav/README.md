# Home rail + menu spacing (Chillflix Next.js)

Live target: `/var/www/chillflix.lol` (PM2 `chillflix`).

## Changes

1. **Tiny space under floating menu** — desktop `.home-hero-bleed` `margin-top: 0.4rem`.
2. **8 media cards per row** on desktop (`min-width: 1024px`) via `.home-carousel-item` flex basis.
3. **Remove rail page counters** (`1 / N`) — keep prev/next arrow buttons on Continue Watching, Trend, and Recommended rails.

## Files

- CSS snippet: `home-rail.patch.css` → apply into `styles/globals.css`
- `continue-watching.tsx`, `trend-carousel.tsx`, `recommended-for-you.tsx` → `components/`

After copy: `npm run build && pm2 restart chillflix`.
