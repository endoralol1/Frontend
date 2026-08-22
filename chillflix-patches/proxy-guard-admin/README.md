# Proxy guard (session + IP bind + admin)

## What Cineby-style sites roughly do
Edge bot shield + browser session + short opaque stream links + rate limits.
We cannot make streams 100% unscrapable; this matches the practical core.

## Live protections
1. **Player session cookie** `vf_ps` — `/api/player/session` (player.js calls first)
2. **`/api/player/sources`** requires session (bare curl / no cookie → 403)
3. **Signed `v-relay` / `a-relay` tokens** — 15 min TTL, bound to session id + IP
4. **Scraper UA block** + per-IP rate limits
5. **Event log** of scraper signals only (`blocked_ua`, `no_session`, `rate_limit`, hard `ip_mismatch`)
6. **Auto-block** after 50 scraper events / 10 min (24h); admin can block/unblock

## Admin
`/admin/proxy-guard`
- 24h counters
- Top suspects
- Block / unblock IPs
- Recent scraper events

Normal watchers with a session are **not** listed.
