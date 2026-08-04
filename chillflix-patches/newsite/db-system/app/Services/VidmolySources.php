<?php
declare(strict_types=1);

/**
 * HiMovies / 0123movie → Vidmoly HLS resolver (HTTP only, no browser).
 *
 * Movies:  https://0123movie.space/vmf/{imdb}/{tmdb}/  → vidmoly embed → master.m3u8
 * TV:      https://0123movie.space/vms/{tmdb}/{s}/{e}/  (only if it still lands on Vidmoly)
 *
 * Playback goes through /api/player/media-proxy with Referer/Origin so hls.js
 * can fetch CDN playlists. Disabled by default in Admin → Sources.
 */
final class VidmolySources
{
    public const PROVIDER_ID = 'vidmoly';
    public const PROVIDER_NAME = 'Vidmoly';

    private const WRAPPER_HOST = 'https://0123movie.space';
    private const SITE_REFERER = 'https://himovies.watch/';
    private const UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36';

    /**
     * @return array{ok:bool,sources:list<array<string,mixed>>,error?:string,diagnostics?:list<array<string,mixed>>,meta?:array<string,mixed>}
     */
    public static function fetch(string $type, int $tmdbId, int $season = 1, int $episode = 1): array
    {
        $type = $type === 'tv' ? 'tv' : 'movie';
        if ($tmdbId < 1) {
            return ['ok' => false, 'sources' => [], 'error' => 'Invalid tmdbId'];
        }

        $diagnostics = [];
        $wrapper = self::wrapperUrl($type, $tmdbId, $season, $episode, $diagnostics);
        if ($wrapper === null) {
            return [
                'ok' => false,
                'sources' => [],
                'error' => (string) ($diagnostics[0]['message'] ?? 'Could not build Vidmoly wrapper URL'),
                'diagnostics' => $diagnostics,
            ];
        }

        $resolved = self::resolveWrapper($wrapper);
        if (empty($resolved['ok'])) {
            $diagnostics[] = [
                'code' => 'VIDMOLY_RESOLVE_FAILED',
                'message' => (string) ($resolved['error'] ?? 'Vidmoly resolve failed'),
                'severity' => 'warning',
                'provider' => self::PROVIDER_ID,
            ];
            return [
                'ok' => false,
                'sources' => [],
                'error' => (string) ($resolved['error'] ?? 'Vidmoly resolve failed'),
                'diagnostics' => $diagnostics,
                'meta' => ['wrapper' => $wrapper],
            ];
        }

        $master = (string) $resolved['m3u8'];
        $embed = (string) ($resolved['embed'] ?? $wrapper);
        $origin = self::originOf($embed) ?: 'https://vidmoly.biz';
        $headers = [
            'Referer' => $embed,
            'Origin' => $origin,
            'Accept' => '*/*',
            'Sec-Fetch-Dest' => 'empty',
            'Sec-Fetch-Mode' => 'cors',
            'Sec-Fetch-Site' => 'cross-site',
        ];

        $playUrl = self::signedProxyUrl($master, $headers, true);
        $quality = (string) ($resolved['quality'] ?? 'Auto');

        return [
            'ok' => true,
            'sources' => [[
                'url' => $playUrl,
                'type' => 'hls',
                'quality' => $quality,
                'provider' => self::PROVIDER_ID,
                'providerName' => self::PROVIDER_NAME,
                'label' => 'Vidmoly' . ($quality !== '' && strcasecmp($quality, 'Auto') !== 0 ? (' · ' . $quality) : ''),
                'language' => '',
                'audioTracks' => [],
                'meta' => [
                    'via' => '0123movie-vidmoly',
                    'wrapper' => $wrapper,
                    'embed' => $embed,
                    'direct' => $master,
                    'proxied' => true,
                ],
            ]],
            'diagnostics' => $diagnostics,
            'meta' => [
                'wrapper' => $wrapper,
                'embed' => $embed,
            ],
        ];
    }

    /**
     * @param list<array<string,mixed>> $diagnostics
     */
    private static function wrapperUrl(string $type, int $tmdbId, int $season, int $episode, array &$diagnostics): ?string
    {
        if ($type === 'tv') {
            $s = max(1, $season);
            $e = max(1, $episode);
            return self::WRAPPER_HOST . '/vms/' . $tmdbId . '/' . $s . '/' . $e . '/';
        }

        $imdb = self::imdbIdFor('movie', $tmdbId);
        if ($imdb === null) {
            $diagnostics[] = [
                'code' => 'VIDMOLY_NO_IMDB',
                'message' => 'IMDb id required for Vidmoly movies',
                'severity' => 'warning',
                'provider' => self::PROVIDER_ID,
            ];
            return null;
        }
        return self::WRAPPER_HOST . '/vmf/' . rawurlencode($imdb) . '/' . $tmdbId . '/';
    }

