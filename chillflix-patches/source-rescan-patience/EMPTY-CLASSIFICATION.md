# Soft vs hard empty classification (mirrors player.js)

## Hard empty → skip this round (background may recheck later)
- VSEMBED_EMPTY + PROVIDER_ERROR "stream_urls missing"
- MOVIEBOX_EMPTY alone / not found
- UPSTREAM_DEAD, NO_STREAMS, *_EMPTY without rate-limit

## Soft empty → retry now + background rounds
- PROVIDER_ERROR with HTTP 429 / 5xx / timeout / worker rate limit
- Example: MovieBox `HTTP 429 ... (via worker)` + MOVIEBOX_EMPTY

## Background rescan
After full cascade: status stays "No playable sources", then rounds 1..8
re-walk the same provider list with backoff 6s → 45s.
