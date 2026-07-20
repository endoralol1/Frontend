# Chillflix patches (VPS `/var/www/chillflix.lol`)

## Disable streaming providers on homepage

- Removed `DeferredStreamingProviders` from `app/(home)/page.tsx` (already absent on VPS).
- Removed dead `LazyStreamingProviders` / `DeferredStreamingProviders` from `components/deferred-home-sections.tsx`.
- `components/provider-carousel.tsx` left in place unused (can delete later).

## Media card hover (bundled in same deploy)

- Stable `aspect-poster` slot; inner scale `1.04` on hover so posters grow without clipping.
- Related carousel / globals tweaks.

Deploy: build with `NEXT_DIST_DIR=.next.candidate`, swap `.next` ↔ `.next.candidate`, `pm2 restart`, never leave PM2 stopped.
