#!/usr/bin/env python3
"""Idempotent PlayerSources + SourcesService filesun wiring."""
from pathlib import Path

PS = Path("/var/www/chillflix-newsite/app/Services/PlayerSources.php")
SS = Path("/var/www/chillflix-newsite/app/Services/SourcesService.php")

BLOCK = r'''
            // FileSuN — embed API → embedsun/vidmoly-family HLS.
            if ($provider === 'filesun') {
                $fs = self::fetchCineproProbeProvider('filesun', $type, $tmdbId, $season, $episode);
                foreach ($fs['diagnostics'] ?? [] as $d) {
                    if (is_array($d)) {
                        $diagnostics[] = $d;
                    }
                }
                foreach ($fs['sources'] ?? [] as $src) {
                    if (!is_array($src) || empty($src['url'])) {
                        continue;
                    }
                    $rawUrl = (string) $src['url'];
                    $unwrapped = self::unwrapCineproProxyUrl($rawUrl);
                    $streamUrl = (string) ($unwrapped['url'] ?? $rawUrl);
                    $cdnHeaders = $unwrapped['headers'] ?? [];
                    if ($cdnHeaders === []) {
                        $cdnHeaders = [
                            'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                            'Referer' => 'https://embedsun.cc/',
                            'Origin' => 'https://embedsun.cc',
                        ];
                    }
                    if (!isset($cdnHeaders['Referer'])) {
                        $cdnHeaders['Referer'] = 'https://embedsun.cc/';
                    }
                    if (!isset($cdnHeaders['Origin'])) {
                        $cdnHeaders['Origin'] = 'https://embedsun.cc';
                    }
                    $playUrl = $streamUrl;
                    $isHls = str_contains(strtolower((string) ($src['type'] ?? '')), 'hls')
                        || preg_match('/\.m3u8(\?|$)/i', $streamUrl);
                    if (class_exists('VidmolySources') && preg_match('#^https?://#i', $streamUrl)) {
                        $playUrl = VidmolySources::signedProxyUrl($streamUrl, $cdnHeaders, (bool) $isHls);
                    } elseif (class_exists('CineplaySources') && preg_match('#^https?://#i', $streamUrl)) {
                        $playUrl = CineplaySources::mintProxy($streamUrl, $cdnHeaders, (bool) $isHls);
                    }
                    $key = 'filesun|' . $streamUrl;
                    if (isset($merged[$key])) {
                        continue;
                    }
                    $quality = (string) ($src['quality'] ?? 'Auto');
                    if ($quality === '') {
                        $quality = 'Auto';
                    }
                    $merged[$key] = [
                        'id' => substr(sha1($key), 0, 12),
                        'url' => $playUrl,
                        'type' => (string) ($src['type'] ?? ($isHls ? 'hls' : 'mp4')),
                        'quality' => $quality,
                        'provider' => 'filesun',
                        'providerName' => (string) ($src['providerName'] ?? 'FileSuN'),
                        'label' => 'FileSuN',
                        'language' => 'en',
                        'hasEnglish' => true,
                        'audioTracks' => [],
                    ];
                }
                if (empty($fs['ok']) && empty($fs['sources'])) {
                    $diagnostics[] = [
                        'code' => 'FILESUN_EMPTY',
                        'message' => (string) ($fs['error'] ?? 'No FileSuN streams'),
                        'severity' => 'warning',
                        'provider' => 'filesun',
                    ];
                }
                continue;
            }

'''


def patch_player_sources() -> None:
    text = PS.read_text(encoding="utf-8")
    if "if ($provider === 'filesun')" in text:
        print("PlayerSources: filesun block already present")
    else:
        for needle in (
            "            if ($provider === 'ridomovies') {",
            "            if ($provider === 'novahd') {",
            "            if ($provider === 'cinejoy') {",
        ):
            if needle in text:
                text = text.replace(needle, BLOCK + needle, 1)
                print("PlayerSources: inserted filesun before", needle.strip())
                break
        else:
            raise SystemExit("PlayerSources: insert anchor not found")

    if "'filesun'" in text.split("localProviderIds", 1)[-1][:500]:
        print("PlayerSources: localProviderIds already has filesun")
    else:
        for old, new in (
            ("'ridomovies', 'moviesonlinehd'", "'ridomovies', 'filesun', 'moviesonlinehd'"),
            ("'novahd', 'ridomovies'", "'novahd', 'ridomovies', 'filesun'"),
            ("'novahd', 'moviesonlinehd'", "'novahd', 'filesun', 'moviesonlinehd'"),
            ("'cinejoy', 'novahd'", "'cinejoy', 'novahd', 'filesun'"),
        ):
            if old in text:
                text = text.replace(old, new, 1)
                print("PlayerSources: added filesun to localProviderIds via", old)
                break
        else:
            raise SystemExit("PlayerSources: could not patch localProviderIds")

    PS.write_text(text, encoding="utf-8")


def patch_sources_service() -> None:
    text = SS.read_text(encoding="utf-8")
    if "'filesun'" in text:
        print("SourcesService: filesun already in catalog")
        return
    for needle in (
        "        'ridomovies' => 'RidoMovies',\n",
        "        'novahd' => 'NovaHD',\n",
        "        'moviesonlinehd' => 'MoviesOnlineHD',\n",
    ):
        if needle in text:
            SS.write_text(text.replace(needle, needle + "        'filesun' => 'FileSuN',\n", 1), encoding="utf-8")
            print("SourcesService: added filesun catalog entry")
            return
    raise SystemExit("SourcesService: catalog anchor not found")


if __name__ == "__main__":
    patch_player_sources()
    patch_sources_service()
    print("ok")
