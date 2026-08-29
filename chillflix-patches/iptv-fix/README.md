# IPTV fix + page content speed

## IPTV
- Live TV (`kool.to` mediahub) returns Cloudflare 403 from the VPS IP.
- Channels API now falls through: mediahub → iptv-org playlist → local cache.
- Live channels with a direct `url` play via the same proxy path as Free-TV.
- Mediahub requests time out after 8s so fallback is fast.
- IPTV search debounce 250ms → 80ms.

## Speed
- ScrollReveal triggers earlier (positive rootMargin) and animates faster.
- First home trend rows render eagerly (no DeferredSection wait).
- First two LazyHomeCarousel rows fetch immediately.
- Shorter page-enter / nav progress animations.
