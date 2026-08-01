# Browse sheet login / register

Live: `/var/www/chillflix-newsite/`

## Changes
- Removed Browse quick links: Top IMDB, Filters, Genres hub
- Added Account section with Login + Create account
- In-sheet auth panel (tabs) calling main-site `/api/auth/*` + Turnstile
- Shows signed-in user + logout after success

## Deploy
Copy `bottom-nav.php`, `layouts/main.php`, `config.php`, `public/assets/js/app.js`, `public/assets/css/app.css`.
