# Chillflix 502 incident (2026-08-05)

## Symptom
Cloudflare Error 502 Bad Gateway for `chillflix.lol` / `www.chillflix.lol`.
Browser → Cloudflare edge OK; origin host failed.

## Root cause
1. Cinepro probed heavy providers (`upcloud`, `vidmoly`) that spawn `php fetch-local-provider.php` → `node byse-resolve.mjs`.
2. Concurrent Byse/Playwright scrapes saturated the VPS (load ~20 on 6 CPUs).
3. Next.js (`pm2 chillflix` on `:3000`) became too slow to answer health checks.
4. Watchdog treated timed-out curls as `000000` and restarted Chillflix under load → brief `connect() failed (111)` → Cloudflare 502.

## Fixes applied
- Disabled `upcloud`, `vidmoly`, `vixsrc` in allowlist + DB (`enabled: false`).
- Hardened `scripts/chillflix-watchdog.sh`: normalize health codes, longer timeout, skip restart when load1 ≥ 10, longer cooldown.
- Cap concurrent PHP helper spawns in `cinepro/.../resolve-php.ts` (max 2) and drop UpCloud retry storm.

## Keep disabled until concurrency is safe
Do not re-enable UpCloud/Vidmoly in the live allowlist while Byse is captcha-blocked; they melt the box and cause 502s.
