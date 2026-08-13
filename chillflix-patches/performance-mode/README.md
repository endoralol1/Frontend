# Performance mode (Browse Settings)

Opt-in toggle for lower-end PCs. Default **off**.

## User path
Browse sheet → Settings → **Performance** → **Performance mode**

## Pref
- `localStorage` / cookie: `cf_pref_performance` (`1` = on, `0` = off)
- Applies `html.cf-perf-mode` / `body.cf-perf-mode` (early head script before paint)

## What it disables
- Starfield RAF (static stars via `CF_refreshSkyMotion`)
- Film grain animation
- Card title shine animation
- Moon glow animation
- Large-display `zoom: 1.25`
- Heavy `backdrop-filter` on common chrome
- Card hover trailer previews (`canPreview` returns false)

## Live files (VPS)
| Path | Role |
|------|------|
| `app/Views/partials/bottom-nav.php` | Settings toggle UI |
| `app/Views/layouts/main.php` | Early class + `perf-mode.css` |
| `public/assets/css/perf-mode.css` | Effect overrides |
| `public/assets/js/app.docksearch-1228.js` | Pref wiring + sky |
| `public/assets/js/card-hover-preview.js` | Skip hover trailers |

Asset cache bust: `?v=20260813-perf01`
