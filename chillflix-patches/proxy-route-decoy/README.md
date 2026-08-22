# Rotate player proxy routes + decoy on old URLs

## New routes (live)
| Old | New |
|-----|-----|
| `/api/player/media-proxy` | `/api/player/v-relay` |
| `/api/player/lang-proxy` | `/api/player/a-relay` |

Mint sites updated: `VidmolySources`, `StremifySources`, `StreamLangProxy` (Cineplay uses Vidmoly mint).

## Old routes → Visit vuflix.co
`ProxyDecoy::serve()`:
- `Accept: text/html` → HTML card with image + link to https://vuflix.co/
- otherwise → JPEG `assets/img/visit-vuflix.jpg` (scrapers get a picture, not a stream)

## Also
- player.js recognizes `v-relay` / `a-relay` for buffer/watchdog heuristics
- scraper UA 403 still applies on the **new** routes
- cache bust: `player.js?v=20260822-relay1`
