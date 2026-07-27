# NoTorrent provider patch (cinepro)

Copied from `/var/www/cinepro/src/providers/notorrent` and updated after NoTorrent addon v2.7 changed routing.

## What broke

- Addon still serves `/stream/{movie|series}/{imdb}.json`, but redirects now go through new workers (`ciniverse.*`, `ernax4.*`, hostinger `vid1.php`) instead of `321moviesfree`.
- Labels changed: bait links are `[FREE]`, real embeds are `[FREE TRIAL]` / `All Players`.
- Our scorer preferred `[FREE]` and only kept one candidate, so players often got an unrelated clip (e.g. `nasty.m3u8`) with the old `321moviesfree` proxy referer.

## Fix

- Deprioritize bare `[FREE]` streams.
- Prefer FREE TRIAL / All Players / Original audio; read quality from `name`.
- Try multiple redirect candidates; reject non-OK CDN responses and decoy URL patterns.
- Set proxy Referer/Origin from the resolved host (`nextgencloudfabric`, `ciniverse`, etc.).
