# NetMirror source

Scrapes **NetMirror / nfMirror** (`netNN.cc` fronts) for Vuflix.

## How it works
1. **Search + playlist** via Jina reader (`r.jina.ai`) — CF blocks datacenter IPs on HTML, but `search.php` / `playlist.php` JSON works through Jina.
2. **Playback** HLS on `https://netNN.cc/hls/{id}.m3u8` proxied through `/api/player/v-relay`, which egresses via the **Yoru Worker `/stream`** path (same pool as Cineplay/Holly).

## Required: redeploy Worker allowlist
CF API tokens on the VPS currently fail (`Authentication error`), so auto-deploy did not push the allowlist.

Patched script is live at:
- `https://vuflix.co/yoru-relay.js`
- `/var/www/cinepro/workers/yoru-relay.js`

**Paste into each Cloudflare Worker** (primary + pool), Save & Deploy. Adds `netNN.cc` (+ regex `^net\d{2,3}\.cc$`) to `FETCH_ALLOW_HOSTS`.

Until that paste, discovery still returns sources, but **stream fetch returns “url not allowlisted”**.

## Admin
- Source id: `netmirror`
- Enabled + auto_load, sort_order **25** (early in race pack)
- Catalog coverage is Netflix-mirror style — some theatrical titles (e.g. Deadpool & Wolverine) may be missing.

## Files
- `NetMirrorSources.php`
- `PlayerSources.php` / `SourcesService.php` / `VidmolySources.php` / `bootstrap-services.php`
- `yoru-relay.js`
