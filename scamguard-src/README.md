# ScamGuard

A self-hosted, autonomous scam/phishing website tracker
open, self-hosted, and transparent about *why* a site scored the way it did.

No headless browser required anywhere. All checks are lightweight protocol
calls: WHOIS/RDAP, a raw TLS handshake for SSL info, DNS lookups, and a plain
`curl` fetch of the homepage HTML.

---

## 1. Requirements

- PHP 8.1+ with the following extensions enabled (all standard on most hosts):
  `pdo_mysql`, `curl`, `openssl`, `mbstring`
- MySQL or MariaDB database
- Apache with `mod_rewrite`/`mod_headers` (for the included `.htaccess` files) —
  or adapt the equivalent rules if you're on Nginx
- Cron access (real cron via SSH, **or** a host that supports scheduled HTTP
  requests — both are supported, see step 5)

## 2. Upload

Upload the entire contents of this folder to your web root (e.g. `public_html`
or a subdomain folder). Everything is designed to run from a single directory
with no build step — just upload and go.

## 3. Create the database

1. Create a new MySQL database and a user with full privileges on it.
2. Import `database/schema.sql` into that database (via phpMyAdmin, Adminer,
   or `mysql -u youruser -p yourdb < database/schema.sql`).

## 4. Configure

Open `config/config.php` and edit:

- `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS` — your database credentials
- `SITE_URL` — your domain, no trailing slash
- `SITE_ENV` — set to `'production'` once everything works
- `APP_SECRET` and `CRON_SECRET` — replace both with long random strings
  (e.g. run `php -r "echo bin2hex(random_bytes(32));"` twice)
- `GOOGLE_SAFE_BROWSING_API_KEY` and `IPINFO_API_TOKEN` — both optional, the
  site works without them, just with fewer signals. Free tiers are available
  for both if you want to add them later.

## 5. First-run setup

Visit `https://yourdomain.com/setup.php` in your browser once. This creates
your first admin account (username + password), hashed securely by your own
server at that moment. **Once done, delete or rename `setup.php`** — it locks
itself automatically after the first admin account exists, but removing it
entirely is good practice.

Then log in at `https://yourdomain.com/admin/login.php`.

## 6. Set up the autonomous discovery pipeline (cron)

This is what makes the site track scam sites on its own, not just on-demand.

**If your host supports real cron (recommended):**

```
# Discover new domains every 15 minutes (Certificate Transparency + threat feeds)
*/15 * * * * php /full/path/to/scamguard/cron/discover.php >> /full/path/to/scamguard/storage/logs/discover.log 2>&1

# Re-check stale cached domains once an hour
0 * * * * php /full/path/to/scamguard/cron/rescan.php >> /full/path/to/scamguard/storage/logs/rescan.log 2>&1
```

Add these via your host's cron panel (cPanel → Cron Jobs, Plesk → Scheduled
Tasks, etc.) or `crontab -e` over SSH.

**If your host has no cron/SSH access at all**, use a free external
scheduled-ping service (e.g. cron-job.org) to hit these URLs on the same
schedule instead:

```
https://yourdomain.com/cron/discover.php?key=YOUR_CRON_SECRET
https://yourdomain.com/cron/rescan.php?key=YOUR_CRON_SECRET
```

`YOUR_CRON_SECRET` is whatever you set as `CRON_SECRET` in `config/config.php`.

## 7. You're live

- Public site: `https://yourdomain.com/`
- Admin panel: `https://yourdomain.com/admin/`

---

## What each part does

| Path | Purpose |
|---|---|
| `index.php` | Homepage with search box + recently checked domains |
| `check.php` | Domain result page (score, signal breakdown, history graph) |
| `report.php` | Public scam report submission form |
| `how-it-works.php`, `faq.php` | Static info pages |
| `admin/` | Full admin panel (see below) |
| `cron/discover.php` | Pulls new domains from Certificate Transparency logs + threat feeds |
| `cron/rescan.php` | Re-checks domains whose cached score has gone stale |
| `includes/DomainChecker.php` | Runs all signal checks (WHOIS/SSL/DNS/content/threat feeds) and scores the result |
| `includes/DomainRepository.php` | DB read/write layer, caching/staleness logic |
| `config/config.php` | The only file you need to edit for setup |
| `database/schema.sql` | Full DB schema — import this first |

### Admin panel pages

- **Dashboard** — KPIs, recent activity
- **Domains** — search/filter all tracked domains, force a re-check, manually
  override a score/status (whitelist or blacklist), bulk-add a list of domains
- **Reports** — moderate user-submitted scam reports (approve/reject)
- **Discovery** — enable/disable each discovery source, view run history,
  tune batch size and rate limits
- **Scoring** — adjust the weight of every signal and the score thresholds
  that map to Safe / Caution / Risky / Scam, without touching code
- **API Keys** — issue/revoke keys for a future public API
- **Settings** — site name, tagline, announcement banner
- **Activity Log** — audit trail of every admin action

## Extending it later

- **Public API**: `api_keys` and `api_usage_log` tables are already in the
  schema — add an `api/check.php` endpoint that validates a key header and
  calls `DomainRepository::getOrCheck()`.
- **More threat feeds**: add more sources inside
  `DomainChecker::checkThreatFeeds()` and `cron/discover.php`'s
  `discover_from_threat_feeds()`.
- **ASN reputation list**: the scoring function has a placeholder comment
  where you can plug in a known-bad-hosting-provider list for a stronger
  hosting signal.
- **Visual/brand-clone detection**: intentionally left out of this build per
  your requirements — if you want it later, that's the one piece that would
  require a headless browser (e.g. `chrome-php/chrome`) for screenshots.

## Security notes

- `config/`, `includes/`, `database/`, and `storage/` are blocked from direct
  web access via `.htaccess` — don't remove those files.
- Change the default recheck interval and rate limits in Discovery settings
  if you're on a low-resource VPS, to avoid hammering your own outbound
  bandwidth on the WHOIS/HTML-fetch steps.
- Rotate `CRON_SECRET` if you ever suspect it's leaked (it's a plain URL
  parameter when using the HTTP-trigger method).
