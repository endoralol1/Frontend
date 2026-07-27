# ScamGuard patches

Deployed on the Chillflix VPS under `/var/www/chillflix.lol/scamguard`.

## Features in this patch
- Discovery cron ticks every minute; admin-configurable interval (default 5 minutes)
- Admin controls for batch size, interval, rate limit
- **Pull now** force discovery run
- React + shadcn/ui admin SPA at `/scamguard/admin/app/`
- JSON API at `/scamguard/admin/api.php`

## Build admin UI
```bash
cd admin-ui
pnpm install
pnpm build   # outputs to ../admin/app when built from /workspace/scamguard-admin
```

From this patch folder, build with:
```bash
cd admin-ui && pnpm install && pnpm exec vite build --outDir ../admin/app
```
