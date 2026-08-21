# NovaHD route / anti-bot fix

## What broke
`novahd.cc/api/sources` still exists, but:

1. Visitor header `x-nova-visitor: 1` (hardcoded) gets **decoy** only: `/api/decoy/p.m3u8`
2. Real streams need a **random** `x-nova-visitor` id (their frontend uses `localStorage nova_vid`)
3. Prefer `Accept: application/x-ndjson` (browser path); JSON still works with a good visitor
4. Must **filter out** `/api/decoy/` URLs so we never race a fake playlist as “healthy”

## Fix (live on VPS cinepro)
File: `/var/www/cinepro/src/providers/novahd/novahd.ts` (+ rebuilt `dist/…/novahd.js`)

- Fresh `x-nova-visitor` per request
- Accept NDJSON + JSON
- Drop decoy URLs; retry when only decoys returned
- Skip decoy in probeCandidate

Restart: `pm2 restart cinepro`
