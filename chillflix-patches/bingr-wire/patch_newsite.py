#!/usr/bin/env python3
"""Idempotent PlayerSources + SourcesService bingr wiring."""
from pathlib import Path

PS = Path("/var/www/chillflix-newsite/app/Services/PlayerSources.php")
SS = Path("/var/www/chillflix-newsite/app/Services/SourcesService.php")

BLOCK = r'''
            // Bingr — api.bingr.one/stream → HLS (Sirius/Apollo/…).
            if ($provider === 'bingr') {
                $bg = self::fetchCineproProbeProvider('bingr', $type, $tmdbId, $season, $episode);
                foreach ($bg['diagnostics'] ?? [] as $d) {
                    if (is_array($d)) {
                        $diagnostics[] = $d;
                    }
                }
                foreach ($bg['sources'] ?? [] as $src) {
                    if (!is_array($src) || empty($src['url'])) {
                        continue;
                    }
                    $rawUrl = (string) $src['url'];
                    $unwrapped = self::unwrapCineproProxyUrl($rawUrl);
                    $streamUrl = (string) ($unwrapped['url'] ?? $rawUrl);
                    $cdnHeaders = $unwrapped['headers'] ?? [];
                    if ($cdnHeaders === []) {
                        $cdnHeaders = [
                            'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36',
                            'Referer' => 'https://hdghartv.cc/',
                            'Origin' => 'https://hdghartv.cc',
                        ];
                    }
                    if (!isset($cdnHeaders['User-Agent'])) {
                        $cdnHeaders['User-Agent'] = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36';
                    }
                    if (!isset($cdnHeaders['Referer'])) {
                        $cdnHeaders['Referer'] = 'https://hdghartv.cc/';
                    }
                    if (!isset($cdnHeaders['Origin'])) {
                        $ref = (string) $cdnHeaders['Referer'];
                        $scheme = parse_url($ref, PHP_URL_SCHEME);
                        $host = parse_url($ref, PHP_URL_HOST);
                        $cdnHeaders['Origin'] = ($scheme && $host)
                            ? ($scheme . '://' . $host)
                            : 'https://hdghartv.cc';
                    }
                    $playUrl = $streamUrl;
                    $isHls = str_contains(strtolower((string) ($src['type'] ?? '')), 'hls')
                        || preg_match('/\.m3u8(\?|$)/i', $streamUrl);
                    if (class_exists('VidmolySources') && preg_match('#^https?://#i', $streamUrl)) {
                        $playUrl = VidmolySources::signedProxyUrl($streamUrl, $cdnHeaders, (bool) $isHls);
                    } elseif (class_exists('CineplaySources') && preg_match('#^https?://#i', $streamUrl)) {
                        $playUrl = CineplaySources::mintProxy($streamUrl, $cdnHeaders, (bool) $isHls);
                    }
                    $key = 'bingr|' . $streamUrl;
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
                        'provider' => 'bingr',
                        'providerName' => (string) ($src['providerName'] ?? 'Bingr'),
                        'label' => 'Bingr',
                        'language' => 'en',
                        'hasEnglish' => true,
                        'audioTracks' => [],
                    ];
                }
                if (empty($bg['ok']) && empty($bg['sources'])) {
                    $diagnostics[] = [
                        'code' => 'BINGR_EMPTY',
                        'message' => (string) ($bg['error'] ?? 'No Bingr streams'),
                        'severity' => 'warning',
                        'provider' => 'bingr',
                    ];
                }
                continue;
            }

'''


def patch_player_sources() -> None:
    text = PS.read_text(encoding="utf-8")
    if "if ($provider === 'bingr')" in text:
        print("PlayerSources: bingr block already present")
    else:
        for needle in (
            "            if ($provider === 'filesun') {",
            "            if ($provider === 'ridomovies') {",
            "            if ($provider === 'novahd') {",
            "            if ($provider === 'cinejoy') {",
        ):
            if needle in text:
                text = text.replace(needle, BLOCK + needle, 1)
                print("PlayerSources: inserted bingr before", needle.strip())
                break
        else:
            raise SystemExit("PlayerSources: insert anchor not found")

    if "'bingr'" in text.split("localProviderIds", 1)[-1][:600]:
        print("PlayerSources: localProviderIds already has bingr")
    else:
        for old, new in (
            ("'filesun', 'moviesonlinehd'", "'filesun', 'bingr', 'moviesonlinehd'"),
            ("'ridomovies', 'filesun'", "'ridomovies', 'filesun', 'bingr'"),
            ("'novahd', 'ridomovies'", "'novahd', 'ridomovies', 'bingr'"),
            ("'cinejoy', 'novahd'", "'cinejoy', 'novahd', 'bingr'"),
        ):
            if old in text:
                text = text.replace(old, new, 1)
                print("PlayerSources: added bingr to localProviderIds via", old)
                break
        else:
            raise SystemExit("PlayerSources: could not patch localProviderIds")

    PS.write_text(text, encoding="utf-8")


def patch_sources_service() -> None:
    text = SS.read_text(encoding="utf-8")
    if "'bingr'" in text:
        print("SourcesService: bingr already in catalog")
        return
    for needle in (
        "        'filesun' => 'FileSuN',\n",
        "        'ridomovies' => 'RidoMovies',\n",
        "        'novahd' => 'NovaHD',\n",
        "        'moviesonlinehd' => 'MoviesOnlineHD',\n",
    ):
        if needle in text:
            SS.write_text(
                text.replace(needle, needle + "        'bingr' => 'Bingr',\n", 1),
                encoding="utf-8",
            )
            print("SourcesService: added bingr catalog entry")
            return
    raise SystemExit("SourcesService: catalog anchor not found")


if __name__ == "__main__":
    patch_player_sources()
    patch_sources_service()
    print("ok")
