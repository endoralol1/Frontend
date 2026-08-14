# NovaHD wire

## What
Adds **NovaHD** (`novahd.cc`) as a CinePro provider and Vuflix player source.

## Upstream
- Movies: `GET https://novahd.cc/api/sources?type=movie&tmdbId={id}`
- TV: `GET https://novahd.cc/api/sources?type=show&tmdbId={id}&season=&episode=`
- Streams are HLS on `*.workers.dev` (nova-edge), often **IP-bound** to the scraper IP

## Fix / wiring
1. CinePro provider `src/providers/novahd/novahd.ts` (+ dist transpile)
2. Allowlist: add `novahd`
3. `PlayerSources.php`: local probe + media-proxy **playlist rewrite** + `Referer: https://novahd.cc/`
4. `SourcesService` catalog entry + DB `enabled=1`
5. Optional: `yoru-relay.js` allowlist `novahd.cc` + `workers.dev` (direct Node fetch already works)

## Verify
```bash
curl -sS 'http://127.0.0.1:3001/v1/movies/550/provider/novahd?probe=true' | head -c 400
curl -sS 'https://vuflix.co/api/player/sources?type=movie&tmdbId=550&provider=novahd'
# ok:true, hls media-proxy URLs
```
