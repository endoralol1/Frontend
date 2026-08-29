#!/usr/bin/env python3
from pathlib import Path

p = Path("/var/www/chillflix-newsite/app/Services/VidmolySources.php")
t = p.read_text()
if "X-Vuflix-Ssl" in t:
    print("already patched")
    raise SystemExit(0)

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
            CURLOPT_WRITEFUNCTION => static function ($ch, $chunk) {
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
            CURLOPT_WRITEFUNCTION => static function ($ch, $chunk) {
"""
if old not in t:
    raise SystemExit("proxyStreamBinary curl opts missing")
t = t.replace(old, new, 1)

old = """    private static function proxyFetch(string $url, array $headers): ?array
    {
        if (!function_exists('curl_init')) {
            return null;
        }
"""
new = """    private static function proxyFetch(string $url, array $headers, bool $insecureTls = false): ?array
    {
        if (!function_exists('curl_init')) {
            return null;
        }
"""
if old not in t:
    raise SystemExit("proxyFetch sig missing")
t = t.replace(old, new, 1)

old = """        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_CONNECTTIMEOUT => 12,
            CURLOPT_TIMEOUT => 45,
            CURLOPT_HTTPHEADER => $hdrLines,
            CURLOPT_ENCODING => '',
            CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
        ]);
        $body = curl_exec($ch);
        $errno = curl_errno($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $ctype = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        curl_close($ch);
        if (($body === false || $errno || $status >= 400) && self::shouldUseWorkerEgress($url)) {
"""
new = """        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_CONNECTTIMEOUT => 12,
            CURLOPT_TIMEOUT => 45,
            CURLOPT_HTTPHEADER => $hdrLines,
            CURLOPT_ENCODING => '',
            CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
            CURLOPT_SSL_VERIFYPEER => !$insecureTls,
            CURLOPT_SSL_VERIFYHOST => $insecureTls ? 0 : 2,
        ]);
        $body = curl_exec($ch);
        $errno = curl_errno($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $ctype = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        curl_close($ch);
        if (($body === false || $errno || $status >= 400) && self::shouldUseWorkerEgress($url)) {
"""
if old not in t:
    raise SystemExit("proxyFetch curl opts missing")
t = t.replace(old, new, 1)

p.write_text(t)
print("VidmolySources patched OK")
