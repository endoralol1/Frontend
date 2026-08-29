#!/usr/bin/env python3
from pathlib import Path
import re

# 1) bootstrap
p = Path("/var/www/chillflix-newsite/app/bootstrap-services.php")
t = p.read_text()
needle = "require_once __DIR__ . '/Services/YesMoviesSources.php';\n"
insert = needle + "require_once __DIR__ . '/Services/FzMoviesSources.php';\n"
if "FzMoviesSources.php" not in t:
    if needle not in t:
        raise SystemExit("bootstrap needle missing")
    t = t.replace(needle, insert, 1)
    p.write_text(t)
    print("bootstrap: ok")
else:
    print("bootstrap: already")

# 2) SourcesService catalog
p = Path("/var/www/chillflix-newsite/app/Services/SourcesService.php")
t = p.read_text()
needle = "        'yesmovies' => 'YesMovies', // ww2.yesmovies.ag → ployan.me direct HLS\n"
insert = needle + "        'fzmovies' => 'FzMovies', // fzmovies.host progressive MP4/MKV mirrors\n"
if "'fzmovies'" not in t:
    if needle not in t:
        raise SystemExit("catalog needle missing")
    t = t.replace(needle, insert, 1)
    p.write_text(t)
    print("catalog: ok")
else:
    print("catalog: already")

# 3) PlayerSources
p = Path("/var/www/chillflix-newsite/app/Services/PlayerSources.php")
t = p.read_text()

if "'fzmovies', 'videasy'" not in t and "'yesmovies', 'videasy'" in t:
    t = t.replace("'yesmovies', 'videasy'", "'yesmovies', 'fzmovies', 'videasy'", 1)
    print("localProviderIds: ok")
else:
    m = re.search(r"(\$localProviderIds = \[)([^\]]+)(\];)", t)
    if not m:
        raise SystemExit("localProviderIds missing")
    inner = m.group(2)
    if "'fzmovies'" not in inner:
        inner2 = inner.replace("'yesmovies'", "'yesmovies', 'fzmovies'", 1)
        t = t[: m.start(2)] + inner2 + t[m.end(2) :]
        print("localProviderIds: ok (regex)")
    else:
        print("localProviderIds: already")

branch = """
            // FzMovies → progressive MP4/MKV mirrors (Vuflix-first).
            if ($provider === 'fzmovies') {
                if (!class_exists('FzMoviesSources') || !FzMoviesSources::hostAllowed()) {
                    $diagnostics[] = [
                        'code' => 'FZMOVIES_HOST_BLOCKED',
                        'message' => 'FzMovies is Vuflix-only for now',
                        'severity' => 'info',
                        'provider' => 'fzmovies',
                    ];
                    continue;
                }
                $fz = FzMoviesSources::fetch($type, $tmdbId, $season, $episode);
                foreach ($fz['diagnostics'] ?? [] as $d) {
                    if (is_array($d)) {
                        $diagnostics[] = $d;
                    }
                }
                foreach ($fz['sources'] ?? [] as $src) {
                    if (!is_array($src) || empty($src['url'])) {
                        continue;
                    }
                    $streamUrl = (string) $src['url'];
                    $qualities = [];
                    foreach ($src['qualities'] ?? [] as $q) {
                        if (!is_array($q) || empty($q['url'])) {
                            continue;
                        }
                        $qualities[] = [
                            'quality' => (string) ($q['quality'] ?? 'Auto'),
                            'url' => (string) $q['url'],
                            'type' => (string) ($q['type'] ?? 'file'),
                        ];
                    }
                    $quality = (string) ($src['quality'] ?? 'Auto');
                    $key = 'fzmovies|' . ($qualities !== [] ? 'pack|' . $quality : $streamUrl);
                    if (isset($merged[$key])) {
                        continue;
                    }
                    $merged[$key] = [
                        'id' => substr(sha1($key), 0, 12),
                        'url' => $streamUrl,
                        'type' => (string) ($src['type'] ?? 'file'),
                        'quality' => $quality !== '' ? $quality : 'Auto',
                        'provider' => 'fzmovies',
                        'providerName' => (string) ($src['providerName'] ?? 'FzMovies'),
                        'label' => (string) ($src['label'] ?? ('FzMovies · ' . $quality)),
                        'language' => (string) ($src['language'] ?? 'en'),
                        'hasEnglish' => array_key_exists('hasEnglish', $src) ? (bool) $src['hasEnglish'] : true,
                        'audioTracks' => [],
                        'qualities' => $qualities,
                        'meta' => is_array($src['meta'] ?? null) ? $src['meta'] : [],
                    ];
                }
                if (empty($fz['ok']) && empty($fz['sources'])) {
                    $diagnostics[] = [
                        'code' => 'FZMOVIES_EMPTY',
                        'message' => (string) ($fz['error'] ?? 'No FzMovies streams'),
                        'severity' => 'warning',
                        'provider' => 'fzmovies',
                    ];
                }
                continue;
            }

"""

