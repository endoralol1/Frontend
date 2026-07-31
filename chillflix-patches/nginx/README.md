# Chillflix nginx soft bot defense

Protects origin from scrape storms without blocking real watchers.

## What it does

1. **`page >= 21`** on `/movie|tv/.../(similar|recommendations)` → **410** (never hits Next)
2. Soft **rate limits** (by `CF-Connecting-IP`) on similar / recommendations / people
3. Shorter proxy timeouts on those paths so stuck scrapes cannot fill nginx workers

## Files

- `chillflix-bot-soft-zones.conf` → `/etc/nginx/conf.d/`
- `chillflix.lol-bot-soft.conf` → `/etc/nginx/snippets/` (include inside www server, before `location /`)
