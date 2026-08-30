# Fix: region stuck on Spain + content not changing

## Root causes
1. **Nginx stripped `Set-Cookie`** on HTML/`location /` (`proxy_hide_header Set-Cookie`), so the
   Server Action that wrote `region` never reached the browser.
2. **HTML cache key ignored `$cookie_region`**, so even with a new cookie Cloudflare/nginx kept
   serving the Spain page for `CF-IPCountry: ES`.
3. Chillflix runs **`next start`** — source-only deploys do nothing until **`next build`**.

## Fix (deployed)
- Stop hiding `Set-Cookie`; include `$cookie_region` in `proxy_cache_key`
- Client writes `region` cookie immediately + POST `/api/preferences/region` (API path does not strip cookies)
- Rebuild + restart `chillflix`

## Apply
```bash
bash chillflix-patches/region-persist/apply.sh
```
