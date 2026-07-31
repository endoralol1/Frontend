# Hero status badges + TMDB logos

## Main site (`/var/www/chillflix.lol`)
- `lib/fetch-hero-movies.ts` — trending rank, HOT, newly launched, logo
- `components/movie-hero.tsx` — badge row under meta
- `styles/globals.css` — badge styles (no background/border)

## Newsite (`/var/www/chillflix-newsite`)
- Hero uses daily trending + TMDB title logos
- Same badge row: `#N Today`, `HOT`, `NEW Newly launched`
