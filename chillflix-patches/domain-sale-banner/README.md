# Domain sale banner (chillflix.lol)

- Component: `components/domain-sale-banner.tsx`
- Used from: `app/(home)/page.tsx`

**Current behavior:** banner is **disabled** (`ENABLED = false`).  
When re-enabled, users can dismiss it with **X** (persists in `localStorage`).

## Deploy
```bash
scp domain-sale-banner.tsx root@VPS:/var/www/chillflix.lol/components/domain-sale-banner.tsx
cd /var/www/chillflix.lol && npm run build && pm2 restart chillflix
```
