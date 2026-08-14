# AdCash native banner

Adds AdCash confirmation loader + native banner above the site footer on all main-layout pages.

## Snippets
- Loader (head): `https://acscdn.com/script/aclib.js` (`id="aclib"`)
- Banner zone: `11970470` — rendered above footer in `layouts/main.php`

## Deploy
```bash
scp newsite/Views/layouts/main.php root@host:/var/www/chillflix-newsite/app/Views/layouts/main.php
```
