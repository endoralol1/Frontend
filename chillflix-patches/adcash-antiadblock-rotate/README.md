# AdCash anti-adblock auto-rotate (Vuflix)

Live on VPS (`vuflix.co`):

- `/usr/local/sbin/adcash-auto-rotate-check` — cron `*/5`; rotates when timer due
- `/usr/local/sbin/adcash-rotate-antiadblock` — renames lib + updates `site-ads.json`
- Admin UI: `/admin/ads` Anti-adblock library (last rotated + next due)

## Fix (2026-08-20)

Auto-rotate skip messages used `echo ... (10m timer)` piped to bash, so parentheses caused a syntax error on every non-due cron tick. Check script now decides in Python and only invokes rotate when due. Admin last-rotated line made brighter and shows relative time + next auto run.
