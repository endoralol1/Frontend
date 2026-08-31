# Fix: vuflix terribly slow / PHP-FPM saturated

## Cause
PHP-FPM was pegged at max children (50/50 busy):
- Flood of unauthenticated `GET /api/user/library` → 401 still burned PHP
- Many parallel `/api/player/sources` scrapes (up to 90s each)
- `v-relay` media proxy holds workers during stream

## Fix (live)
- Nginx: rate-limit user/sources/inbox; reject `/api/user/*` without `ns_session` at edge
- Cap sources `fastcgi_read_timeout` 45s + PHP `set_time_limit(40)`
- `SOURCES_PER_MIN` 60 → 24; limit concurrent relays per visitor
- `pm.max_children` 50 → 90

## Apply
Copy nginx files + PHP files from this folder, `nginx -t && reload`, restart php8.1-fpm.
