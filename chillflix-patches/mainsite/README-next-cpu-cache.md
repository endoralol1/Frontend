# Next CPU cache + image offload

## Live results (after build + restart)
- `/api/media/titles`: ~163ms cold → **~6ms** warm
- `/api/recommendations`: cached 10 min by seed fingerprint
- Homepage images: **TMDB CDN** (`image.tmdb.org`), not `/api/img`
- Legacy `/api/img/*` cache hits served by **nginx disk** (`X-Chillflix-Img: disk`)

## Files
- `lib/ttl-map-cache.ts` — process TTL cache + in-flight coalesce
- `lib/i18n/localize-stored-media.ts` — 6h title cache
- `app/api/recommendations/route.ts` — 10 min reco cache
- `lib/media-image-url.ts` — default TMDB CDN (`MEDIA_IMAGE_VIA_API=true` for old proxy)
- `components/recommended-for-you.tsx` — refresh debounce 400ms → 30s
- `nginx/chillflix.lol-img-cache.conf` — disk webp for `/api/img`

## Deploy
1. Copy sources into the Next app, `npm run build`, `pm2 restart chillflix`
2. Install nginx snippet before `location /api/`, `nginx -t && nginx -s reload`