    /**
     * @return array{ok:bool,m3u8?:string,embed?:string,quality?:string,error?:string}
     */
    private static function resolveWrapper(string $wrapper): array
    {
        $resp = self::httpGet($wrapper, [
            'Accept: text/html,application/xhtml+xml,*/*;q=0.8',
            'Referer: ' . self::SITE_REFERER,
            'Sec-Fetch-Dest: iframe',
            'Sec-Fetch-Mode: navigate',
            'Sec-Fetch-Site: cross-site',
        ], true);
        if ($resp === null) {
            return ['ok' => false, 'error' => 'Wrapper request failed'];
        }

        $final = (string) ($resp['url'] ?? $wrapper);
        $html = (string) ($resp['body'] ?? '');
        $host = strtolower((string) (parse_url($final, PHP_URL_HOST) ?: ''));

        // TV wrappers currently often bounce to Byse/UpCloud instead of Vidmoly.
        if ($host !== '' && !str_contains($host, 'vidmoly')) {
            return [
                'ok' => false,
                'error' => 'Wrapper did not land on Vidmoly (got ' . $host . ')',
            ];
        }

        $m3u8 = self::extractM3u8($html);
        if ($m3u8 === null) {
            return ['ok' => false, 'error' => 'No m3u8 in Vidmoly embed HTML'];
        }

        return [
            'ok' => true,
            'm3u8' => $m3u8,
            'embed' => $final,
            'quality' => 'Auto',
        ];
    }

    private static function extractM3u8(string $html): ?string
    {
        if (preg_match('/sources\s*:\s*\[\s*\{\s*file\s*:\s*[\'"]([^\'"]+\.m3u8[^\'"]*)[\'"]/i', $html, $m)) {
            return html_entity_decode($m[1], ENT_QUOTES);
        }
        if (preg_match('#https?://[^\s\'"]+\.m3u8[^\s\'"]*#i', $html, $m)) {
            return html_entity_decode($m[0], ENT_QUOTES);
        }
        return null;
    }

    private static function imdbIdFor(string $type, int $tmdbId): ?string
    {
        try {
            if (!class_exists('Tmdb')) {
                return null;
            }
            $tmdb = $GLOBALS['tmdb'] ?? null;
            if (!$tmdb instanceof Tmdb) {
                $tmdb = new Tmdb();
            }
            $details = $tmdb->details($type, $tmdbId);
            $imdb = trim((string) ($details['external_ids']['imdb_id'] ?? ''));
            if ($imdb !== '' && preg_match('/^tt\d+$/', $imdb)) {
                return $imdb;
            }
        } catch (Throwable $e) {
            // ignore
        }
        return null;
    }

    private static function originOf(string $url): string
    {
        $p = parse_url($url);
        if (!is_array($p) || empty($p['scheme']) || empty($p['host'])) {
            return '';
        }
        return $p['scheme'] . '://' . $p['host'];
    }

    /**
     * @param array<string,string> $headers
     */
    public static function signedProxyUrl(string $url, array $headers, bool $rewritePlaylist = false): string
    {
        $exp = time() + 3600;
        $payload = [
            'u' => $url,
            'h' => $headers,
            'e' => $exp,
            'r' => $rewritePlaylist ? 1 : 0,
        ];
        $body = rtrim(strtr(base64_encode(json_encode($payload, JSON_UNESCAPED_SLASHES) ?: '{}'), '+/', '-_'), '=');
        $sig = hash_hmac('sha256', $body, self::proxySecret());
        $qs = http_build_query(['t' => $body, 's' => $sig]);
        return url('/api/player/media-proxy?' . $qs);
    }

