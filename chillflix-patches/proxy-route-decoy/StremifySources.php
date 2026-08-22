<?php
declare(strict_types=1);

/**
 * Experimental Vuflix test provider: Stremify (Hayduk public instance).
 *
 * Flow: TMDB → Stremio stream API → HTTP URLs for the native player.
 * Many streams need a Referer (and some are same-IP locked on the scraper),
 * so we wrap those through /api/player/media-proxy when headers are required.
 *
 * Disabled by default in Admin → Sources. Not intended as a primary public source.
 */
final class StremifySources
{
    public const PROVIDER_ID = 'stremify';
    public const PROVIDER_NAME = 'Stremify';

    private const BASE = 'https://stremify.hayd.uk';
    private const MAX_STREAMS = 10;

    public static function hostAllowed(): bool
    {
        $host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
        $host = preg_replace('/:\d+$/', '', $host) ?: $host;
        $host = preg_replace('/^www\./', '', $host) ?: $host;
        return $host === 'vuflix.co' || $host === 'localhost' || $host === '127.0.0.1';
    }

    /**
     * @return array{ok:bool,sources:list<array<string,mixed>>,error?:string,diagnostics?:list<array<string,mixed>>,meta?:array<string,mixed>}
     */
    public static function fetch(string $type, int $tmdbId, int $season = 1, int $episode = 1): array
    {
        if (!self::hostAllowed()) {
            return [
                'ok' => false,
                'sources' => [],
                'error' => 'Stremify test provider is Vuflix-only',
                'diagnostics' => [[
                    'code' => 'STREMIFY_HOST_BLOCKED',
                    'message' => 'Stremify is only enabled for testing on vuflix.co',
                    'severity' => 'info',
                    'provider' => self::PROVIDER_ID,
                ]],
            ];
        }

        $type = $type === 'tv' ? 'tv' : 'movie';
        if ($tmdbId < 1) {
            return ['ok' => false, 'sources' => [], 'error' => 'Invalid tmdbId'];
        }

        $kind = $type === 'tv' ? 'series' : 'movie';
        $ids = self::streamIds($type, $tmdbId, $season, $episode);
        $diagnostics = [];
        $streams = [];
        $lastError = '';

        foreach ($ids as $streamId) {
            $url = self::BASE . '/stream/' . $kind . '/' . rawurlencode($streamId) . '.json';
            $payload = self::httpGetJson($url);
            if (!is_array($payload)) {
                $lastError = 'Stremify request failed for ' . $streamId;
                $diagnostics[] = [
                    'code' => 'STREMIFY_FETCH_FAILED',
                    'message' => $lastError,
                    'severity' => 'warning',
                    'provider' => self::PROVIDER_ID,
                ];
                continue;
            }
            if (isset($payload['statusCode']) && (int) $payload['statusCode'] >= 400) {
                $lastError = (string) ($payload['message'] ?? ('Stremify HTTP ' . $payload['statusCode']));
                $diagnostics[] = [
                    'code' => 'STREMIFY_UPSTREAM_ERROR',
                    'message' => $lastError . ' (' . $streamId . ')',
                    'severity' => 'warning',
                    'provider' => self::PROVIDER_ID,
                ];
                continue;
            }
            $batch = is_array($payload['streams'] ?? null) ? $payload['streams'] : [];
            if (!$batch) {
                $lastError = 'No streams for ' . $streamId;
                continue;
            }
            foreach ($batch as $row) {
                if (is_array($row)) {
                    $streams[] = $row;
                }
            }
            if ($streams) {
                break;
            }
        }

        if (!$streams) {
            return [
                'ok' => false,
                'sources' => [],
                'error' => $lastError !== '' ? $lastError : 'No Stremify streams',
                'diagnostics' => $diagnostics,
            ];
        }

        $sources = [];
        foreach ($streams as $row) {
            if (count($sources) >= self::MAX_STREAMS) {
                break;
            }
            $streamUrl = trim((string) ($row['url'] ?? ''));
            if ($streamUrl === '' || !preg_match('#^https?://#i', $streamUrl)) {
                continue;
            }

            $title = trim((string) ($row['title'] ?? $row['name'] ?? 'Stremify'));
            $quality = self::guessQuality($title);
            $headers = [];
            $hints = is_array($row['behaviorHints'] ?? null) ? $row['behaviorHints'] : [];
            $proxyHeaders = is_array($hints['proxyHeaders']['request'] ?? null)
                ? $hints['proxyHeaders']['request']
                : [];
            foreach ($proxyHeaders as $hk => $hv) {
                $hk = trim((string) $hk);
                $hv = trim((string) $hv);
                if ($hk !== '' && $hv !== '') {
                    $headers[$hk] = $hv;
                }
            }

            $playUrl = $streamUrl;
            if ($headers) {
                $playUrl = self::signedProxyUrl($streamUrl, $headers);
            }

            $sources[] = [
                'url' => $playUrl,
                'type' => self::guessType($streamUrl),
                'quality' => $quality,
                'provider' => self::PROVIDER_ID,
                'providerName' => self::PROVIDER_NAME,
                'label' => 'Stremify · ' . ($quality !== '' ? $quality . ' · ' : '') . self::shortTitle($title),
                'language' => '',
                'audioTracks' => [],
                'meta' => [
                    'via' => 'stremify-hayduk',
                    'title' => $title,
                    'notWebReady' => !empty($hints['notWebReady']),
                    'proxied' => $headers !== [],
                ],
            ];
        }

        if (!$sources) {
            return [
                'ok' => false,
                'sources' => [],
                'error' => 'Stremify returned streams but none were playable URLs',
                'diagnostics' => $diagnostics,
            ];
        }

        return [
            'ok' => true,
            'sources' => $sources,
            'diagnostics' => $diagnostics,
            'meta' => [
                'addon' => self::BASE,
                'count' => count($sources),
            ],
        ];
    }

