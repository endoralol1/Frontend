# MegaSource + Moonflix

## MegaSource
Cinemove label for the Stremio MegaSource addon. Default scraper = **WatchPlay** (`https://v1.watchplay.shop`).
We scrape WatchPlay directly (IMDb → `/api` getPlayer → HLS on `*.hclod.qzz.io`).
CDN is reachable from the VPS → mint via `VidmolySources::signedProxyUrl` (no Worker required).

- Source id: `megasource`
- Class: `MegaSourceSources.php`

## Moonflix
Cinemove aliases: `moonflix` / `hdghar` / `vixsrc` / `vixcloud` / `streamingcommunity` / `scws` / `unity`.
We try Worker `/vixsrc`, then `HdgharSources` (`HDGHAR_API_BASE` env override for Railway).

**Current status:** VixSrc returns CF 403 via Worker; Railway HDGHAR app is gone. Provider is wired so a fixed Worker or new `HDGHAR_API_BASE` lights up without another code change.

- Source id: `moonflix`
- Class: `MoonflixSources.php`

## Deploy
Copy PHP files onto VPS under `/var/www/chillflix-newsite/`, seed catalog, enable auto_load.
