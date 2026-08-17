# Admin storage & nginx logs

## Decisions (from your notes)

1. **Live Chillflix `.next`** — left alone (clearing would slow the site).
2. **`/tmp/cfhls_*`** — Vuflix-only clean button (that’s the site that creates it).
3. **Vuflix API/TMDB cache** — clean button available; leave it while you have space.
4. **Nginx logs** — viewable/clearable in both admins.
5. **Always-safe cleans** — PM2 logs, npm cache, root `.cache`, old nginx `.gz`.

## Where to click

- **Vuflix:** Admin → **Storage** (`/admin/storage`)
- **Chillflix:** Admin → **Logs** (named nginx files) + Admin → **Storage** (safe disk cleans)

Deployed live on the VPS.
