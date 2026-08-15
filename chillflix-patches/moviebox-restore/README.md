# MovieBox restore

## What broke
**MovieBox** (`id: moviebox`, UI label **Pi**) is a real CinePro scraper for `moviebox.ph` — not the same as **HollyBox** / “Source 45” (`hollybox`).

It stopped returning streams because:

1. CinePro `.env` / PM2 dump had  
   `CINEPRO_PROVIDER_ALLOWLIST=onlyflix,cinesu,vaplayer`  
   so normal (non-`probe`) MovieBox calls returned `PROVIDER_NOT_FOUND`.
2. Vuflix `PlayerSources` treated `moviebox` as a **remote** CinePro prefetch via `/api/cinepro/sources`, which came back empty → UI “No source”.

`?probe=true` still worked on loopback the whole time.

## Fix (live VPS)
1. Allowlist: `onlyflix,cinesu,vaplayer,moviebox` in `/var/www/cinepro/.env` + PM2 dump / restart.
2. `PlayerSources.php`: treat `moviebox` like VSEmbed/MoviesOnlineHD — local `fetchCineproProbeProvider`, unwrap CinePro `/v1/proxy?data=…`, re-sign with media-proxy + `moviebox.ph` Referer/Origin.

## Verify
```bash
curl -sS 'https://vuflix.co/api/player/sources?type=movie&tmdbId=550&provider=moviebox'
# ok:true, 3× mp4 media-proxy URLs
```

## Files
- `PlayerSources.php` — deployed copy
- `patch_player_sources.py` — idempotent apply script
- `cinepro-allowlist.txt` — expected allowlist snapshot
