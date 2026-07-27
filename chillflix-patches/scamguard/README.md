# ScamGuard patches

## SEO + mixed results
- Pretty URLs: `/scamguard/site/{domain}`
- `sitemap.xml`, `robots.txt`, per-page meta/canonical/JSON-LD
- Browse all checks: `/scamguard/browse.php`
- Homepage mixes safe + scam recent results
- Hourly `cron/seed-popular.php` fills DB with known-good domains (not only threat feeds)

## Admin SPA
See `admin/` + `admin-ui/` from the prior admin discovery patch if present on another branch.
