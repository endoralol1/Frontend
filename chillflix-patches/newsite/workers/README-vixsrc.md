# VixSrc via shared Yoru Worker

VPS IPs are Cloudflare-banned on `vixsrc.to`. Reuse the existing Worker
`https://divine-frost-3156.vuflix.workers.dev` with a new route.

## Deploy
1. Cloudflare Dashboard → Workers → `divine-frost-3156` (vuflix) → Edit code
2. Paste updated `yoru-relay.js` (adds `GET /vixsrc`)
3. Save & Deploy (same `YORU_RELAY_SECRET`)

## VPS
```bash
# already set for Yoru:
# YORU_RELAY_URL=https://divine-frost-3156.vuflix.workers.dev
# YORU_RELAY_SECRET=...

# CinePro picks these up automatically for VixSrc.
# Optional overrides: VIXSRC_RELAY_URL / VIXSRC_RELAY_SECRET

# Enable provider
# CINEPRO_PROVIDER_ALLOWLIST=...,vixsrc
# Admin → Stream Sources → enable VixSrc (builtin)

cd /var/www/cinepro && npm run build && pm2 restart cinepro --update-env
```

## Test
```bash
curl -sS "$YORU_RELAY_URL/vixsrc?type=movie&tmdb=27205&key=$YORU_RELAY_SECRET" | jq .
curl -sS "http://127.0.0.1:3001/v1/movies/27205/provider/vixsrc?fresh=true" | jq '.sources|length'
```
