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

    /** @var array<string,true> temp paths to unlink even if FPM SIGKILLs mid-request (best-effort via shutdown) */
    private static array $trackedTemps = [];
    private static bool $shutdownRegistered = false;

    private static function cacheDir(): string
    {
        $dir = '/var/www/chillflix-newsite/storage/cache/lang-proxy';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        return $dir;
    }

    /** Create a tracked temp file; always cleaned in finally + shutdown. */
    private static function makeTemp(string $prefix): string|false
    {
        $tmp = tempnam(sys_get_temp_dir(), $prefix);
        if ($tmp === false) {
            return false;
        }
        self::$trackedTemps[$tmp] = true;
        if (!self::$shutdownRegistered) {
            self::$shutdownRegistered = true;
            register_shutdown_function(static function (): void {
                foreach (array_keys(self::$trackedTemps) as $path) {
                    if (is_string($path) && $path !== '' && is_file($path)) {
                        @unlink($path);
                    }
                }
                self::$trackedTemps = [];
            });
        }
        return $tmp;
    }

    private static function releaseTemp(?string $path): void
    {
        if ($path === null || $path === '') {
            return;
        }
        @unlink($path);
        unset(self::$trackedTemps[$path]);
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
        $bind = class_exists('ProxyGuard') ? ProxyGuard::tokenBindFields() : ['e' => time() + 900];
        $payload = [
            'u' => $url,
            'e' => (int) ($bind['e'] ?? (time() + 900)),
            'm' => $mode,
            'l' => $lang,
            'sid' => (string) ($bind['sid'] ?? ''),
            'ip' => (string) ($bind['ip'] ?? ''),
        ];
        // Cache CDN headers server-side so media playlists stay small (Sirius has 1.6k segs).
        if ($headers !== []) {
            $payload['hh'] = self::headerCachePut($headers);
        }
        $body = rtrim(strtr(base64_encode(json_encode($payload, JSON_UNESCAPED_SLASHES) ?: '{}'), '+/', '-_'), '=');
        $sig = hash_hmac('sha256', $body, self::secret());
        return url('/api/player/a-relay?' . http_build_query(['t' => $body, 's' => $sig]));
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
        if (class_exists('ProxyGuard')) {
            ProxyGuard::assertTokenBinding($data);
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
        // Segments never need rewrite — stream straight through (no /tmp/cfhls_* disk).
        // Old path downloaded 50–100MB+ to disk then readfile(); FPM request_terminate_timeout=120
        // SIGKILLs workers and skips finally → multi-GB orphan leak in /tmp.
        $lower = strtolower($url);
        // HDGHAR/streamraiwind disguises MPEG-TS as .jpg/.jpeg/.png/.bin
        $looksSegment = (bool) preg_match('/\.(ts|m4s|mp4|aac|cmfv|cmfa|jpg|jpeg|png|bin)(\?|$)/i', $lower);
        $looksPlaylist = str_contains($lower, '.m3u8');
        if ($looksSegment && !$looksPlaylist) {
            if (!self::httpPassthroughBinary($url, $headers, 'video/mp2t')) {
                http_response_code(502);
            }
            exit;
        }

        // Playlists only: small text body, rewrite English-first, then delete temp.
        $tmp = self::makeTemp('cfhls_');
        if ($tmp === false) {
            http_response_code(500);
            exit;
        }
        try {
            // Playlists are tiny — hard-cap so a mislabeled segment cannot refill /tmp.
            $meta = self::httpDownloadToFile($url, $tmp, $headers, 8 * 1024 * 1024);
            if ($meta === null || !is_file($tmp) || filesize($tmp) < 1) {
                // Not a small playlist — likely a segment without extension. Passthrough, no disk keep.
                self::releaseTemp($tmp);
                if (!self::httpPassthroughBinary($url, $headers, 'video/mp2t')) {
                    http_response_code(502);
                    header('Content-Type: application/json');
                    echo json_encode(['ok' => false, 'error' => 'Upstream fetch failed']);
                }
                exit;
            }

            $fh = @fopen($tmp, 'rb');
            $peek = $fh ? (string) fread($fh, 2048) : '';
            if ($fh) {
                fclose($fh);
            }
            $trim = ltrim($peek);
            $isPlaylist = str_starts_with($trim, '#EXTM3U') || $looksPlaylist;

            if (!$isPlaylist) {
                // Peek says binary segment — do not keep the temp; re-fetch as passthrough.
                self::releaseTemp($tmp);
                if (!self::httpPassthroughBinary($url, $headers, 'video/mp2t')) {
                    http_response_code(502);
                }
                exit;
            }

            $text = (string) file_get_contents($tmp);
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
        } finally {
            self::releaseTemp($tmp);
        }
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
                // HDGHAR often lists duplicate English subtitle rows — HLS.js can choke.
                $subKey = '';
                if (preg_match('/NAME="([^"]+)"/i', $trim, $m)) {
                    $subKey .= strtolower($m[1]);
                }
                if (preg_match('/LANGUAGE="([^"]+)"/i', $trim, $m)) {
                    $subKey .= '|' . strtolower($m[1]);
                }
                if ($subKey === '') {
                    $subKey = 'row-' . count($subs);
                }
                if (!isset($subs[$subKey])) {
                    $subs[$subKey] = $trim;
                }
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
        return implode("\n", array_merge($headersOut, $audioWant, $audioOther, array_values($subs), $streams)) . "\n";
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
            // Stream straight to the client — never buffer the whole segment in RAM.
            if (!self::httpPassthroughBinary($url, [], 'video/mp2t')) {
                http_response_code(502);
            }
            exit;
        }
        $tmpIn = self::makeTemp('cfin_');
        $tmpOutBase = self::makeTemp('cfout_');
        if ($tmpIn === false || $tmpOutBase === false) { http_response_code(500); exit; }
        @unlink($tmpOutBase);
        unset(self::$trackedTemps[$tmpOutBase]);
        $tmpOut = $tmpOutBase . '.ts';
        self::$trackedTemps[$tmpOut] = true;
        try {
            $meta = self::httpDownloadToFile($url, $tmpIn, [], 120 * 1024 * 1024);
            if ($meta === null || !is_file($tmpIn) || filesize($tmpIn) < 1) { http_response_code(502); exit; }
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
                header('Content-Type: video/mp2t');
                header('Content-Length: ' . (string) filesize($tmpIn));
                readfile($tmpIn);
                exit;
            }
            if (!@rename($tmpOut, $cacheFile)) {
                @copy($tmpOut, $cacheFile);
            } else {
                unset(self::$trackedTemps[$tmpOut]);
                $tmpOut = $cacheFile;
            }
            header('Content-Type: video/mp2t');
            header('Content-Length: ' . (string) filesize($tmpOut));
            header('Cache-Control: public, max-age=3600');
            header('X-Lang-Proxy-Cache: MISS');
            readfile($tmpOut);
            exit;
        } finally {
            self::releaseTemp($tmpIn);
            if (is_string($tmpOut) && is_file($tmpOut) && $tmpOut !== $cacheFile) {
                self::releaseTemp($tmpOut);
            }
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

    /**
     * Small responses only (playlists / tiny payloads). Hard-capped to avoid OOM.
     * @param array<string,string> $extraHeaders
     */
    private static function httpGetBinary(string $url, array $extraHeaders = [], int $maxBytes = 4 * 1024 * 1024): ?string
    {
        $tmp = self::makeTemp('cfget_');
        if ($tmp === false) {
            return null;
        }
        try {
            $meta = self::httpDownloadToFile($url, $tmp, $extraHeaders, $maxBytes);
            if ($meta === null || !is_file($tmp)) {
                return null;
            }
            $size = filesize($tmp);
            if ($size === false || $size < 1 || $size > $maxBytes) {
                return null;
            }
            $body = file_get_contents($tmp);
            return is_string($body) && $body !== '' ? $body : null;
        } finally {
            self::releaseTemp($tmp);
        }
    }

    /**
     * Stream upstream body straight to the HTTP client (no PHP string buffer).
     * @param array<string,string> $extraHeaders
     */
    private static function httpPassthroughBinary(string $url, array $extraHeaders, string $contentType): bool
    {
        if (!function_exists('curl_init')) {
            return false;
        }
        $headers = ['User-Agent: VuflixLangProxy/1.3', 'Accept: */*'];
        foreach ($extraHeaders as $k => $v) {
            $headers[] = $k . ': ' . $v;
        }
        header('Content-Type: ' . $contentType);
        header('Cache-Control: public, max-age=300');
        if (function_exists('apache_setenv')) {
            @apache_setenv('no-gzip', '1');
        }
        @ini_set('zlib.output_compression', '0');
        while (ob_get_level() > 0) {
            @ob_end_flush();
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 12,
            CURLOPT_TIMEOUT => 120,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
            CURLOPT_WRITEFUNCTION => static function ($ch, string $chunk): int {
                echo $chunk;
                return strlen($chunk);
            },
        ]);
        $ok = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return $ok !== false && $code >= 200 && $code < 400;
    }

    /**
     * Download to a file via curl streaming (disk, not PHP RAM).
     * @param array<string,string> $extraHeaders
     * @return array{bytes:int,http_code:int}|null
     */
    private static function httpDownloadToFile(string $url, string $dest, array $extraHeaders = [], int $maxBytes = 0): ?array
    {
        if (!function_exists('curl_init')) {
            return null;
        }
        $fp = @fopen($dest, 'wb');
        if ($fp === false) {
            return null;
        }
        $headers = ['User-Agent: VuflixLangProxy/1.3', 'Accept: */*'];
        foreach ($extraHeaders as $k => $v) {
            $headers[] = $k . ': ' . $v;
        }
        $bytes = 0;
        $abort = false;
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 12,
            CURLOPT_TIMEOUT => 120,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
            CURLOPT_WRITEFUNCTION => static function ($ch, string $chunk) use ($fp, $maxBytes, &$bytes, &$abort): int {
                $len = strlen($chunk);
                $bytes += $len;
                if ($maxBytes > 0 && $bytes > $maxBytes) {
                    $abort = true;
                    return 0; // abort transfer
                }
                $written = fwrite($fp, $chunk);
                return $written === false ? 0 : (int) $written;
            },
        ]);
        $ok = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        fclose($fp);
        if ($abort || $ok === false || $code < 200 || $code >= 400 || $bytes < 1) {
            @unlink($dest);
            return null;
        }
        return ['bytes' => $bytes, 'http_code' => $code];
    }

    private static function secret(): string
    {
        $secret = (string) config('auth_secret', '');
        return $secret !== '' ? $secret : 'vuflix-lang-proxy';
    }
}
