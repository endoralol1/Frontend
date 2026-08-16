<?php
declare(strict_types=1);

/**
 * Multi-audio / CDN fixes for Vuflix scrapers.
 *
 * Modes:
 * - master_prefer: rewrite HLS master so English is DEFAULT (legacy)
 * - hls_edge: proxy full HLS tree (playlists + segments), English-first on masters
 * - ts_playlist / ts_segment: Castle MPEG-TS language remux (-copyts + cache)
 */
final class StreamLangProxy
{
    public const LANG_MAP = [
        'en' => 'eng', 'eng' => 'eng', 'english' => 'eng',
        'hi' => 'hin', 'hin' => 'hin', 'hindi' => 'hin',
        'ta' => 'tam', 'tam' => 'tam', 'tamil' => 'tam',
        'te' => 'tel', 'tel' => 'tel', 'telugu' => 'tel',
    ];

    private static function cacheDir(): string
    {
        $dir = '/var/www/chillflix-newsite/storage/cache/lang-proxy';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        return $dir;
    }

    public static function mintMasterPrefer(string $url, string $lang = 'eng'): string
    {
        return self::mint($url, 'hls_edge', $lang);
    }

    public static function mintHlsEdge(string $url, string $lang = 'eng'): string
    {
        return self::mint($url, 'hls_edge', $lang);
    }

    public static function mintTsPlaylist(string $url, string $lang = 'eng'): string
    {
        return self::mint($url, 'ts_playlist', $lang);
    }

    /** Proxy an HLS URL with optional request headers (AwsInd Referer, etc.). */
    public static function mintHlsHeaders(string $url, array $headers = [], string $lang = 'eng'): string
    {
        return self::mint($url, 'hls_edge', $lang, $headers);
    }

    /** @param array<string,string> $headers */
    private static function headerCachePut(array $headers): string
    {
        $json = json_encode($headers, JSON_UNESCAPED_SLASHES) ?: '{}';
        $key = substr(hash_hmac('sha256', $json, self::secret()), 0, 32);
        $file = self::cacheDir() . '/hdr_' . $key . '.json';
        if (!is_file($file)) {
            @file_put_contents($file, $json, LOCK_EX);
        }
        return $key;
    }

