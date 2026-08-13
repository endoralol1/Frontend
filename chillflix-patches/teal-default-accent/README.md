# Teal default accent

Site-wide default accent theme changed from Ember (`#db6937`) to Teal (`#14b8a6`) — the Settings → Theme color swatch users pick as teal/cyan.

## Files
- `main.php` / `player.php` — early boot script default `cf_accent` → `teal`
- `app.docksearch-1228.js` — `getAccentId` / `applyAccentTheme` fallbacks → `teal`
- `app-docksearch-1232.css` — `:root` `--cf-orange*` defaults → teal
- `config.php` — `theme_color` → `#14b8a6`

Saved user choices in `localStorage.cf_accent` still win; only unset users get teal.
