# Hero backdrop quality (vuflix / chillflix newsite)

## Why heroes looked soft on 2K/4K

Homepage heroes were hardcoded to TMDB **`w1280`** for FPS (after dropping `w1920` / `original`). That bitmap is upscaled under `background-size: cover` on large / high-DPR screens.

Not controlled by Browse Performance settings — those only toggle animations/trailers. Performance mode still keeps **`w1280`** on purpose.

## Fix

- LCP / default: still **`w1280`**
- Emit **`data-bg-hi`** = **`w1920`**
- `hero-quality.js` upgrades when viewport/DPR looks like desktop 2K+ and not `cf-perf-mode` / Save-Data

Avoids loading `original` (~4× `w1920`) for every slide.
