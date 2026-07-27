# ScamGuard patches

## SEO + mixed results
- Pretty URLs: `/scamguard/site/{domain}`
- `sitemap.xml`, `robots.txt`, per-page meta/canonical/JSON-LD
- Browse all checks: `/scamguard/browse.php`
- Homepage mixes safe + scam recent results
- Hourly `cron/seed-popular.php` fills DB with known-good domains (not only threat feeds)

## Reputation signals (ScamAdviser-style)
- Trustpilot / Sitejabber reviews, MXToolbox RBL, URLVoid engines
- Stronger risky-TLD heuristics
- Same-server reverse-IP neighbors (skipped on Cloudflare/CDN)
- Origin IP DNSBL checks (skipped on Cloudflare/CDN)
- Phone / WhatsApp, free-webmail contact, noindex, crypto-only payment heuristics

## Admin SPA
- Dark (black) theme
- Built assets under `admin/app/` (source in repo `scamguard-admin/`)
