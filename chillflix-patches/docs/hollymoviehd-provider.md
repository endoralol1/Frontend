# HollyMovieHD provider

Scrapes `https://hollymoviehd.cc/` (Cloudflare-protected WordPress + Goodstream player).

## Resolve path
1. FlareSolverr unlock → cache `cf_clearance` (~20 min)
2. Movie: `/{slug}-{year}/` (fallback site search)
3. TV: `/series/{slug}-season-{n}/` → `/episode/...-episode-{e}/`
4. Read `data-streamkey` + `data-wpnonce`
5. POST `admin-ajax.php?action=ajax_getlinkstream`
6. POST Goodstream embed with CSRF → MP4/HLS sources
7. Proxy through cinepro

## Enabled on
- **chillflix.lol** — admin Stream Sources + on-demand cinepro provider
- **vuflix.co** — `SourcesService` catalog / `newsite.sources` enabled (remote via chillflix)

## Important: on-demand only
`hollymoviehd` is **not** in `CINEPRO_PROVIDER_ALLOWLIST` (bulk fan-out melts FlareSolverr).
It is listed in `ON_DEMAND_CINEPRO_PROVIDER_IDS` and resolved via:
`/v1/.../provider/hollymoviehd?probe=true` when the player requests `?provider=hollymoviehd`.

## Ops
- Requires FlareSolverr on `FLARESOLVERR_URL` (default `http://127.0.0.1:8191`)
- Manual-only in Chillflix auto-probe order
