# Chillflix 4K prefer4k fix (deployed on VPS)

Deployed to `/var/www/chillflix.lol` on 2026-07-18.

## Problem
`?prefer4k=1` showed “4K resolve request failed.” / no streams even when `/api/4k/resolve` returned streams (e.g. Enola Holmes 3 / TMDB `1202033`).

## Causes
1. `isFfmpegAvailable()` used dynamic `process.env[key]`, so `NEXT_PUBLIC_FOUR_K_*` never inlined in the browser — remux/transcode looked off and every MKV stream was dropped.
2. Client resolve used `credentials: "omit"` and absolute cross-host URLs; catch swallowed the real error as “4K resolve request failed.”
3. Provider scan timeout (15s) was tight for cold 4K resolve (~8s+).

## Fixes
- `lib/4khdhub/hls-flags.ts` — static `process.env.NEXT_PUBLIC_*` access
- `lib/providers/4khdhub-client-resolver.ts` — same-origin relative fetch, better errors
- `lib/playback-guard.ts` — allow `Sec-Fetch-Site: same-origin|same-site`
- `lib/source-probe-constants.ts` — provider fetch timeout 45s

## Hydration React #418 / #423 (nav nested anchors) — 2026-07-19

### Root cause (100%)
SSR emitted invalid nested anchors in desktop `SiteNav` for list routes:

```html
<a href="/movie/discover"><a class="...radix...">Movies</a></a>
```

Same for `/tv/discover`, `/people/popular`, `/trending` (exactly 4× #418 + recovery #423).

`SiteNavItemSingle` wrapped Radix `NavigationMenuLink` (always an `<a>`) inside `ListPageLink`. For full-page list paths, `ListPageLink` also renders a raw `<a>`, so HTML was `<a><a>…</a></a>`. Browsers “fix” that DOM; React hydration rejects it → minified #418/#423.

Home / 4K / Music / Games / IPTV used Next `Link` + leftover `legacyBehavior`/`passHref` props and did not double-wrap in SSR.

### Fix
- `components/site-nav.tsx` — `NavigationMenuLink asChild` + single `ListPageLink` (same pattern as dropdown items)
- `components/list-page-link.tsx` — `forwardRef` for Radix `asChild`

### Verified after deploy
Playwright clean Chromium on `127.0.0.1:3000`, `www.chillflix.lol`, `chillflix.lol`: `reactErrorCount: 0`, `nestedAnchors: 0`, all nav items have correct `href`.

### Not app bugs (still may appear in a real PC console)
- `[Intervention] Images loaded lazily…` — browser intervention
- `No listener: tabs.outgoing.message.ready` — browser/extension
- `ERR_BLOCKED_BY_CLIENT` / AdBlock tab — ad blocker

## SEO hardening — 2026-07-19

### Fixes deployed
1. Homepage SSR `<h1>` + `WebSite` / `Organization` JSON-LD (SearchAction)
2. Hero movie title demoted to `<h2>` so only one H1
3. List pages use `buildListPageMetadata` → page-specific canonical + Open Graph URL
4. `/sitemap.xml` is now a **sitemap index** → static / movies / tv child sitemaps
5. Movie/TV meta descriptions capped at ~160 chars

### Verified live
- Home H1 + JSON-LD present in HTML
- `/movie/discover` and `/tv/popular` canonical/og:url match page
- Sitemap index returns child sitemap locs
