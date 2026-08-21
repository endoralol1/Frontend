# Eager watch player (Vuflix)

On watch page load, always mount the native player and start `/api/player/sources` search immediately (no Wait-for-Watch click).

- **Auto Play ON** → also try to start playback when a source is ready (muted fallback if needed).
- **Auto Play OFF** → sources search in background; user taps Watch/Play to start.

Live files touched:
- `app/Views/pages/watch.php`
- `public/assets/js/app.docksearch-1236.js` (+ `app.js` mirror)
- `public/assets/css/app-docksearch-1248.css` (boot overlay)
- `app/Views/layouts/main.php` (cache busters)
