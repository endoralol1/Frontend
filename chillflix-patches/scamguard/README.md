# ScamGuard patches

## Multi-type checks (ScamAdviser-style)
- Homepage Type tabs: Website / Phone / Crypto / IBAN
- Pretty URLs: `/scamguard/phone/{n}`, `/crypto/{addr}`, `/iban/{iban}`
- Phone: country, carrier (prefix map), line type, VoIP, community abuse
- Crypto: BTC/ETH/TRX/LTC format checks + reports
- IBAN: ISO 13616 checksum + reports
- Tables: `entity_checks`, `entity_reports` (see `database/entity_checks.sql`)

## Also included
- Domain reputation signals, SEO browse/sitemap, dark admin SPA
