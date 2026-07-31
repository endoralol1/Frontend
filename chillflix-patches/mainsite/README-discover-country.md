# Discover country filter (from /newsite)

Adds TMDB `with_origin_country` to movie/TV discover filters on www.chillflix.lol, matching /newsite country picker intent.

## Files
- `components/discover-filter-country.tsx` (new)
- `components/discover-filters.tsx`
- `components/discover-active-filters.tsx`
- `config/site.ts` (`availableParams`)
- `tmdb/api/discover/types.ts`
- `messages/*.json` keys: `discover.country`, `discover.selectCountry`, `discover.searchCountry`

## Behavior
- Multi-select countries, joined with `|` (TMDB OR)
- Popular newsite countries listed first, full region list searchable below
- Active filter chips show country names

## Deploy
Copy into Next app root, `npm run build`, `pm2 restart chillflix`.
