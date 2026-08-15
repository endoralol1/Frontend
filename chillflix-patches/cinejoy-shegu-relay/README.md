# Cinejoy (4K) — shegu 403 / worker relay

## Symptom
Player shows no source for Cinejoy/4K. Diagnostic: `4K: cinejoy servers HTTP 403`.

## Cause
`https://api.shegu.st/servers` returns plain `forbidden` from the VPS IP (intentional block,
not a wiring bug). PlayerSources already probes Cinejoy locally.

## Fix (code ready on VPS)
1. `yoru-relay.js` — allowlist `cinejoy.to`, `shegu.st`, `api.shegu.st`, `a.shegu.st`, earthcleaner hosts
2. `lumen-client.ts` / `cinejoy.ts` — use `relayAwareFetch` instead of direct `fetch`
3. CinePro allowlist includes `cinejoy`; PlayerSources `localProviderIds` includes `cinejoy`

## Blocker
Cloudflare API tokens verify but cannot edit Workers scripts. Redeploy the updated
`yoru-relay.js` (also at `https://vuflix.co/yoru-relay.js`) to all pool Workers via Dashboard.

## Verify after Worker deploy
```bash
curl -sS 'https://vuflix.co/api/player/sources?type=movie&tmdbId=550&provider=cinejoy'
# expect ok:true with hls media-proxy URLs
```
