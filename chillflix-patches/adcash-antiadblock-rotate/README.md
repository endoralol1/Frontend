# AdCash anti-adblock auto-rotate (Vuflix)

Live on VPS (`vuflix.co`):

- `/usr/local/sbin/adcash-auto-rotate-check` — cron `*/5`; rotates when timer due
- `/usr/local/sbin/adcash-rotate-antiadblock` — renames lib + updates `site-ads.json`
- Admin UI: `/admin/ads` Anti-adblock library (last rotated + next due)

## Notes

- Timers always use Unix time (timezone-independent).
- Admin "Last rotated" shows the **device local timezone** (CET/CEST in DE/HR, EET/EEST in RO, etc.), not server UTC.
- Auto-rotate skip path no longer pipes `(…)` echo text into bash.
