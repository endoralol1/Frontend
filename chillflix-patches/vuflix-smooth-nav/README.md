# Vuflix smooth page transitions

Removes the hard “blink” when switching Home / Movies / TV / etc.

## Behavior
- Soft-nav routes use the View Transitions API when available (Chrome/Edge/Safari)
- Fallback: short fade + lift on `.wrapper` content (dock stays put)
- Thin accent progress bar while the next page loads
- Full document navigations (watch pages, etc.) get a brief leave fade
- Honors `prefers-reduced-motion`

## Deploy
```bash
bash chillflix-patches/vuflix-smooth-nav/apply.sh
```

Touches:
- `public/assets/js/app.docksearch-1240.js` (from 1236 + transitions)
- `public/assets/css/soft-nav-transitions.css`
- `app/Views/layouts/main.php` (script/css cache-bust)
