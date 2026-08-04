<?php
declare(strict_types=1);

/**
 * Cineplay / Vidking → Yoru (4K) scraper for the native Vuflix player.
 *
 * Scrapes api.speedracelight.com `/cdn/sources-with-title` (Yoru) with enc=2
 * + seed decrypt, then wraps HLS through /api/player/media-proxy for hls.js.
 *
 * Datacenter VPS IPs are often CF-banned (1015/429). Prefer:
 *   YORU_RELAY_URL + YORU_RELAY_SECRET  (Cloudflare Worker / relay)
 * or PROVIDER_FETCH_PROXY (residential).
 *
 * No third-party embed — native player only.
 */
final class CineplaySources
{
    public const API_ORIGIN = 'https://api.speedracelight.com';

    /** Cache successful raw Yoru payloads so many viewers share one upstream scrape. */
    private const RAW_CACHE_TTL_SEC = 300;

    /**
     * @return array{ok:bool,sources?:list<array<string,mixed>>,error?:string,diagnostics?:list<array<string,mixed>>}
     */
    public static function fetch(string $type, int $tmdbId, int $season = 1, int $episode = 1): array
    {
        $type = $type === 'tv' ? 'tv' : 'movie';
        if ($tmdbId < 1) {
            return ['ok' => false, 'error' => 'Invalid tmdbId', 'sources' => [], 'diagnostics' => []];
        }

        $diagnostics = [];
        $cacheKey = $type . ':' . $tmdbId . ':' . max(1, $season) . ':' . max(1, $episode);

        // Serve from short cache (re-mint media-proxy URLs so signatures stay fresh).
        $cachedRaw = self::readRawCache($cacheKey);
        if (is_array($cachedRaw) && !empty($cachedRaw['sources'])) {
            $mapped = self::mapSources($cachedRaw);
            if (!empty($mapped['sources'])) {
                $diagnostics[] = [
                    'code' => 'CINEPLAY_CACHE_HIT',
                    'message' => 'Yoru cache hit (' . count($mapped['sources'][0]['qualities'] ?? []) . ' qualities)',
                    'severity' => 'info',
                    'provider' => 'cineplay',
                ];
                return [
                    'ok' => true,
                    'sources' => $mapped['sources'],
                    'diagnostics' => $diagnostics,
                ];
            }
        }

        $scraped = self::scrapeYoru($type, $tmdbId, $season, $episode, $diagnostics);
        if (!empty($scraped['sources'])) {
            return [
                'ok' => true,
                'sources' => $scraped['sources'],
                'diagnostics' => $diagnostics,
            ];
        }

        $diagnostics[] = [
            'code' => 'CINEPLAY_NO_STREAMS',
            'message' => (string) ($scraped['error'] ?? 'Yoru scrape failed — set YORU_RELAY_URL'),
            'severity' => 'error',
            'provider' => 'cineplay',
        ];

        return [
            'ok' => false,
            'error' => (string) ($scraped['error'] ?? 'Yoru scrape failed'),
            'sources' => [],
            'diagnostics' => $diagnostics,
        ];
    }

    /**
     * Mint a signed media-proxy URL for a scraped stream.
     *
     * @param array<string,string> $headers
     */
    public static function mintProxy(string $streamUrl, array $headers = [], bool $rewritePlaylist = true): string
    {
        if (!class_exists('VidmolySources')) {
            throw new RuntimeException('VidmolySources proxy helper missing');
        }
        if ($headers === []) {
            $headers = [
                'Referer' => 'https://www.vidking.net/',
                'Origin' => 'https://www.vidking.net',
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/137.0.0.0 Safari/537.36',
            ];
        }
        return VidmolySources::signedProxyUrl($streamUrl, $headers, $rewritePlaylist);
    }

    /**
     * @param list<array<string,mixed>> $diagnostics
     * @return array{sources?:list<array<string,mixed>>,error?:string,title?:string,year?:string,imdbId?:string}
     */
    private static function scrapeYoru(
        string $type,
        int $tmdbId,
        int $season,
        int $episode,
        array &$diagnostics
    ): array {
        $viaRelay = self::scrapeViaRelay($type, $tmdbId, $season, $episode, $diagnostics);
        if (!empty($viaRelay['sources']) || (isset($viaRelay['error']) && str_starts_with((string) $viaRelay['error'], 'relay '))) {
            if (!empty($viaRelay['sources'])) {
                return $viaRelay;
            }
            // Relay configured but failed — still try local node (may work with proxy).
        }

        return self::scrapeViaNode($type, $tmdbId, $season, $episode, $diagnostics);
    }

