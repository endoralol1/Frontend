# Singapore bot block (chillflix.lol + vuflix.co)

## Problem
GA Realtime showed ~97% “users” from Singapore / ~99% desktop while origin
`chillflix.cf.log` barely recorded `SG`. HTML was advertised as
`Cache-Control: public, max-age=30`, so **Cloudflare edge-cached pages** and
bots executed gtag without hitting origin.

## Fix (deployed)
1. **Stop CF caching HTML** — `CDN-Cache-Control: no-store` + `private` browser cache.
2. **Block `CF-IPCountry: SG`** on HTML/browse at nginx (cookie bypass `cf_human_ok=1`).
3. **Skip GA for SG** + only boot GA after a real user gesture.
4. **Vuflix** — same nginx country block + deferred gtag + PHP early gate.

## Cloudflare (do this in the dashboard — highest leverage)
Zone `chillflix.lol` and `vuflix.co` → Security → WAF → Custom rule:

```txt
(ip.geoip.country eq "SG")
```

Action: **Managed Challenge** (or Block if you have no real SG audience).

Also: Caching → Cache Rules → Bypass cache for HTML (`http.request.uri.path` not matching `/_next/static` / assets), or rely on the new `CDN-Cache-Control: no-store` response headers.

## Unlock real Singapore visitors
Set cookie (once) then browse normally:

```text
https://www.chillflix.lol/sg-unlock
https://vuflix.co/sg-unlock
```

Or append `?unlock_sg=1` once (sets `cf_human_ok=1` for 7 days).

## Files
| Path | Role |
|------|------|
| `nginx/chillflix-bot-soft-zones.conf` | SG country map |
| `nginx/chillflix.lol-bot-soft.conf` | SG 403 + no-store cache headers on browse |
| `nginx/chillflix.lol.location-slash.diff` | `/` location header change |
| `nginx/vuflix-sg-block.conf` | vuflix include |
| `chillflix/should-skip-analytics.ts` | skip GA for SG |
| `chillflix/deferred-google-analytics.tsx` | human-gated GA |
| `chillflix/middleware.ts` | SG HTML reject |
| `chillflix/sg-unlock/route` | cookie unlock |
| `vuflix/SgBotGate.php` | PHP early reject |
| `vuflix/gtag-defer-snippet.html` | replace eager gtag |
| `bot-shield.js` | bump + SG note |

## Apply on VPS
See `apply.sh`.
