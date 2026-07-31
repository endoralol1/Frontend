# 4K HLS CPU guard

## Problem
`libx264` 4K re-encode (`mode=transcode`) was saturating the VPS (~300% CPU on one ffmpeg job) and filling `/tmp/chillflix-4k-hls` (tens of GB).

## Live fix applied on origin
1. Kill stuck `ffmpeg` jobs writing to `/tmp/chillflix-4k-hls`
2. Purge `/tmp/chillflix-4k-hls`
3. PM2 env:
   - `FOUR_K_HLS_ENABLED=false` / `FOUR_K_HLS_DISABLED=true` (and `NEXT_PUBLIC_*` twins)
   - Remux kept on: `FOUR_K_REMUX_ENABLED=true` (`-c:v copy`, much cheaper)
4. Source hardening in `lib/4khdhub/`:
   - `isFfmpegTranscodeAvailable()` always false unless `FOUR_K_TRANSCODE_FORCE=true`
   - `getOrStartHlsSession` refuses `mode=transcode`
   - `MAX_CONCURRENT_SESSIONS = 1`

## Apply after deploy / rebuild
Copy patched files into the Next app, then rebuild **or** at minimum set the env flags and `pm2 restart chillflix --update-env`.

Re-enable heavy transcode only with:
`FOUR_K_TRANSCODE_FORCE=true` plus `FOUR_K_HLS_ENABLED=true`.