    /** @return array<string,string> */
    private static function headerCacheGet(string $key): array
    {
        if ($key === '' || !preg_match('/^[a-f0-9]{16,64}$/', $key)) {
            return [];
        }
        $file = self::cacheDir() . '/hdr_' . $key . '.json';
        if (!is_file($file)) {
            return [];
        }
        $raw = @file_get_contents($file);
        $data = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($data)) {
            return [];
        }
        $out = [];
        foreach ($data as $k => $v) {
            $k = trim((string) $k);
            $v = trim((string) $v);
            if ($k !== '' && $v !== '' && !preg_match('/[\r\n]/', $k . $v)) {
                $out[$k] = $v;
            }
        }
        return $out;
    }

    public static function mint(string $url, string $mode, string $lang = 'eng', array $headers = []): string
    {
        $lang = self::normalizeLang($lang);
        $payload = [
            'u' => $url,
            'e' => time() + 7200,
            'm' => $mode,
            'l' => $lang,
        ];
        // Cache CDN headers server-side so media playlists stay small (Sirius has 1.6k segs).
        if ($headers !== []) {
            $payload['hh'] = self::headerCachePut($headers);
        }
        $body = rtrim(strtr(base64_encode(json_encode($payload, JSON_UNESCAPED_SLASHES) ?: '{}'), '+/', '-_'), '=');
        $sig = hash_hmac('sha256', $body, self::secret());
        return url('/api/player/lang-proxy?' . http_build_query(['t' => $body, 's' => $sig]));
    }

    public static function handle(string $token, string $sig): void
    {
        if ($token === '' || $sig === '' || !hash_equals(hash_hmac('sha256', $token, self::secret()), $sig)) {
            http_response_code(403);
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'error' => 'Invalid lang-proxy token']);
            exit;
        }
        $pad = strlen($token) % 4;
        $json = base64_decode(strtr($token, '-_', '+/') . ($pad ? str_repeat('=', 4 - $pad) : ''), true);
        $data = is_string($json) ? json_decode($json, true) : null;
        if (!is_array($data) || (int) ($data['e'] ?? 0) < time()) {
            http_response_code(403);
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'error' => 'Expired or bad token']);
            exit;
        }
        $url = trim((string) ($data['u'] ?? ''));
        $mode = (string) ($data['m'] ?? '');
        $lang = self::normalizeLang((string) ($data['l'] ?? 'eng'));
        $headers = [];
        foreach (($data['h'] ?? []) as $k => $v) {
            $k = trim((string) $k);
            $v = trim((string) $v);
            if ($k !== '' && $v !== '' && !preg_match('/[\r\n]/', $k . $v)) {
                $headers[$k] = $v;
            }
        }
        if ($headers === [] && !empty($data['hh'])) {
            $headers = self::headerCacheGet((string) $data['hh']);
        }
        if ($url === '' || !preg_match('#^https?://#i', $url)) {
            http_response_code(400);
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'error' => 'Invalid url']);
            exit;
        }

        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Headers: *');
        header('X-Content-Type-Options: nosniff');

        if ($mode === 'master_prefer' || $mode === 'hls_edge') {
            self::serveHlsEdge($url, $lang, $headers);
        }
        if ($mode === 'ts_playlist') {
            self::serveTsPlaylist($url, $lang);
        }
        if ($mode === 'ts_segment') {
            self::serveTsSegment($url, $lang);
        }

        http_response_code(400);
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'error' => 'Unknown mode']);
        exit;
    }

    /** @param array<string,string> $headers */
    private static function serveHlsEdge(string $url, string $lang, array $headers): void
    {
        $bin = self::httpGetBinary($url, $headers);
        if ($bin === null || $bin === '') {
            http_response_code(502);
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'error' => 'Upstream fetch failed']);
            exit;
        }

        $trim = ltrim($bin);
        $isPlaylist = str_starts_with($trim, '#EXTM3U') || str_contains(strtolower($url), '.m3u8');
        if (!$isPlaylist) {
            // Segment / binary — force a demuxer-friendly type (CDN often lies with image/jpeg).
            header('Content-Type: video/mp2t');
            header('Cache-Control: public, max-age=300');
            echo $bin;
            exit;
        }

        $text = $bin;
        // Prefer English on masters that expose EXT-X-MEDIA audio.
        if (str_contains($text, 'TYPE=AUDIO')) {
            $text = self::rewriteMasterPreferEnglish($text, $url, $lang, $headers);
        } else {
            $text = self::rewritePlaylistUrls($text, $url, $lang, $headers);
        }

        $text = self::sanitizeDanglingSubtitleRefs($text);
        header('Content-Type: application/vnd.apple.mpegurl');
        header('Cache-Control: private, max-age=30');
        echo $text;
        exit;
    }

    /** Sirius/HDGHAR masters often reference SUBTITLES="subs" with no tracks — breaks HLS.js. */
    private static function sanitizeDanglingSubtitleRefs(string $body): string
    {
        if (preg_match('/TYPE=SUBTITLES/i', $body)) {
            return $body;
        }
        return preg_replace('/,?\s*SUBTITLES="[^"]*"/i', '', $body) ?? $body;
    }

    /** @param array<string,string> $headers */
    private static function rewriteMasterPreferEnglish(string $raw, string $base, string $lang, array $headers = []): string
    {
        $wantNames = array_map('strtolower', self::langDisplayNames($lang));
        $wantNames[] = strtolower($lang);
        $lines = preg_split("/\r\n|\n|\r/", $raw) ?: [];
        $headersOut = [];
        $audioWant = [];
        $audioOther = [];
        $subs = [];
        $streams = [];
        $pending = null;

        foreach ($lines as $line) {
            $trim = trim($line);
            if ($trim === '') continue;
            if ($trim === '#EXTM3U' || str_starts_with($trim, '#EXT-X-VERSION') || str_starts_with($trim, '#EXT-X-INDEPENDENT-SEGMENTS')) {
                $headersOut[] = $trim;
                continue;
            }
            if (str_starts_with($trim, '#EXT-X-MEDIA:') && str_contains($trim, 'TYPE=AUDIO')) {
                $name = '';
                $language = '';
                if (preg_match('/NAME="([^"]+)"/i', $trim, $m)) $name = strtolower($m[1]);
                if (preg_match('/LANGUAGE="([^"]+)"/i', $trim, $m)) $language = strtolower($m[1]);
                $isWant = in_array($name, $wantNames, true) || in_array($language, $wantNames, true);
                $line = preg_replace('/,?\s*DEFAULT=(YES|NO)/i', '', $trim) ?? $trim;
                $line = preg_replace('/,?\s*AUTOSELECT=(YES|NO)/i', '', $line) ?? $line;
                $flags = $isWant ? 'AUTOSELECT=YES,DEFAULT=YES' : 'AUTOSELECT=YES,DEFAULT=NO';
                if (preg_match('/URI="([^"]+)"/i', $line, $m)) {
                    $abs = self::absolutize($m[1], $base);
                    $prox = self::mint($abs, 'hls_edge', $lang, $headers);
                    // Keep AUTOSELECT/DEFAULT before URI — trailing attrs break some HLS.js builds.
                    $line = preg_replace('/,?\s*URI="[^"]*"/i', '', $line) ?? $line;
                    $line = rtrim($line, ',') . ',' . $flags . ',URI="' . $prox . '"';
                } else {
                    $line = rtrim($line, ',') . ',' . $flags;
                }
                if ($isWant) $audioWant[] = $line; else $audioOther[] = $line;
                continue;
            }
            if (str_starts_with($trim, '#EXT-X-MEDIA:')) {
                if (preg_match('/URI="([^"]+)"/i', $trim, $m)) {
                    $abs = self::absolutize($m[1], $base);
                    $prox = self::mint($abs, 'hls_edge', $lang, $headers);
                    $trim = str_replace($m[0], 'URI="' . $prox . '"', $trim);
                }
                $subs[] = $trim;
                continue;
            }
            if (str_starts_with($trim, '#EXT-X-STREAM-INF:')) {
                $pending = $trim;
                continue;
            }
            if ($pending !== null) {
                $abs = self::absolutize($trim, $base);
                $streams[] = $pending;
                $streams[] = self::mint($abs, 'hls_edge', $lang, $headers);
                $pending = null;
                continue;
            }
        }
        if ($headersOut === []) $headersOut = ['#EXTM3U'];
        return implode("\n", array_merge($headersOut, $audioWant, $audioOther, $subs, $streams)) . "\n";
    }

    /** @param array<string,string> $reqHeaders */
    private static function rewritePlaylistUrls(string $raw, string $base, string $lang, array $reqHeaders = []): string
    {
        $lines = preg_split("/\r\n|\n|\r/", $raw) ?: [];
        $out = [];
        foreach ($lines as $line) {
            $trim = trim($line);
            if ($trim === '') { $out[] = $line; continue; }
            if (str_starts_with($trim, '#')) {
                if (preg_match('/URI="([^"]+)"/i', $line, $m)) {
                    $abs = self::absolutize($m[1], $base);
                    $prox = self::mint($abs, 'hls_edge', $lang, $reqHeaders);
                    $line = str_replace($m[0], 'URI="' . $prox . '"', $line);
                }
                $out[] = $line;
                continue;
            }
            $abs = self::absolutize($trim, $base);
            $out[] = self::mint($abs, 'hls_edge', $lang, $reqHeaders);
        }
        return implode("\n", $out) . "\n";
    }

    private static function serveTsPlaylist(string $url, string $lang): void
    {
        $raw = self::httpGetBinary($url);
        if ($raw === null || $raw === '') {
            http_response_code(502);
            exit;
        }
        $lines = preg_split("/\r\n|\n|\r/", $raw) ?: [];
        $out = [];
        $segUrls = [];
        foreach ($lines as $line) {
            $trim = trim($line);
            if ($trim === '' || str_starts_with($trim, '#')) {
                $out[] = $line;
                continue;
            }
            $abs = self::absolutize($trim, $url);
            $segUrls[] = $abs;
            $out[] = self::mint($abs, 'ts_segment', $lang);
        }
        self::warmSegmentsAsync(array_slice($segUrls, 0, 12), $lang);
        header('Content-Type: application/vnd.apple.mpegurl');
        header('Cache-Control: private, max-age=30');
        echo implode("\n", $out) . "\n";
        exit;
    }

    /** @param list<string> $urls */
    private static function warmSegmentsAsync(array $urls, string $lang): void
    {
        if ($urls === []) return;
        $ffmpeg = self::ffmpegPath();
        if ($ffmpeg === null) return;
        $tmp = tempnam(sys_get_temp_dir(), 'cfwarm_');
        if ($tmp === false) return;
        file_put_contents($tmp, json_encode([
            'urls' => array_values($urls),
            'lang' => $lang,
            'cache' => self::cacheDir(),
            'ffmpeg' => $ffmpeg,
        ], JSON_UNESCAPED_SLASHES));
        $script = '/var/www/chillflix-newsite/bin/lang-proxy-warm.php';
        if (!is_readable($script)) { @unlink($tmp); return; }
        exec(sprintf('nohup php %s %s > /dev/null 2>&1 &', escapeshellarg($script), escapeshellarg($tmp)));
    }

    private static function serveTsSegment(string $url, string $lang): void
    {
        $cacheFile = self::cacheDir() . '/' . hash('sha256', $url . '|' . $lang) . '.ts';
        if (is_file($cacheFile) && filesize($cacheFile) > 64) {
            header('Content-Type: video/mp2t');
            header('Content-Length: ' . (string) filesize($cacheFile));
            header('Cache-Control: public, max-age=3600');
            header('X-Lang-Proxy-Cache: HIT');
            readfile($cacheFile);
            exit;
        }
        $ffmpeg = self::ffmpegPath();
        if ($ffmpeg === null) {
            $bin = self::httpGetBinary($url);
            if ($bin === null) { http_response_code(502); exit; }
            header('Content-Type: video/mp2t');
            echo $bin;
            exit;
        }
        $tmpIn = tempnam(sys_get_temp_dir(), 'cfin_');
        $tmpOut = tempnam(sys_get_temp_dir(), 'cfout_');
        if ($tmpIn === false || $tmpOut === false) { http_response_code(500); exit; }
        @unlink($tmpOut);
        $tmpOut .= '.ts';
        try {
            $bin = self::httpGetBinary($url);
            if ($bin === null || $bin === '') { http_response_code(502); exit; }
            file_put_contents($tmpIn, $bin);
            $cmd = sprintf(
                '%s -hide_banner -loglevel error -y -copyts -i %s -map 0:v:0 -map 0:a:m:language:%s -c copy -muxdelay 0 -muxpreload 0 -f mpegts %s 2>&1',
                escapeshellcmd($ffmpeg), escapeshellarg($tmpIn), escapeshellarg($lang), escapeshellarg($tmpOut)
            );
            $desc = []; $code = 0; exec($cmd, $desc, $code);
            if ($code !== 0 || !is_file($tmpOut) || filesize($tmpOut) < 64) {
                $cmd2 = sprintf(
                    '%s -hide_banner -loglevel error -y -copyts -i %s -map 0:v:0 -map 0:a:0 -c copy -muxdelay 0 -muxpreload 0 -f mpegts %s 2>&1',
                    escapeshellcmd($ffmpeg), escapeshellarg($tmpIn), escapeshellarg($tmpOut)
                );
                exec($cmd2, $desc, $code);
            }
            if ($code !== 0 || !is_file($tmpOut) || filesize($tmpOut) < 64) {
                header('Content-Type: video/mp2t'); echo $bin; exit;
            }
            if (!@rename($tmpOut, $cacheFile)) {
                @copy($tmpOut, $cacheFile);
            } else {
                $tmpOut = $cacheFile;
            }
            header('Content-Type: video/mp2t');
            header('Content-Length: ' . (string) filesize($tmpOut));
            header('Cache-Control: public, max-age=3600');
            header('X-Lang-Proxy-Cache: MISS');
            readfile($tmpOut);
            exit;
        } finally {
            @unlink($tmpIn);
            if (is_string($tmpOut) && is_file($tmpOut) && $tmpOut !== $cacheFile) @unlink($tmpOut);
        }
    }

    private static function ffmpegPath(): ?string
    {
        foreach (['/usr/bin/ffmpeg', '/usr/local/bin/ffmpeg'] as $p) {
            if (is_executable($p)) return $p;
        }
        return null;
    }

    private static function normalizeLang(string $lang): string
    {
        $l = strtolower(trim($lang));
        return self::LANG_MAP[$l] ?? (strlen($l) === 3 ? $l : 'eng');
    }

    /** @return list<string> */
    private static function langDisplayNames(string $iso6392): array
    {
        return match ($iso6392) {
            'eng' => ['English', 'En', 'ENG', 'en'],
            'hin' => ['Hindi', 'Hi', 'HIN', 'hi'],
            'tam' => ['Tamil', 'Ta', 'TAM', 'ta'],
            'tel' => ['Telugu', 'Te', 'TEL', 'te'],
            default => [ucfirst($iso6392)],
        };
    }

    private static function absolutize(string $maybeRelative, string $base): string
    {
        if (preg_match('#^https?://#i', $maybeRelative)) return $maybeRelative;
        $bp = parse_url($base);
        if (!is_array($bp) || empty($bp['scheme']) || empty($bp['host'])) return $maybeRelative;
        $origin = $bp['scheme'] . '://' . $bp['host'] . (isset($bp['port']) ? (':' . $bp['port']) : '');
        if (str_starts_with($maybeRelative, '/')) return $origin . $maybeRelative;
        $path = (string) ($bp['path'] ?? '/');
        $dir = preg_replace('#/[^/]*$#', '/', $path) ?: '/';
        $joined = $origin . $dir . $maybeRelative;
        // Collapse /./ and duplicate slashes in the path (keep ://).
        $parts = explode('://', $joined, 2);
        if (count($parts) === 2) {
            $parts[1] = preg_replace('#/(\./)+#', '/', $parts[1]) ?? $parts[1];
            $joined = $parts[0] . '://' . $parts[1];
        }
        return $joined;
    }

    /** @param array<string,string> $extraHeaders */
    private static function httpGetBinary(string $url, array $extraHeaders = []): ?string
    {
        if (!function_exists('curl_init')) return null;
        $headers = ['User-Agent: VuflixLangProxy/1.2', 'Accept: */*'];
        foreach ($extraHeaders as $k => $v) {
            $headers[] = $k . ': ' . $v;
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 12,
            CURLOPT_TIMEOUT => 45,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if (!is_string($body) || $body === '' || $code < 200 || $code >= 400) return null;
        return $body;
    }

    private static function secret(): string
    {
        $secret = (string) config('auth_secret', '');
        return $secret !== '' ? $secret : 'vuflix-lang-proxy';
    }
}
