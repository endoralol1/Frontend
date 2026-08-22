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
    private const EMBED_HOSTS = ['vidmoly.to', 'vidmoly.me', 'vidmoly.biz', 'vidmoly.net'];

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
        $via = (string) ($resolved['via'] ?? '0123movie-vidmoly');
        $origin = self::originOf($embed) ?: (str_contains($via, 'byse') ? 'https://gn1r5n.org' : 'https://vidmoly.biz');
        $headers = [
            'Referer' => str_contains($via, 'byse') ? (rtrim($origin, '/') . '/') : $embed,
            'Origin' => $origin,
            'Accept' => '*/*',
            'Sec-Fetch-Dest' => 'empty',
            'Sec-Fetch-Mode' => 'cors',
            'Sec-Fetch-Site' => 'cross-site',
        ];

        $playUrl = self::signedProxyUrl($master, $headers, true);
        $quality = (string) ($resolved['quality'] ?? 'Auto');
        $labelPrefix = str_contains($via, 'byse') ? 'UpCloud' : 'Vidmoly';

        return [
            'ok' => true,
            'sources' => [[
                'url' => $playUrl,
                'type' => 'hls',
                'quality' => $quality,
                'provider' => self::PROVIDER_ID,
                'providerName' => str_contains($via, 'byse') ? 'UpCloud' : self::PROVIDER_NAME,
                'label' => $labelPrefix . ($quality !== '' && strcasecmp($quality, 'Auto') !== 0 ? (' · ' . $quality) : ''),
                'language' => '',
                'audioTracks' => [],
                'meta' => [
                    'via' => $via,
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
     * @return array{ok:bool,m3u8?:string,embed?:string,quality?:string,via?:string,error?:string}
     */
    private static function resolveWrapper(string $wrapper): array
    {
        // Capture the 0123movie redirect target without requiring the final embed to be 200.
        $hop = self::httpGet($wrapper, [
            'Accept: text/html,application/xhtml+xml,*/*;q=0.8',
            'Referer: ' . self::SITE_REFERER,
            'Sec-Fetch-Dest: iframe',
            'Sec-Fetch-Mode: navigate',
            'Sec-Fetch-Site: cross-site',
        ], false);
        if ($hop === null) {
            return ['ok' => false, 'error' => 'Wrapper request failed'];
        }

        $status = (int) ($hop['status'] ?? 0);
        $location = trim((string) ($hop['redirect'] ?? ''));
        $final = $location !== '' ? $location : (string) ($hop['url'] ?? $wrapper);
        $host = strtolower((string) (parse_url($final, PHP_URL_HOST) ?: ''));

        // Many HiMovies "Vidmoly" buttons redirect to Byse (gn1r5n). Byse PoW is
        // CPU-heavy and caused Cloudflare 502s when run on the VPS — keep it OFF
        // unless VIDMOLY_ALLOW_BYSE_FALLBACK=1 is set explicitly.
        if ($host !== '' && !str_contains($host, 'vidmoly')) {
            $allowByse = filter_var(
                getenv('VIDMOLY_ALLOW_BYSE_FALLBACK') ?: '0',
                FILTER_VALIDATE_BOOLEAN
            );
            if (
                $allowByse
                && class_exists('ByseSources')
                && (str_contains($host, 'gn1r5n') || str_contains($host, 'byse'))
            ) {
                $diag = [];
                $byse = ByseSources::resolveFromEmbed($final, $diag);
                if (!empty($byse['ok']) && !empty($byse['sources'][0]['url'])) {
                    $src0 = $byse['sources'][0];
                    return [
                        'ok' => true,
                        'm3u8' => (string) $src0['url'],
                        'embed' => $final,
                        'quality' => (string) ($src0['quality'] ?? 'Auto'),
                        'via' => 'byse-fallback',
                    ];
                }
            }
            return [
                'ok' => false,
                'error' => 'Wrapper did not land on Vidmoly (got ' . ($host !== '' ? $host : (string) $status) . ')',
            ];
        }

        $embedId = self::embedIdFromUrl($final);
        $lastError = 'No playable Vidmoly embed';

        if ($embedId !== null) {
            foreach (self::EMBED_HOSTS as $embedHost) {
                $embedUrl = 'https://' . $embedHost . '/embed-' . $embedId . '.html';
                $resolved = self::resolveEmbedPage($embedUrl, $embedId);
                if (!empty($resolved['ok'])) {
                    return $resolved;
                }
                if (!empty($resolved['error'])) {
                    $lastError = (string) $resolved['error'];
                }
            }
        } else {
            // Fallback: follow the original redirect target.
            $resolved = self::resolveEmbedPage($final, null);
            if (!empty($resolved['ok'])) {
                return $resolved;
            }
            if (!empty($resolved['error'])) {
                $lastError = (string) $resolved['error'];
            }
        }

        return ['ok' => false, 'error' => $lastError];
    }

    /**
     * @return array{ok:bool,m3u8?:string,embed?:string,quality?:string,via?:string,error?:string}
     */
    private static function resolveEmbedPage(string $embedUrl, ?string $embedId): array
    {
        $headers = [
            'Accept: text/html,application/xhtml+xml,*/*;q=0.8',
            'Referer: https://0123movie.space/',
            'Sec-Fetch-Dest: iframe',
            'Sec-Fetch-Mode: navigate',
            'Sec-Fetch-Site: cross-site',
        ];
        if ($embedId) {
            $headers[] = 'Cookie: cf_turnstile_demo_pass_' . $embedId . '=1';
        }

        $resp = self::httpGet($embedUrl, $headers, true);
        if ($resp === null) {
            return ['ok' => false, 'error' => 'Vidmoly embed request failed'];
        }

        $html = (string) ($resp['body'] ?? '');
        $final = (string) ($resp['url'] ?? $embedUrl);
        $status = (int) ($resp['status'] ?? 0);

        // vidmoly.to JS challenge: follow window.location.replace once.
        if (preg_match('/window\.location\.replace\(\s*[\'"]([^\'"]+)[\'"]\s*\)/i', $html, $m)) {
            $challenge = html_entity_decode($m[1], ENT_QUOTES);
            $challengeResp = self::httpGet($challenge, [
                'Accept: text/html,application/xhtml+xml,*/*;q=0.8',
                'Referer: ' . $embedUrl,
                'Sec-Fetch-Dest: iframe',
                'Sec-Fetch-Mode: navigate',
                'Sec-Fetch-Site: same-origin',
            ], true);
            if ($challengeResp !== null) {
                $challengeHost = strtolower((string) (parse_url((string) ($challengeResp['url'] ?? ''), PHP_URL_HOST) ?: ''));
                // ww*.vidmoly.to homepage means the challenge bounced us (bot / dead embed).
                if ($challengeHost === '' || str_starts_with($challengeHost, 'ww')) {
                    // keep prior html/status
                } else {
                    $html = (string) ($challengeResp['body'] ?? '');
                    $final = (string) ($challengeResp['url'] ?? $final);
                    $status = (int) ($challengeResp['status'] ?? $status);
                }
            }
        }

        $lower = strtolower($html);
        if (
            str_contains($lower, 'video not found')
            || str_contains($lower, 'this video not found')
            || str_contains($lower, 'file was deleted')
        ) {
            return [
                'ok' => false,
                'error' => 'Vidmoly video not found (upstream embed deleted)',
            ];
        }

        $m3u8 = self::extractM3u8($html);
        if ($m3u8 !== null) {
            return [
                'ok' => true,
                'm3u8' => $m3u8,
                'embed' => $final,
                'quality' => 'Auto',
                'via' => '0123movie-vidmoly',
            ];
        }

        if ($status >= 400) {
            return [
                'ok' => false,
                'error' => 'Vidmoly embed unavailable (HTTP ' . $status . ')',
            ];
        }

        return ['ok' => false, 'error' => 'No m3u8 in Vidmoly embed HTML'];
    }

    private static function embedIdFromUrl(string $url): ?string
    {
        if (preg_match('#/embed-([a-zA-Z0-9]+)#', $url, $m)) {
            return $m[1];
        }
        if (preg_match('#/(?:w|v)/([a-zA-Z0-9]+)#', $url, $m)) {
            return $m[1];
        }
        return null;
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
        $bind = class_exists('ProxyGuard') ? ProxyGuard::tokenBindFields() : ['e' => time() + 900];
        $payload = [
            'u' => $url,
            'h' => $headers,
            'e' => (int) ($bind['e'] ?? (time() + 900)),
            'r' => $rewritePlaylist ? 1 : 0,
            'sid' => (string) ($bind['sid'] ?? ''),
            'ip' => (string) ($bind['ip'] ?? ''),
        ];
        $body = rtrim(strtr(base64_encode(json_encode($payload, JSON_UNESCAPED_SLASHES) ?: '{}'), '+/', '-_'), '=');
        $sig = hash_hmac('sha256', $body, self::proxySecret());
        $qs = http_build_query(['t' => $body, 's' => $sig]);
        return url('/api/player/v-relay?' . $qs);
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
        if (class_exists('ProxyGuard')) {
            ProxyGuard::assertTokenBinding($data);
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

        // Sirius/HDGHAR CDN lies with image/jpeg for MPEG-TS (.jpg) segments.
        // nosniff + wrong type leaves HLS.js stuck at 0:00.
        $outType = self::demuxerFriendlyContentType($ctype, $url, $body, (bool) $isPlaylist);
        if ($outType !== '') {
            header('Content-Type: ' . $outType);
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
        return self::sanitizeHlsMaster(implode("\n", $out) . "\n");
    }

    /**
     * CDNs (Sirius/HDGHAR) often label MPEG-TS segments as image/jpeg (.jpg).
     * Force a demuxer-friendly type so HLS.js can play under nosniff.
     */
    private static function demuxerFriendlyContentType(
        string $ctype,
        string $url,
        string $body,
        bool $isPlaylist
    ): string {
        if ($isPlaylist || str_starts_with(ltrim($body), '#EXTM3U')) {
            return 'application/vnd.apple.mpegurl';
        }
        if ($body !== '' && ord($body[0]) === 0x47) {
            return 'video/mp2t';
        }
        if (strlen($body) >= 8) {
            $box = substr($body, 4, 4);
            if (in_array($box, ['ftyp', 'moof', 'mdat', 'styp'], true)) {
                return 'video/mp4';
            }
        }
        $ctype = strtolower(trim($ctype));
        if (
            $ctype === ''
            || str_starts_with($ctype, 'image/')
            || str_contains($ctype, 'octet-stream')
            || str_contains($ctype, 'text/html')
        ) {
            if (preg_match('/\.(jpg|jpeg|png|html|txt|bin)(\?|$)/i', $url)) {
                return 'video/mp2t';
            }
        }
        if ($ctype !== '') {
            return $ctype;
        }
        return 'application/octet-stream';
    }

    /**
     * Drop dangling subtitle group refs and prefer English DEFAULT on multi-audio masters.
     */
    private static function sanitizeHlsMaster(string $body): string
    {
        if (!preg_match('/TYPE=SUBTITLES/i', $body)) {
            $body = preg_replace('/,?\s*SUBTITLES="[^"]*"/i', '', $body) ?? $body;
        }
        if (preg_match('/TYPE=AUDIO/i', $body) && preg_match('/LANGUAGE="English"/i', $body)) {
            $body = preg_replace(
                '/(#EXT-X-MEDIA:TYPE=AUDIO[^\n]*?)DEFAULT=YES/i',
                '$1DEFAULT=NO',
                $body
            ) ?? $body;
            $body = preg_replace(
                '/(#EXT-X-MEDIA:TYPE=AUDIO[^\n]*LANGUAGE="English"[^\n]*?)DEFAULT=NO/i',
                '$1DEFAULT=YES',
                $body,
                1
            ) ?? $body;
        }
        return $body;
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
     * @return array{url:string,body:string,status:int,redirect?:string}|null
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
            // 0123movie/Vidmoly IPv6 often fails from this host.
            CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
            CURLOPT_HEADER => !$follow,
        ]);
        $raw = curl_exec($ch);
        $errno = curl_errno($ch);
        $final = (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($raw === false || $errno) {
            return null;
        }

        $redirect = '';
        $body = (string) $raw;
        if (!$follow) {
            $parts = explode("\r\n\r\n", $body, 2);
            $headerBlob = $parts[0] ?? '';
            $body = $parts[1] ?? '';
            // Handle multiple header blocks (100 Continue etc.)
            if (str_starts_with(strtoupper(ltrim($body)), 'HTTP/')) {
                $parts2 = explode("\r\n\r\n", $body, 2);
                $headerBlob .= "\r\n" . ($parts2[0] ?? '');
                $body = $parts2[1] ?? '';
            }
            if (preg_match('/^Location:\s*(.+)$/im', $headerBlob, $m)) {
                $redirect = trim($m[1]);
            }
            // Wrapper hop success = got a redirect or 2xx/3xx.
            if ($status < 200 || ($status >= 400 && $redirect === '')) {
                return null;
            }
            return [
                'url' => $final !== '' ? $final : $url,
                'body' => $body,
                'status' => $status,
                'redirect' => $redirect,
            ];
        }

        // When following redirects we still return bodies for 404 embed pages so
        // callers can detect "video not found" instead of a generic wrapper miss.
        return [
            'url' => $final !== '' ? $final : $url,
            'body' => $body,
            'status' => $status,
        ];
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
            CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
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
