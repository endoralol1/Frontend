#!/usr/bin/env python3
"""Idempotent PlayerSources + SourcesService NovaHD wiring."""
from pathlib import Path

PS = Path("/var/www/chillflix-newsite/app/Services/PlayerSources.php")
SS = Path("/var/www/chillflix-newsite/app/Services/SourcesService.php")

NOVAHD_BLOCK = r'''
            // NovaHD — CinePro scraper (novahd.cc → IP-bound nova-edge HLS).
            // Probe locally + media-proxy playlist rewrite (CDN JWTs bind to VPS IP).
            if ($provider === 'novahd') {
                $nh = self::fetchCineproProbeProvider('novahd', $type, $tmdbId, $season, $episode);
                foreach ($nh['diagnostics'] ?? [] as $d) {
                    if (is_array($d)) {
                        $diagnostics[] = $d;
                    }
                }
                foreach ($nh['sources'] ?? [] as $src) {
                    if (!is_array($src) || empty($src['url'])) {
                        continue;
                    }
                    $rawUrl = (string) $src['url'];
                    $unwrapped = self::unwrapCineproProxyUrl($rawUrl);
                    $streamUrl = (string) ($unwrapped['url'] ?? $rawUrl);
                    $cdnHeaders = $unwrapped['headers'] ?? [];
                    if ($cdnHeaders === []) {
                        $cdnHeaders = [
                            'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
                            'Referer' => 'https://novahd.cc/',
                            'Origin' => 'https://novahd.cc',
                        ];
                    }
                    if (!isset($cdnHeaders['Referer'])) {
                        $cdnHeaders['Referer'] = 'https://novahd.cc/';
                    }
                    if (!isset($cdnHeaders['Origin'])) {
                        $cdnHeaders['Origin'] = 'https://novahd.cc';
                    }
                    $playUrl = $streamUrl;
                    $isHls = str_contains(strtolower((string) ($src['type'] ?? '')), 'hls')
                        || preg_match('/\.m3u8(\?|$)/i', $streamUrl)
                        || str_contains(strtolower($streamUrl), 'workers.dev');
                    if (class_exists('VidmolySources') && preg_match('#^https?://#i', $streamUrl)) {
                        $playUrl = VidmolySources::signedProxyUrl($streamUrl, $cdnHeaders, (bool) $isHls);
                    } elseif (class_exists('CineplaySources') && preg_match('#^https?://#i', $streamUrl)) {
                        $playUrl = CineplaySources::mintProxy($streamUrl, $cdnHeaders, (bool) $isHls);
                    }
                    $key = 'novahd|' . $streamUrl;
                    if (isset($merged[$key])) {
                        continue;
                    }
                    $quality = (string) ($src['quality'] ?? 'Auto');
                    $lang = trim((string) ($src['language'] ?? ''));
                    if ($lang === '') {
                        $lang = 'en';
                    }
                    $merged[$key] = [
                        'id' => substr(sha1($key), 0, 12),
                        'url' => $playUrl,
                        'type' => (string) ($src['type'] ?? ($isHls ? 'hls' : 'mp4')),
                        'quality' => $quality !== '' ? $quality : 'Auto',
                        'provider' => 'novahd',
                        'providerName' => (string) ($src['providerName'] ?? 'NovaHD'),
                        'label' => (string) ($src['label'] ?? ('NovaHD' . ($quality !== '' ? (' · ' . $quality) : ''))),
                        'language' => $lang,
                        'hasEnglish' => true,
                        'audioTracks' => [],
                    ];
                }
                if (empty($nh['ok']) && empty($nh['sources'])) {
                    $diagnostics[] = [
                        'code' => 'NOVAHD_EMPTY',
                        'message' => (string) ($nh['error'] ?? 'No NovaHD streams'),
                        'severity' => 'warning',
                        'provider' => 'novahd',
                    ];
                }
                continue;
            }

'''


def patch_player_sources() -> None:
    text = PS.read_text(encoding="utf-8")
    if "if ($provider === 'novahd')" in text:
        print("PlayerSources: novahd block already present")
    else:
        needle = "            if ($provider === 'cinejoy') {"
        if needle not in text:
            raise SystemExit("PlayerSources: cinejoy anchor not found")
        text = text.replace(needle, NOVAHD_BLOCK + needle, 1)
        print("PlayerSources: inserted novahd block before cinejoy")

    old = "'cinejoy', 'moviesonlinehd'"
    new = "'cinejoy', 'novahd', 'moviesonlinehd'"
    if "'novahd'" in text and "localProviderIds" in text:
        # ensure local list contains novahd
        if ", 'novahd'," in text or "'novahd', 'moviesonlinehd'" in text:
            print("PlayerSources: localProviderIds already has novahd")
        else:
            if old not in text:
                # try broader replace
                alt_old = "'flixhqz', 'cinejoy'"
                alt_new = "'flixhqz', 'cinejoy', 'novahd'"
                if alt_old in text and "'novahd'" not in text.split("localProviderIds", 1)[1][:400]:
                    text = text.replace(alt_old, alt_new, 1)
                    print("PlayerSources: added novahd via flixhqz/cinejoy anchor")
                else:
                    raise SystemExit("PlayerSources: could not patch localProviderIds")
            else:
                text = text.replace(old, new, 1)
                print("PlayerSources: added novahd to localProviderIds")
    else:
        if old in text:
            text = text.replace(old, new, 1)
            print("PlayerSources: added novahd to localProviderIds")
        else:
            raise SystemExit("PlayerSources: localProviderIds anchor missing")

    PS.write_text(text, encoding="utf-8")


def patch_sources_service() -> None:
    text = SS.read_text(encoding="utf-8")
    if "'novahd'" in text:
        print("SourcesService: novahd already in catalog")
        return
    needle = "        'moviesonlinehd' => 'MoviesOnlineHD',\n"
    insert = needle + "        'novahd' => 'NovaHD',\n"
    if needle not in text:
        raise SystemExit("SourcesService: moviesonlinehd anchor not found")
    SS.write_text(text.replace(needle, insert, 1), encoding="utf-8")
    print("SourcesService: added novahd catalog entry")


if __name__ == "__main__":
    patch_player_sources()
    patch_sources_service()
    print("ok")
