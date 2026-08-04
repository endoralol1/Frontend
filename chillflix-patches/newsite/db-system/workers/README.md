# Yoru relay (native 4K in Vuflix player)

Cineplay’s Yoru server is `api.speedracelight.com`. Our VPS IP is Cloudflare-banned there, so PHP must call a **relay** on a clean edge IP (Cloudflare Worker recommended).

## Flow

1. Player asks Vuflix `/api/...` for sources (provider `cineplay`).
2. `CineplaySources` calls `YORU_RELAY_URL/resolve` with `YORU_RELAY_SECRET`.
3. Relay fetches seed + `/cdn/sources-with-title`, decrypts enc=2, returns HLS URLs (incl. 2160p).
4. PHP wraps URLs in `/api/player/media-proxy` and returns them to the player.
5. `hls.js` plays in **our** UI — no Vidking/Cineplay iframe.

## Deploy Worker

1. Cloudflare Dashboard → Workers & Pages → Create → paste `yoru-relay.js`.
2. Settings → Variables → encrypt `YORU_RELAY_SECRET` (long random string).
3. On the VPS `.env` (newsite or cinepro):

```env
YORU_RELAY_URL=https://yoru-relay.<account>.workers.dev
YORU_RELAY_SECRET=<same secret>
```

4. Disable broken proxies (`VIXSRC_PROXY` pointing at a dead host).
5. Reload PHP-FPM.

## Test

```bash
curl -sS "$YORU_RELAY_URL/resolve?type=movie&tmdb=157336&key=$YORU_RELAY_SECRET" | jq '.sources[].quality'
# expect: 1080p, 720p, 480p, 2160p
```