    /**
     * @param list<array<string,mixed>> $diagnostics
     * @return array{sources?:list<array<string,mixed>>,error?:string,title?:string,year?:string,imdbId?:string}
     */
    private static function scrapeViaRelay(
        string $type,
        int $tmdbId,
        int $season,
        int $episode,
        array &$diagnostics
    ): array {
        $relay = self::envValue('YORU_RELAY_URL');
        $secret = self::envValue('YORU_RELAY_SECRET');
        if ($relay === '') {
            return [];
        }

        $qs = http_build_query([
            'type' => $type,
            'tmdb' => $tmdbId,
            'season' => max(1, $season),
            'episode' => max(1, $episode),
        ]);
        $url = rtrim($relay, '/') . '/resolve?' . $qs;
        $headers = [
            'Accept: application/json',
            'User-Agent: VuflixYoruRelay/1.0',
        ];
        if ($secret !== '') {
            $headers[] = 'X-Yoru-Key: ' . $secret;
        }

        $ch = curl_init($url);
        if ($ch === false) {
            return ['error' => 'relay curl init failed'];
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT => 45,
            CURLOPT_HTTPHEADER => $headers,
        ]);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $cerr = curl_error($ch);
        curl_close($ch);

        if ($body === false || $body === '') {
            $diagnostics[] = [
                'code' => 'CINEPLAY_RELAY_FAIL',
                'message' => 'relay request failed: ' . ($cerr !== '' ? $cerr : 'empty body'),
                'severity' => 'warning',
                'provider' => 'cineplay',
            ];
            return ['error' => 'relay request failed'];
        }

        $json = json_decode($body, true);
        if (!is_array($json)) {
            return ['error' => 'relay bad JSON'];
        }
        if (empty($json['ok']) || empty($json['sources']) || !is_array($json['sources'])) {
            $diagnostics[] = [
                'code' => 'CINEPLAY_RELAY_EMPTY',
                'message' => (string) ($json['error'] ?? ('relay HTTP ' . $status)),
                'severity' => 'warning',
                'provider' => 'cineplay',
            ];
            return [
                'error' => 'relay ' . (string) ($json['error'] ?? ('HTTP ' . $status)),
                'title' => (string) ($json['title'] ?? ''),
                'year' => (string) ($json['year'] ?? ''),
                'imdbId' => (string) ($json['imdbId'] ?? ''),
            ];
        }

        $diagnostics[] = [
            'code' => 'CINEPLAY_RELAY_OK',
            'message' => 'Yoru via relay (' . count($json['sources']) . ' streams)',
            'severity' => 'info',
            'provider' => 'cineplay',
        ];

        self::writeRawCache(
            $type . ':' . $tmdbId . ':' . max(1, $season) . ':' . max(1, $episode),
            $json
        );

