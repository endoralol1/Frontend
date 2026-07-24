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

## First-visit speed

Cold browser visits felt slow because ~1.5MB of JS + chat/tickets/storm all booted immediately, while return visits used disk cache.
- Defer chat status, ticket polling, turnstile config, and storm canvas until after first paint/idle.
- Nginx anonymous HTML microcache (30s, skips `chillflix_session`) — see `scripts/nginx-chillflix-html-cache.conf` + site conf.

Deploy: prefer `NEXT_DIST_DIR=.next.candidate` full build when the VPS has enough RAM. If `next build` OOMs (~11GB box), hot-patch the live `.next` bundles and restart `chillflix` only — do not leave PM2 stopped.
