# Card hover preview (HTML5, no YouTube chrome)

Live site fix for Netflix-style hover trailers on home rails / Top 10.

## Why not YouTube iframe crop?

Cropping/scaling a YouTube iframe to hide prev/pause/next **pushes the picture out of frame** and often still leaves controls visible. That approach is abandoned.

## Correct approach

1. Resolve trailer MP4 with `yt-dlp` (`cf_resolve_youtube_preview_stream`).
2. Same-origin proxy `/api/preview-media/{type}/{id}` streams bytes (googlevideo URLs are IP/time-bound).
3. Frontend plays muted HTML5 `<video>` with `object-fit: cover` — no iframe, no YT UI.
4. Card expands **width only** (right); height locked via `--cf-preview-h`.

## Files

| Path on VPS | Role |
|-------------|------|
| `public/assets/css/card-hover-preview.css` | Expand + framing |
| `public/assets/js/card-hover-preview.js` | Hover → preview API → video |
| `app/routes.php` | `/api/preview`, `/api/preview-media`, yt-dlp helper |
| `app/Views/layouts/main.php` | Asset `?v=20260813-cardprev14` |

Snippets in this folder mirror the deployed assets.
