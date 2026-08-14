# Flixhqz restore

## Symptom
Flixhqz enabled in admin but player showed empty / “No source”.

## Cause
Same class of break as MovieBox:
1. CinePro allowlist omitted `flixhqz` → non-probe calls `PROVIDER_NOT_FOUND`
2. PlayerSources treated it as remote `/api/cinepro/sources` prefetch → empty (`CINEPRO_BACKGROUND`)
3. Direct `?probe=true` still returned HLS (IP-bound JWTs)

## Fix
- Allowlist: add `flixhqz`
- `PlayerSources.php`: local `fetchCineproProbeProvider('flixhqz')`, unwrap CinePro `/v1/proxy`, media-proxy with **playlist rewrite** (VSEmbed-style)

## Verify
```bash
curl -sS 'https://vuflix.co/api/player/sources?type=movie&tmdbId=550&provider=flixhqz'
# ok:true, hls media-proxy URL; playlist body rewrites child URLs through media-proxy
```
