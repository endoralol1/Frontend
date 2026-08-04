# CineSu → Glendale HLS (2026-08)

Cine.su no longer serves the old encrypted `/v1/s/{routeId}/m/{token}` artifacts.
Their player now builds tokens for **`https://glendale-plumbing.com/c/v1/{token}/master.m3u8`**.

## Files
- `src/providers/cinesu/glendale.ts` — token builder + variant parse
- `src/providers/cinesu/cinesu.ts` — provider returns Auto + per-quality HLS (proxied)

## Deploy
```bash
cd /var/www/cinepro
# copy patched cinesu files
npm run build
# allowlist must include cinesu
# CINEPRO_PROVIDER_ALLOWLIST=...,cinesu,...
pm2 restart cinepro --update-env && pm2 save
```

## Notes
- Qualities come from the master playlist (path tokens like `/800p/`, `/1080p/`, `/2160p/` when present).
- OMSS proxy rewrites relative playlist URIs against `glendale-plumbing.com`.
- Route/hash constants are embedded from the live cine.su player bundle; refresh if resolves start 404ing.
