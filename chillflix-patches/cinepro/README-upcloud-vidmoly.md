# Chillflix UpCloud + Vidmoly providers

Adds cinepro scrapers that shell out to `/var/www/chillflix-newsite/bin/fetch-local-provider.php`.

## Files
- `src/providers/himovies-php/resolve-php.ts` — PHP helper spawn + UpCloud PoW retries
- `src/providers/upcloud/upcloud.ts`
- `src/providers/vidmoly/vidmoly.ts`
- Chillflix `lib/stream-sources-defaults.ts` — catalog labels/order

## Deploy notes
1. Copy provider files into `/var/www/cinepro/src/providers/` and `npm run build`
2. Ensure `dotenv.config({ override: true })` in `src/server.ts` so PM2 stale env cannot hide allowlist changes
3. Set `CINEPRO_PROVIDER_ALLOWLIST` to include `upcloud,vidmoly` (or enable them as **builtin** in Admin → Stream Sources and save so allowlist sync writes them)
4. `pm2 restart cinepro --update-env && pm2 save`
5. Rebuild Chillflix after updating `stream-sources-defaults.ts` so admin saves keep `builtin: true`

UpCloud depends on Byse PoW (`byse-resolve.mjs`) and can fail with temporary captcha rate limits.
