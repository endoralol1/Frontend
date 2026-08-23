# Videasy (Cinemove "VE")

## What it is
Cinemove **VE** = **Videasy** (`player.videasy.to`). Same upstream as Cineplay/Yoru:
`api.speedracelight.com` with `enc=2` + seed decrypt.

English-first servers raced: **Yoru** (`/cdn`), **Neon** (`/vsrc`), **Breach** (`/m4uhd`),
**Cypher** (`/downloader2`), **Vyse** (`/hdmovie` English filter).

## Files
- `VideasySources.php` → `/var/www/chillflix-newsite/app/Services/VideasySources.php`
- `videasy-resolve.mjs` → `/var/www/chillflix-newsite/bin/videasy-resolve.mjs`
- Wire into `bootstrap-services.php`, `PlayerSources.php` `$localProviderIds` + fetch branch,
  `bin/fetch-local-provider.php`, enable DB row `videasy` (public label **VE** or keep **Kappa**).

## DB
```sql
UPDATE sources SET enabled=1, public_label='VE', sort_order=35,
  notes='Videasy (Cinemove VE) — speedracelight multi-server via Yoru relay / videasy-resolve.mjs'
WHERE id='videasy';
```

## Notes
- VPS datacenter IP is often `seed 429` / CF 1015 — Worker relay or `PROVIDER_FETCH_PROXY` required.
- Proven working resolve from a clean egress IP with `node videasy-resolve.mjs --type movie --tmdb 157336`.

## Do NOT use Cloudflare WARP on the VPS
`warp-cli connect` breaks SSH/egress and takes the site offline (CF 522).
WARP service should stay disabled. Use Worker relay or a real HTTP proxy env
(`PROVIDER_FETCH_PROXY`) if seed is rate-limited — never WARP.
