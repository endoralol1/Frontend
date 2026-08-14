#!/usr/bin/env python3
"""Fix VidmolySources::proxyMedia to stream binary (MP4) with Content-Range."""
from pathlib import Path

path = Path("/var/www/chillflix-newsite/app/Services/VidmolySources.php")
text = path.read_text()

old = """        $rewrite = !empty($data['r']);

        $result = self::proxyFetch($url, $reqHeaders);
        if ($result === null) {
            http_response_code(502);
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'error' => 'Media proxy fetch failed']);
            exit;
        }

        $status = (int) ($result['status'] ?? 502);
        $body = (string) ($result['body'] ?? '');
        $ctype = strtolower((string) ($result['contentType'] ?? ''));
        http_response_code($status > 0 ? $status : 502);
        header('Access-Control-Allow-Origin: *');
        header('X-Content-Type-Options: nosniff');

        $isPlaylist = str_contains($ctype, 'mpegurl')
            || str_contains($ctype, 'application/vnd.apple.mpegurl')
            || str_contains($ctype, 'audio/mpegurl')
            || str_starts_with(ltrim($body), '#EXTM3U')
            || preg_match('/\\.m3u8(\\\\?|$)/i', $url);

        if ($rewrite && $isPlaylist && $status >= 200 && $status < 300) {
            header('Content-Type: application/vnd.apple.mpegurl');
            echo self::rewritePlaylist($body, $url, $reqHeaders);
            exit;
        }

        if ($ctype !== '') {
            header('Content-Type: ' . $ctype);
        } elseif ($isPlaylist) {
            header('Content-Type: application/vnd.apple.mpegurl');
        }
        if (!empty($result['contentLength'])) {
            header('Content-Length: ' . (string) $result['contentLength']);
        }
        echo $body;
        exit;
    }
"""

# The regex escape in the file is /\.m3u8(\?|$)/i - in the PHP source it's a single backslash before .
# Let me read the exact bytes from the file instead.

start = text.find("        $rewrite = !empty($data['r']);")
end = text.find("    /**\n     * @param array<string,string> $headers", start)
if start < 0 or end < 0:
    raise SystemExit(f"markers not found start={start} end={end}")

# Only replace the first proxyMedia body occurrence (between rewrite and next docblock after proxyMedia)
chunk = text[start:end]
if "proxyStreamBinary" in chunk:
    print("already patched")
else:
    new = r'''        $rewrite = !empty($data['r']);

        // Binary progressive MP4 (MovieBox/HollyBox/etc.) needs real Range/Content-Range
        // streaming. The buffered proxyFetch path drops Content-Range and rewrites
        // Content-Length to the chunk size, which leaves HTML5 video stuck at ~0:01.
        if (!$rewrite) {
            self::proxyStreamBinary($url, $reqHeaders);
            exit;
        }

        $result = self::proxyFetch($url, $reqHeaders);
        if ($result === null) {
            http_response_code(502);
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'error' => 'Media proxy fetch failed']);
            exit;
        }

        $status = (int) ($result['status'] ?? 502);
        $body = (string) ($result['body'] ?? '');
        $ctype = strtolower((string) ($result['contentType'] ?? ''));
        http_response_code($status > 0 ? $status : 502);
        header('Access-Control-Allow-Origin: *');
        header('X-Content-Type-Options: nosniff');

        $isPlaylist = str_contains($ctype, 'mpegurl')
            || str_contains($ctype, 'application/vnd.apple.mpegurl')
            || str_contains($ctype, 'audio/mpegurl')
            || str_starts_with(ltrim($body), '#EXTM3U')
            || preg_match('/\.m3u8(\?|$)/i', $url);

        if ($rewrite && $isPlaylist && $status >= 200 && $status < 300) {
            header('Content-Type: application/vnd.apple.mpegurl');
            echo self::rewritePlaylist($body, $url, $reqHeaders);
            exit;
        }

        if ($ctype !== '') {
            header('Content-Type: ' . $ctype);
        } elseif ($isPlaylist) {
            header('Content-Type: application/vnd.apple.mpegurl');
        }
        if (!empty($result['contentLength'])) {
            header('Content-Length: ' . (string) $result['contentLength']);
        }
        echo $body;
        exit;
    }

    /**
     * Stream remote media with Range support (forwards Content-Range / Accept-Ranges).
     *
     * @param array<string,string> $headers
     */
    private static function proxyStreamBinary(string $url, array $headers): void
    {
        if (!function_exists('curl_init')) {
            http_response_code(500);
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'error' => 'curl missing']);
            exit;
        }

        $hdrLines = ['User-Agent: ' . self::UA];
        foreach ($headers as $k => $v) {
            $hdrLines[] = $k . ': ' . $v;
        }
        $range = (string) ($_SERVER['HTTP_RANGE'] ?? '');
        if ($range !== '' && !preg_match('/[\r\n]/', $range)) {
            $hdrLines[] = 'Range: ' . $range;
        }

        $ch = curl_init($url);
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
            CURLOPT_WRITEFUNCTION => static function ($ch, $chunk) {
                echo $chunk;
                return strlen($chunk);
            },
            CURLOPT_HEADERFUNCTION => static function ($ch, $headerLine) {
                $len = strlen($headerLine);
                $headerLine = trim($headerLine);
                if ($headerLine === '' || str_contains($headerLine, ':') === false) {
                    if (preg_match('#^HTTP/\S+\s+(\d+)#', $headerLine, $m)) {
                        http_response_code((int) $m[1]);
                    }
                    return $len;
                }
                [$name, $value] = array_map('trim', explode(':', $headerLine, 2));
                $lname = strtolower($name);
                if (in_array($lname, ['content-type', 'content-length', 'accept-ranges', 'content-range', 'cache-control'], true)) {
                    header($name . ': ' . $value, true);
                }
                return $len;
            },
        ]);
        header('Access-Control-Allow-Origin: *');
        header('X-Content-Type-Options: nosniff');
        // Ensure browsers know Range is supported even if upstream omits Accept-Ranges.
        header('Accept-Ranges: bytes', false);
        $ok = curl_exec($ch);
        $errno = curl_errno($ch);
        curl_close($ch);
        if ($ok === false && $errno) {
            if (!headers_sent()) {
                http_response_code(502);
                header('Content-Type: application/json');
            }
            echo json_encode(['ok' => false, 'error' => 'Media proxy fetch failed']);
        }
        exit;
    }
'''
    text = text[:start] + new + text[end:]
    path.write_text(text)
    print("patched proxyStreamBinary")

import subprocess
r = subprocess.run(["php", "-l", str(path)], capture_output=True, text=True)
print(r.stdout.strip() or r.stderr.strip())
raise SystemExit(r.returncode)
