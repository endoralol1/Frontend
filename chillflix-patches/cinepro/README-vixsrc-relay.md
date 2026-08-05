# VixSrc → shared Yoru Worker relay

## Problem
`vixsrc.to` Cloudflare-bans the VPS IP (direct + FlareSolverr both 403).

## Fix
Reuse Worker `YORU_RELAY_URL` with new route `GET /vixsrc`.

## Files
- `../newsite/workers/yoru-relay.js` — adds `/vixsrc` (keeps `/resolve` for Yoru)
- `src/providers/vixsrc/vixsrc.ts` — prefers relay when `YORU_RELAY_URL` + `YORU_RELAY_SECRET` are set

## Deploy Worker (required once)
Cloudflare Dashboard → Workers → `divine-frost-3156` → Edit → paste updated `yoru-relay.js` → Save & Deploy.

## VPS
```bash
# allowlist includes vixsrc; YORU_RELAY_* already set
cd /var/www/cinepro && npm run build && pm2 restart cinepro --update-env
```

## Test
```bash
curl -sS "$YORU_RELAY_URL/vixsrc?type=movie&tmdb=27205&key=$YORU_RELAY_SECRET"
curl -sS "http://127.0.0.1:3001/v1/movies/27205/provider/vixsrc?fresh=true"
```
