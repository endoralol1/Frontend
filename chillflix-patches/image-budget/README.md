# Homepage FPS: oversized images (exact cause)

Profiling showed **CPU ~0% idle** but FPS still low → **GPU-bound**.

## Exact findings
- 44× poster URLs at `w600_and_h900_bestv2` for cards ~150–200px wide (far too many pixels)
- 36× `w780` landscape/episode stills
- 6× hero `w1920` backdrops
- Poster unload/reload on scroll forced repeated image decode (scroll FPS hit)

## Fixes (live)
- Default/`movie-card` posters → `w342`
- Episode/landscape stills → `w500`
- Hero backdrops → `w1280`
- `card-poster-lifecycle.js` no longer unloads posters

Asset: `?v=20260813-perf14`