        return self::mapSources($json);
    }

    /**
     * @param list<array<string,mixed>> $diagnostics
     * @return array{sources?:list<array<string,mixed>>,error?:string,title?:string,year?:string,imdbId?:string}
     */
    private static function scrapeViaNode(
        string $type,
        int $tmdbId,
        int $season,
        int $episode,
        array &$diagnostics
    ): array {
        $script = self::resolveScriptPath();
        if ($script === null) {
            $diagnostics[] = [
                'code' => 'CINEPLAY_SCRIPT_MISSING',
                'message' => 'cineplay-yoru-resolve.mjs not found',
                'severity' => 'warning',
                'provider' => 'cineplay',
            ];
            return ['error' => 'resolver script missing'];
        }

        $node = self::nodeBinary();
        $cmd = [
            $node,
            $script,
            '--type', $type,
            '--tmdb', (string) $tmdbId,
            '--season', (string) max(1, $season),
            '--episode', (string) max(1, $episode),
        ];

        $env = self::resolverEnv();
        $desc = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $proc = @proc_open($cmd, $desc, $pipes, dirname($script), $env);
        if (!is_resource($proc)) {
            return ['error' => 'failed to start resolver'];
        }
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]) ?: '';
        $stderr = stream_get_contents($pipes[2]) ?: '';
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($proc);

        $json = json_decode(trim($stdout), true);
        if (!is_array($json)) {
            $diagnostics[] = [
                'code' => 'CINEPLAY_RESOLVE_BAD_JSON',
                'message' => trim($stderr) !== '' ? trim($stderr) : 'empty resolver output',
                'severity' => 'warning',
                'provider' => 'cineplay',
            ];
            return ['error' => 'resolver returned invalid JSON', 'exit' => $code];
        }
        if (empty($json['ok']) || empty($json['sources']) || !is_array($json['sources'])) {
            $diagnostics[] = [
                'code' => !empty($json['cfBlocked']) ? 'CINEPLAY_CF_BLOCKED' : 'CINEPLAY_EMPTY',
                'message' => (string) ($json['error'] ?? 'No Yoru sources'),
                'severity' => 'warning',
                'provider' => 'cineplay',
            ];
            return [
                'error' => (string) ($json['error'] ?? 'No Yoru sources'),
                'title' => (string) ($json['title'] ?? ''),
                'year' => (string) ($json['year'] ?? ''),
                'imdbId' => (string) ($json['imdbId'] ?? ''),
            ];
        }

        $diagnostics[] = [
            'code' => 'CINEPLAY_YORU_OK',
            'message' => 'Scraped ' . count($json['sources']) . ' Yoru stream(s) via node',
            'severity' => 'info',
            'provider' => 'cineplay',
        ];

        self::writeRawCache(
            $type . ':' . $tmdbId . ':' . max(1, $season) . ':' . max(1, $episode),
            $json
        );

        return self::mapSources($json);
    }

    /** @return array<string,mixed>|null */
    private static function readRawCache(string $key): ?array
    {
        $path = self::rawCachePath($key);
        if ($path === null || !is_file($path)) {
            return null;
        }
        $raw = @file_get_contents($path);
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        $wrap = json_decode($raw, true);
        if (!is_array($wrap) || empty($wrap['exp']) || empty($wrap['data']) || !is_array($wrap['data'])) {
            return null;
        }
        if ((int) $wrap['exp'] < time()) {
            @unlink($path);
            return null;
        }
        return $wrap['data'];
    }

    /** @param array<string,mixed> $json */
    private static function writeRawCache(string $key, array $json): void
    {
        $path = self::rawCachePath($key);
        if ($path === null) {
            return;
        }
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $payload = json_encode([
            'exp' => time() + self::RAW_CACHE_TTL_SEC,
            'data' => $json,
        ], JSON_UNESCAPED_SLASHES);
        if (!is_string($payload)) {
            return;
        }
        @file_put_contents($path, $payload, LOCK_EX);
    }

    private static function rawCachePath(string $key): ?string
    {
        $base = '/var/www/chillflix-newsite/storage/cache/cineplay-yoru';
        $alt = dirname(__DIR__, 2) . '/storage/cache/cineplay-yoru';
        $dir = is_dir(dirname($base)) || @mkdir(dirname($base), 0775, true) ? $base : $alt;
        if ($dir === '') {
            return null;
        }
        $safe = preg_replace('/[^a-zA-Z0-9:_-]+/', '_', $key) ?: 'x';
        return rtrim($dir, '/') . '/' . $safe . '.json';
    }

    /**
     * @param array<string,mixed> $json
     * @return array{sources:list<array<string,mixed>>,title:string,year:string,imdbId:string}
     */
    private static function mapSources(array $json): array
    {
        $qualities = [];
        $headers = [
            'Referer' => 'https://www.vidking.net/',
            'Origin' => 'https://www.vidking.net',
            'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/137.0.0.0 Safari/537.36',
        ];
        foreach ($json['sources'] as $src) {
            if (!is_array($src) || empty($src['url'])) {
                continue;
            }
            $rawUrl = (string) $src['url'];
            $quality = trim((string) ($src['quality'] ?? 'Auto'));
            if ($quality === '') {
                $quality = 'Auto';
            }
            $streamType = str_contains(strtolower($rawUrl), '.m3u8') ? 'hls' : (string) ($src['type'] ?? 'hls');
            try {
                $playUrl = self::mintProxy($rawUrl, $headers, true);
            } catch (Throwable $e) {
                $playUrl = $rawUrl;
            }
            $qualities[] = [
                'quality' => $quality,
                'url' => $playUrl,
                'type' => $streamType === 'iframe' ? 'hls' : $streamType,
                'rawHost' => (string) (parse_url($rawUrl, PHP_URL_HOST) ?: ''),
            ];
        }

        if ($qualities === []) {
            return [
                'sources' => [],
                'title' => (string) ($json['title'] ?? ''),
                'year' => (string) ($json['year'] ?? ''),
                'imdbId' => (string) ($json['imdbId'] ?? ''),
            ];
        }

        // Highest first in Quality menu; default play URL is 1080p when available.
        usort($qualities, static function (array $a, array $b): int {
            return self::qualityRank((string) $b['quality']) <=> self::qualityRank((string) $a['quality']);
        });
        $default = $qualities[0];
        foreach ($qualities as $q) {
            if (str_contains(strtolower((string) $q['quality']), '1080')) {
                $default = $q;
                break;
            }
        }

        return [
            'sources' => [[
                'url' => (string) $default['url'],
                'type' => (string) ($default['type'] ?? 'hls'),
                'quality' => (string) $default['quality'],
                'provider' => 'cineplay',
                'providerName' => 'Cineplay',
                'label' => 'Cineplay · Yoru',
                'language' => 'en',
                'qualities' => array_map(static function (array $q): array {
                    return [
                        'quality' => (string) $q['quality'],
                        'url' => (string) $q['url'],
                        'type' => (string) ($q['type'] ?? 'hls'),
                    ];
                }, $qualities),
                'meta' => [
                    'server' => 'Yoru',
                    'via' => 'speedracelight-cdn',
                    'rawHost' => (string) ($default['rawHost'] ?? ''),
                    'defaultQuality' => '1080p',
                ],
            ]],
            'title' => (string) ($json['title'] ?? ''),
            'year' => (string) ($json['year'] ?? ''),
            'imdbId' => (string) ($json['imdbId'] ?? ''),
        ];
    }

    private static function qualityRank(string $q): int
    {
        $q = strtolower(trim($q));
        if (str_contains($q, '2160') || $q === '4k') {
            return 50;
        }
        if (str_contains($q, '1080')) {
            return 40;
        }
        if (str_contains($q, '720')) {
            return 30;
        }
        if (str_contains($q, '480')) {
            return 20;
        }
        if (str_contains($q, '360')) {
            return 10;
        }
        return 5;
    }

    private static function resolveScriptPath(): ?string
    {
        $candidates = [
            dirname(__DIR__, 2) . '/bin/cineplay-yoru-resolve.mjs',
            '/var/www/chillflix-newsite/bin/cineplay-yoru-resolve.mjs',
            '/var/www/chillflix-newsite/db-system/bin/cineplay-yoru-resolve.mjs',
        ];
        foreach ($candidates as $p) {
            if (is_file($p)) {
                return $p;
            }
        }
        return null;
    }

    private static function nodeBinary(): string
    {
        foreach (['/usr/bin/node', '/usr/local/bin/node', 'node'] as $bin) {
            if ($bin === 'node' || is_executable($bin)) {
                return $bin;
            }
        }
        return 'node';
    }

    private static function envValue(string $key): string
    {
        $v = getenv($key);
        if (is_string($v) && trim($v) !== '') {
            return trim($v);
        }
        foreach (self::dotenvFiles() as $file) {
            if (!is_readable($file)) {
                continue;
            }
            foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
                $line = trim($line);
                if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                    continue;
                }
                [$k, $val] = explode('=', $line, 2);
                if (trim($k) === $key) {
                    $val = trim($val, " \t\"'");
                    if ($val !== '') {
                        return $val;
                    }
                }
            }
        }
        return '';
    }

    /** @return list<string> */
    private static function dotenvFiles(): array
    {
        return [
            dirname(__DIR__, 2) . '/.env',
            '/var/www/chillflix-newsite/db-system/.env',
            '/var/www/chillflix-newsite/.env',
            '/var/www/cinepro/.env',
        ];
    }

    /** @return array<string,string> */
    private static function resolverEnv(): array
    {
        $env = [];
        foreach ($_ENV as $k => $v) {
            if (is_string($k) && is_string($v)) {
                $env[$k] = $v;
            }
        }
        foreach ($_SERVER as $k => $v) {
            if (is_string($k) && is_string($v) && !isset($env[$k])) {
                $env[$k] = $v;
            }
        }
        foreach (['PROVIDER_FETCH_PROXY', 'OUTBOUND_HTTP_PROXY', 'HTTPS_PROXY', 'HTTP_PROXY', 'VIXSRC_PROXY', 'YORU_RELAY_URL', 'YORU_RELAY_SECRET'] as $k) {
            $v = getenv($k);
            if (is_string($v) && $v !== '') {
                $env[$k] = $v;
            }
        }
        if (empty($env['PROVIDER_FETCH_PROXY']) && empty($env['VIXSRC_PROXY'])) {
            $cineproEnv = '/var/www/cinepro/.env';
            if (is_readable($cineproEnv)) {
                foreach (file($cineproEnv, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
                    $line = trim($line);
                    if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                        continue;
                    }
                    [$k, $v] = explode('=', $line, 2);
                    $k = trim($k);
                    $v = trim($v, " \t\"'");
                    if (in_array($k, ['PROVIDER_FETCH_PROXY', 'VIXSRC_PROXY', 'OUTBOUND_HTTP_PROXY', 'HTTPS_PROXY', 'HTTP_PROXY', 'YORU_RELAY_URL', 'YORU_RELAY_SECRET'], true) && $v !== '') {
                        $env[$k] = $v;
                    }
                }
            }
        }
        // Prefer explicit relay env from our dotenv
        foreach (['YORU_RELAY_URL', 'YORU_RELAY_SECRET'] as $k) {
            $v = self::envValue($k);
            if ($v !== '') {
                $env[$k] = $v;
            }
        }
        $env['PATH'] = $env['PATH'] ?? '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin';
        $env['NODE_PATH'] = '/var/www/cinepro/node_modules' . (isset($env['NODE_PATH']) && $env['NODE_PATH'] !== '' ? (':' . $env['NODE_PATH']) : '');
        return $env;
    }
}
