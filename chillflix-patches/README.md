# Chillflix patches (VPS `/var/www/chillflix.lol`)

## Disable streaming providers on homepage

- Removed `DeferredStreamingProviders` from `app/(home)/page.tsx` (already absent on VPS).
- Removed dead `LazyStreamingProviders` / `DeferredStreamingProviders` from `components/deferred-home-sections.tsx`.
- `components/provider-carousel.tsx` left in place unused (can delete later).

## Media card hover (bundled in same deploy)

- Stable `aspect-poster` slot; inner scale `1.04` on hover so posters grow without clipping.
- Related carousel / globals tweaks.

## Playback cold-start / VAPlayer timeouts

- Raised probe + metadata + cold-start budgets so scraped VAPlayer links are not killed at 3–4.5s.
- Client probe timeout outlasts nested server probes; one retry on 429/502/503.
- `/api/cinepro/proxy` retries transient upstream 429/502/503 with short backoff before failing the player.

Deploy: build with `NEXT_DIST_DIR=.next.candidate`, swap `.next` ↔ `.next.candidate`, `pm2 restart`, never leave PM2 stopped.
