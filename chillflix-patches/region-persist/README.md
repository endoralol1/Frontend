# Fix: region/language reset on refresh (Spain snap-back)

## Cause
Optimistic UI kept the new country until reload, but the `region` cookie often
never stuck — Server Action `Set-Cookie` alone was unreliable. After refresh the
header still read the old cookie (e.g. `ES` / Spain).

## Fix
- Write `region` / `locale` via `document.cookie` immediately on change
- Keep Server Action write with `secure: true`, `path=/`, `sameSite=lax`
- Ignore stale server props while a local change is pending

## Apply
```bash
bash chillflix-patches/region-persist/apply.sh
```