    public static function proxyMedia(string $token, string $sig): void
    {
        if ($token === '' || $sig === '' || !hash_equals(hash_hmac('sha256', $token, self::proxySecret()), $sig)) {
            // Fall back to Stremify token format / secret if that provider is present.
            if (class_exists('StremifySources')) {
                StremifySources::proxyMedia($token, $sig);
                return;
            }
            http_response_code(403);
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'error' => 'Invalid proxy token']);
            exit;
        }

        $pad = strlen($token) % 4;
        $json = base64_decode(strtr($token, '-_', '+/') . ($pad ? str_repeat('=', 4 - $pad) : ''), true);
        $data = is_string($json) ? json_decode($json, true) : null;
        if (!is_array($data)) {
            http_response_code(400);
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'error' => 'Bad token']);
            exit;
        }
        if ((int) ($data['e'] ?? 0) < time()) {
            http_response_code(403);
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'error' => 'Proxy token expired']);
            exit;
        }
        $url = trim((string) ($data['u'] ?? ''));
        if ($url === '' || !preg_match('#^https?://#i', $url)) {
            http_response_code(400);
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'error' => 'Invalid media url']);
            exit;
        }

        /** @var array<string,string> $reqHeaders */
        $reqHeaders = [];
        foreach (($data['h'] ?? []) as $k => $v) {
            $k = trim((string) $k);
            $v = trim((string) $v);
            if ($k !== '' && $v !== '' && !preg_match('/[\r\n]/', $k . $v)) {
                $reqHeaders[$k] = $v;
            }
        }
        $rewrite = !empty($data['r']);

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
     * @param array<string,string> $headers
     */
    private static function rewritePlaylist(string $body, string $playlistUrl, array $headers): string
    {
        $lines = preg_split("/\r\n|\n|\r/", $body) ?: [];
        $out = [];
        foreach ($lines as $line) {
            $trim = trim($line);
            if ($trim === '' || str_starts_with($trim, '#')) {
                // Rewrite URI="..." attributes inside tags when present.
                if (preg_match('/URI="([^"]+)"/i', $line, $m)) {
                    $abs = self::absolutize($m[1], $playlistUrl);
                    $proxied = self::signedProxyUrl($abs, $headers, true);
                    $line = str_replace($m[0], 'URI="' . $proxied . '"', $line);
                }
                $out[] = $line;
                continue;
            }
            $abs = self::absolutize($trim, $playlistUrl);
            $out[] = self::signedProxyUrl($abs, $headers, true);
        }
        return implode("\n", $out) . "\n";
    }

    private static function absolutize(string $maybeRelative, string $base): string
    {
        if (preg_match('#^https?://#i', $maybeRelative)) {
            return $maybeRelative;
        }
        $bp = parse_url($base);
        if (!is_array($bp) || empty($bp['scheme']) || empty($bp['host'])) {
            return $maybeRelative;
        }
        $origin = $bp['scheme'] . '://' . $bp['host'] . (isset($bp['port']) ? (':' . $bp['port']) : '');
        if (str_starts_with($maybeRelative, '/')) {
            return $origin . $maybeRelative;
        }
        $path = (string) ($bp['path'] ?? '/');
        $dir = preg_replace('#/[^/]*$#', '/', $path) ?: '/';
        return $origin . $dir . $maybeRelative;
    }

    /**
     * @param list<string> $headers
     * @return array{url:string,body:string}|null
     */
    private static function httpGet(string $url, array $headers = [], bool $follow = true): ?array
    {
        if (!function_exists('curl_init')) {
            return null;
        }
        $ch = curl_init($url);
        $hdrs = array_merge([
            'User-Agent: ' . self::UA,
            'Accept-Language: en-US,en;q=0.9',
        ], $headers);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => $follow,
            CURLOPT_MAXREDIRS => 8,
            CURLOPT_CONNECTTIMEOUT => 12,
            CURLOPT_TIMEOUT => 25,
            CURLOPT_HTTPHEADER => $hdrs,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_ENCODING => '',
        ]);
        $body = curl_exec($ch);
        $errno = curl_errno($ch);
        $final = (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($body === false || $errno || $status < 200 || $status >= 400) {
            return null;
        }
        return ['url' => $final !== '' ? $final : $url, 'body' => (string) $body];
    }

    /**
     * @param array<string,string> $headers
     * @return array{status:int,body:string,contentType?:string,contentLength?:string}|null
     */
    private static function proxyFetch(string $url, array $headers): ?array
    {
        if (!function_exists('curl_init')) {
            return null;
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
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_CONNECTTIMEOUT => 12,
            CURLOPT_TIMEOUT => 45,
            CURLOPT_HTTPHEADER => $hdrLines,
            CURLOPT_ENCODING => '',
        ]);
        $body = curl_exec($ch);
        $errno = curl_errno($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $ctype = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        curl_close($ch);
        if ($body === false || $errno) {
            return null;
        }
        return [
            'status' => $status,
            'body' => (string) $body,
            'contentType' => $ctype,
            'contentLength' => (string) strlen((string) $body),
        ];
    }

    private static function proxySecret(): string
    {
        $secret = (string) config('auth_secret', '');
        return $secret !== '' ? $secret : 'vuflix-vidmoly-proxy';
    }
}
