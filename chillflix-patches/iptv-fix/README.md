# IPTV fix + page content speed

## IPTV
- Live TV (`kool.to` mediahub) returns Cloudflare 403 from the VPS IP.
- Channels API falls through: mediahub → curated iptv-org categories (capped ~2800) → local cache.
- Avoids dumping the full ~14k country index (looked buggy next to Free-TV).
- Live channels with a direct `url` play via the same proxy path as Free-TV.
- Mediahub requests time out after 8s so fallback is fast.
- IPTV search debounce 250ms → 80ms.

## Speed
- ScrollReveal triggers earlier (positive rootMargin) and animates faster.
- First home trend rows render eagerly (no DeferredSection wait).
- First two LazyHomeCarousel rows fetch immediately.
- Shorter page-enter / nav progress animations.
