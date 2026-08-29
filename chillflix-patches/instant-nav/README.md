# Instant nav (chillflix.lol)

Problem: Movies / TV / list clicks used full `<a>` document reloads via
`requiresFullPageNavigation()` for browse slugs.

Fix:
1. Soft Next.js `Link` for list/browse routes (modal bypass already exists)
2. Default `prefetch={true}` on `ListPageLink`
3. Idle prefetch of Home / Movies / TV

## Deploy
```bash
scp list-page-paths.ts root@VPS:/var/www/chillflix.lol/lib/list-page-paths.ts
scp list-page-link.tsx root@VPS:/var/www/chillflix.lol/components/list-page-link.tsx
scp instant-nav-prefetch.tsx root@VPS:/var/www/chillflix.lol/components/instant-nav-prefetch.tsx
# merge InstantNavPrefetch into app/layout.tsx (or scp app-layout.tsx -> app/layout.tsx)
cd /var/www/chillflix.lol && npm run build && pm2 restart chillflix
```
