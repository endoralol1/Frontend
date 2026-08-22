# YesMovies → ployan.me

Public site: `ww2.yesmovies.ag`  
Player host: `ployan.me`

## Curl-only resolve (no browser)

1. Map TMDB title → YesMovies `mid` via sitemap slug index (`/sitemap.xml`, cached 6h).
2. TV: load season page, read `episodes-sv-1` `data-id` for the episode.
3. Build token:
   - plaintext `mid+eid+sv+unix`
   - PBKDF2-HMAC-SHA256 password=`player`, iterations=1000, salt=8 random bytes → 32-byte key
   - AES-256-GCM encrypt → path `{salt_hex}-{iv_hex}-{ct+tag_hex}`
4. `GET https://ployan.me/get/{path}` → `{code:200, info, mode}`
5. Prefer **`mode=direct`** (usually server `sv=1`):
   `GET https://ployan.me/hls/{info}/master.m3u8` → segments on `*.voxzer.org`
6. Mint playlist through `v-relay` (rewrite).

Servers 2/5 return `mode=embed` (iframe path) — skipped for now.

## UI

Provider id `yesmovies`, public label **Yes** (or admin rename).  
Soft-empty when uncached / unmatched (pending, not Failed).

## Deploy

Copy into newsite:

- `app/Services/YesMoviesSources.php`
- patched `PlayerSources.php`, `SourcesService.php`, `bootstrap-services.php`
- `bin/fetch-local-provider.php`, `public/assets/js/player.js`

Enable in `newsite.sources` (auto_load, scrape_timeout ~75, sort near Rapid).
