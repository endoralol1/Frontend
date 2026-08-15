# chillflix.lol domain-for-sale banner

Adds a notice under the home hero on https://www.chillflix.lol/:

> Domain for sale — Interested in buying chillflix.lol? Contact **saltybureksmesom** on Discord.

## Live files
- `/var/www/chillflix.lol/components/domain-sale-banner.tsx`
- `/var/www/chillflix.lol/app/(home)/page.tsx` (renders `<DomainSaleBanner />` under hero)

Deployed with `npm run build` + `pm2 restart chillflix`.