    /** @return list<string> */
    private static function streamIds(string $type, int $tmdbId, int $season, int $episode): array
    {
        $ids = [];
        if ($type === 'tv') {
            $imdb = self::imdbIdFor($type, $tmdbId);
            if ($imdb) {
                $ids[] = $imdb . ':' . max(1, $season) . ':' . max(1, $episode);
            }
            $ids[] = 'tmdb:' . $tmdbId . ':' . max(1, $season) . ':' . max(1, $episode);
        } else {
            $ids[] = 'tmdb:' . $tmdbId;
            $imdb = self::imdbIdFor($type, $tmdbId);
            if ($imdb) {
                $ids[] = $imdb;
            }
        }
        return array_values(array_unique($ids));
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

    /** @param array<string,string> $headers */
    public static function signedProxyUrl(string $url, array $headers): string
    {
        $exp = time() + 3600;
        $payload = [
            'u' => $url,
            'h' => $headers,
            'e' => $exp,
        ];
        $body = rtrim(strtr(base64_encode(json_encode($payload, JSON_UNESCAPED_SLASHES) ?: '{}'), '+/', '-_'), '=');
        $sig = hash_hmac('sha256', $body, self::proxySecret());
        $qs = http_build_query(['t' => $body, 's' => $sig]);
        return url('/api/player/v-relay?' . $qs);
    }

    /**
     * Stream a remote media URL with optional request headers (Referer, etc).
     */
    public static function proxyMedia(string $token, string $sig): void
    {
        if ($token === '' || $sig === '' || !hash_equals(hash_hmac('sha256', $token, self::proxySecret()), $sig)) {
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
        $headers = ['User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36'];
        foreach (($data['h'] ?? []) as $k => $v) {
            $k = trim((string) $k);
            $v = trim((string) $v);
            if ($k === '' || $v === '' || preg_match('/[\r\n]/', $k . $v)) {
                continue;
            }
            $headers[] = $k . ': ' . $v;
        }

        $range = (string) ($_SERVER['HTTP_RANGE'] ?? '');
        if ($range !== '' && !preg_match('/[\r\n]/', $range)) {
            $headers[] = 'Range: ' . $range;
        }

        if (!function_exists('curl_init')) {
            http_response_code(500);
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'error' => 'curl missing']);
            exit;
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_CONNECTTIMEOUT => 12,
            CURLOPT_TIMEOUT => 0,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_HEADER => false,
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

    private static function proxySecret(): string
    {
        $secret = (string) config('auth_secret', '');
        return $secret !== '' ? $secret : 'vuflix-stremify-proxy';
    }

    private static function guessQuality(string $text): string
    {
        if (preg_match('/\b(2160p|4k|uhd)\b/i', $text)) {
            return '4K';
        }
        if (preg_match('/\b1080p\b/i', $text)) {
            return '1080p';
        }
        if (preg_match('/\b720p\b/i', $text)) {
            return '720p';
        }
        if (preg_match('/\b480p\b/i', $text)) {
            return '480p';
        }
        if (preg_match('/\b360p?\b/i', $text)) {
            return '360p';
        }
        return 'Auto';
    }

    private static function guessType(string $url): string
    {
        $path = strtolower(parse_url($url, PHP_URL_PATH) ?: '');
        if (str_contains($path, '.m3u8') || str_contains($url, 'm3u8')) {
            return 'hls';
        }
        return 'file';
    }

    private static function shortTitle(string $title): string
    {
        $title = preg_replace('/\s+/', ' ', trim($title)) ?? $title;
        if (strlen($title) > 48) {
            return substr($title, 0, 45) . '…';
        }
        return $title;
    }

    private static function httpGetJson(string $url): ?array
    {
        $raw = self::httpGetRaw($url);
        if ($raw === null || $raw === '') {
            return null;
        }
        $data = json_decode($raw, true);
        return is_array($data) ? $data : null;
    }

    private static function httpGetRaw(string $url): ?string
    {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_CONNECTTIMEOUT => 12,
                CURLOPT_TIMEOUT => 45,
                CURLOPT_HTTPHEADER => [
                    'Accept: application/json',
                    'User-Agent: VuflixStremify/1.0',
                ],
            ]);
            $body = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if (!is_string($body) || $body === '') {
                return null;
            }
            // Hayduk sometimes returns JSON error bodies with HTTP 500
            if ($code >= 200 && $code < 600) {
                return $body;
            }
            return null;
        }

        $ctx = stream_context_create([
            'http' => [
                'timeout' => 45,
                'header' => "Accept: application/json\r\nUser-Agent: VuflixStremify/1.0\r\n",
            ],
        ]);
        $body = @file_get_contents($url, false, $ctx);
        return is_string($body) ? $body : null;
    }
}