if "if ($provider === 'fzmovies')" not in t:
    needle2 = "'provider' => 'yesmovies',\n                    ];\n                }\n                continue;\n            }\n"
    idx = t.find("YESMOVIES_EMPTY")
    if idx < 0:
        raise SystemExit("YESMOVIES_EMPTY missing")
    pos2 = t.find(needle2, idx)
    if pos2 < 0:
        raise SystemExit("could not find yesmovies end after YESMOVIES_EMPTY")
    insert_at = pos2 + len(needle2)
    t = t[:insert_at] + "\n" + branch + t[insert_at:]
    print("player branch: ok")
else:
    print("player branch: already")

p.write_text(t)
print("PlayerSources fzmovies refs:", t.count("fzmovies"))

# 4) VidmolySources insecure TLS
p = Path("/var/www/chillflix-newsite/app/Services/VidmolySources.php")
t = p.read_text()
if "X-Vuflix-Ssl" in t:
    print("VidmolySources: already")
else:
    old = """        $rewrite = !empty($data['r']);

        // Binary progressive MP4 (MovieBox/HollyBox/etc.) needs real Range/Content-Range
        // streaming. The buffered proxyFetch path drops Content-Range and rewrites
        // Content-Length to the chunk size, which leaves HTML5 video stuck at ~0:01.
        if (!$rewrite) {
            self::proxyStreamBinary($url, $reqHeaders);
            exit;
        }

        $result = self::proxyFetch($url, $reqHeaders);
"""
    new = """        $rewrite = !empty($data['r']);
        $insecureTls = false;
        foreach (array_keys($reqHeaders) as $hk) {
            if (strcasecmp((string) $hk, 'X-Vuflix-Ssl') === 0) {
                $insecureTls = strcasecmp((string) $reqHeaders[$hk], 'insecure') === 0;
                unset($reqHeaders[$hk]);
            }
        }

        // Binary progressive MP4 (MovieBox/HollyBox/etc.) needs real Range/Content-Range
        // streaming. The buffered proxyFetch path drops Content-Range and rewrites
        // Content-Length to the chunk size, which leaves HTML5 video stuck at ~0:01.
        if (!$rewrite) {
            self::proxyStreamBinary($url, $reqHeaders, $insecureTls);
            exit;
        }

        $result = self::proxyFetch($url, $reqHeaders, $insecureTls);
"""
    if old not in t:
        raise SystemExit("proxyMedia needle missing")
    t = t.replace(old, new, 1)

    old = """    private static function proxyStreamBinary(string $url, array $headers): void
    {
        if (!function_exists('curl_init')) {
            http_response_code(500);
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'error' => 'curl missing']);
            exit;
        }
"""
    new = """    private static function proxyStreamBinary(string $url, array $headers, bool $insecureTls = false): void
    {
        if (!function_exists('curl_init')) {
            http_response_code(500);
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'error' => 'curl missing']);
            exit;
        }
"""
    if old not in t:
        raise SystemExit("proxyStreamBinary sig missing")
    t = t.replace(old, new, 1)

    old = """        $ch = curl_init($fetchUrl);
        curl_setopt_array($ch, [
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_CONNECTTIMEOUT => 12,
            CURLOPT_TIMEOUT => 0,
            CURLOPT_HTTPHEADER => $hdrLines,
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_HEADER => false,
            CURLOPT_ENCODING => '',
            CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
"""
    new = """        $ch = curl_init($fetchUrl);
        curl_setopt_array($ch, [
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_CONNECTTIMEOUT => 12,
            CURLOPT_TIMEOUT => 0,
            CURLOPT_HTTPHEADER => $hdrLines,
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_HEADER => false,
            CURLOPT_ENCODING => '',
            CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
            CURLOPT_SSL_VERIFYPEER => !$insecureTls,
            CURLOPT_SSL_VERIFYHOST => $insecureTls ? 0 : 2,
"""
    if old not in t:
        raise SystemExit("proxyStreamBinary curl opts missing")
    t = t.replace(old, new, 1)

    if "private static function proxyFetch(string $url, array $headers, bool $insecureTls = false)" not in t:
        if "private static function proxyFetch(string $url, array $headers)" not in t:
            raise SystemExit("proxyFetch missing")
        t = t.replace(
            "private static function proxyFetch(string $url, array $headers)",
            "private static function proxyFetch(string $url, array $headers, bool $insecureTls = false)",
            1,
        )
        idx = t.find("private static function proxyFetch(string $url, array $headers, bool $insecureTls = false)")
        ssl = t.find("CURLOPT_SSL_VERIFYPEER => true,", idx)
        if ssl < 0 or ssl > idx + 3000:
            raise SystemExit("proxyFetch SSL not found")
        t = (
            t[:ssl]
            + "CURLOPT_SSL_VERIFYPEER => !$insecureTls,\n            CURLOPT_SSL_VERIFYHOST => $insecureTls ? 0 : 2,"
            + t[ssl + len("CURLOPT_SSL_VERIFYPEER => true,") :]
        )
        print("proxyFetch: patched")

    p.write_text(t)
    print("VidmolySources: patched")

print("DONE")
