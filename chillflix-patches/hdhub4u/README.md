# HDHub4u provider (vuflix.co)

Scrapes the live HDHub4u catalog (gateway `hdhub4u.bi` → host JSON → currently
`new5.hdhub4u.cl`) and resolves HubDrive → HubCloud → FSL progressive files
through `/api/player/v-relay`.

## Status
- **Movies**: working (slug + IMDb match; site search is bot-blocked)
- **TV**: not wired yet (series are packed “all episodes” posts)
- **Vuflix-only** (`hostAllowed`)

## Flow
1. Resolve live domain from `h4.suncdn.org/host/` (and fallbacks)
2. Guess post slug from TMDB title/year; verify IMDb id on page
3. Parse quality buttons → prefer `hubdrive.tips`
4. HubDrive → `hubcloud.cx/drive/…` → `hubcloud.php` → FSL / FSLv2 CDN
5. Mint CDN URL via `VidmolySources::signedProxyUrl` (Range streaming)

## Apply
```bash
bash chillflix-patches/hdhub4u/apply.sh
```

## Smoke
```
curl -k -H 'Host: vuflix.co' -H 'User-Agent: Mozilla/5.0' \
  --resolve vuflix.co:443:127.0.0.1 \
  'https://vuflix.co/api/player/sources?type=movie&tmdbId=860508&provider=hdhub4u'
```
Expect `provider: hdhub4u`, qualities like `1080p HEVC` / `720p HEVC`, v-relay URL
returning HTTP 206 on Range.
