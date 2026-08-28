# Home rail + menu spacing (Chillflix Next.js)

Live target: `/var/www/chillflix.lol` (PM2 `chillflix`).

## Changes

1. **Tiny space under floating menu** — desktop `.home-hero-bleed` `margin-top: 0.4rem`.
2. **8 media cards per row** on desktop (`min-width: 1024px`) via `.home-carousel-item` flex basis.
3. **Remove Continue Watching pager** — no `1 / N` label or prev/next arrow buttons (same cleanup on trend / recommended headers).

## Files

- `globals.css` snippet rules: apply into `styles/globals.css` (see `home-rail.patch.css`)
- `continue-watching.tsx`, `trend-carousel.tsx`, `recommended-for-you.tsx` → `components/`

After copy: `npm run build && pm2 restart chillflix`.
