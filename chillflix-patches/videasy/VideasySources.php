<?php
declare(strict_types=1);

/**
 * Videasy — Cinemove's "VE" source.
 *
 * player.videasy.to scrapes api.speedracelight.com (same stack as Cineplay/Yoru)
 * with enc=2 + seed decrypt. English-first servers we race:
 *   Yoru/cdn, Neon/vsrc, Breach/m4uhd, Cypher/downloader2, Vyse/hdmovie(English)
 *
 * Datacenter VPS IPs are often rate-limited (429/1015). Prefer:
 *   YORU_RELAY_URL + YORU_RELAY_SECRET  (Cloudflare Worker)
 * or PROVIDER_FETCH_PROXY (residential).
 *
 * Streams are minted through media-proxy (same headers as Vidking/Videasy).
 */
final class VideasySources
{
    public const PROVIDER_ID = 'videasy';
    public const PROVIDER_NAME = 'Videasy';
    public const API_ORIGIN = 'https://api.speedracelight.com';

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

        $cachedRaw = self::readRawCache($cacheKey);
        if (is_array($cachedRaw) && !empty($cachedRaw['sources'])) {
            $mapped = self::mapSources($cachedRaw);
            if (!empty($mapped['sources'])) {
                $diagnostics[] = [
                    'code' => 'VIDEASY_CACHE_HIT',
                    'message' => 'Videasy cache hit (' . (string) ($cachedRaw['server'] ?? 'server') . ')',
                    'severity' => 'info',
                    'provider' => self::PROVIDER_ID,
                ];
                return [
                    'ok' => true,
                    'sources' => $mapped['sources'],
                    'diagnostics' => $diagnostics,
                ];
            }
        }

        $scraped = self::scrape($type, $tmdbId, $season, $episode, $diagnostics);
        if (!empty($scraped['sources'])) {
            self::writeRawCache($cacheKey, $scraped);
            $mapped = self::mapSources($scraped);
            return [
                'ok' => !empty($mapped['sources']),
                'sources' => $mapped['sources'] ?? [],
                'diagnostics' => $diagnostics,
                'error' => empty($mapped['sources']) ? 'map failed' : null,
            ];
        }

        // Last resort: reuse Cineplay/Yoru if that class can still resolve.
        if (class_exists('CineplaySources')) {
            $cp = CineplaySources::fetch($type, $tmdbId, $season, $episode);
            foreach ($cp['diagnostics'] ?? [] as $d) {
                if (is_array($d)) {
                    $d['provider'] = self::PROVIDER_ID;
                    $diagnostics[] = $d;
                }
            }
            if (!empty($cp['sources'])) {
                // Re-label as Videasy for the player source list.
                $out = [];
                foreach ($cp['sources'] as $src) {
                    if (!is_array($src)) {
                        continue;
                    }
                    $src['provider'] = self::PROVIDER_ID;
                    $src['providerName'] = self::PROVIDER_NAME;
                    if (!empty($src['label'])) {
                        $src['label'] = preg_replace('/^Cineplay|^4K|^Yoru/i', 'Videasy', (string) $src['label']) ?: $src['label'];
                    }
                    $out[] = $src;
                }
                if ($out !== []) {
                    $diagnostics[] = [
                        'code' => 'VIDEASY_CINEPLAY_FALLBACK',
                        'message' => 'Served via Cineplay/Yoru path',
                        'severity' => 'info',
                        'provider' => self::PROVIDER_ID,
                    ];
                    return ['ok' => true, 'sources' => $out, 'diagnostics' => $diagnostics];
                }
            }
        }

        $diagnostics[] = [
            'code' => 'VIDEASY_NO_STREAMS',
            'message' => (string) ($scraped['error'] ?? 'Videasy scrape failed — set YORU_RELAY_URL or PROVIDER_FETCH_PROXY'),
            'severity' => 'error',
            'provider' => self::PROVIDER_ID,
        ];

        return [
            'ok' => false,
            'error' => (string) ($scraped['error'] ?? 'Videasy scrape failed'),
            'sources' => [],
            'diagnostics' => $diagnostics,
        ];
    }

    /**
     * @param array<string,mixed> $raw
     * @return array{sources:list<array<string,mixed>>}
     */
    private static function mapSources(array $raw): array
    {
        $server = (string) ($raw['server'] ?? 'Videasy');
        $list = [];
        $qualities = [];
        foreach ($raw['sources'] ?? [] as $s) {
            if (!is_array($s) || empty($s['url'])) {
                continue;
            }
            $url = (string) $s['url'];
            $quality = (string) ($s['quality'] ?? 'Auto');
            try {
                $playUrl = self::mintProxy($url);
            } catch (Throwable $e) {
                continue;
            }
            $qualities[] = [
                'label' => $quality !== '' ? $quality : 'Auto',
                'url' => $playUrl,
                'type' => 'hls',
            ];
        }
        if ($qualities === []) {
            return ['sources' => []];
        }

        // Prefer 1080p as default URL when present.
        usort($qualities, static function (array $a, array $b): int {
            $score = static function (string $q): int {
                if (preg_match('/2160|4k/i', $q)) {
                    return 4000;
                }
                if (preg_match('/1080/', $q)) {
                    return 1080;
                }
                if (preg_match('/720/', $q)) {
                    return 720;
                }
                if (preg_match('/480/', $q)) {
                    return 480;
                }
                return 0;
            };
            return $score((string) $b['label']) <=> $score((string) $a['label']);
        });
        $best = $qualities[0];
        // Put 1080 near front for Quality tab default if present.
        foreach ($qualities as $q) {
            if (preg_match('/1080/', (string) $q['label'])) {
                $best = $q;
                break;
            }
        }

        $subs = [];
        foreach ($raw['subtitles'] ?? [] as $sub) {
            if (!is_array($sub) || empty($sub['url'])) {
                continue;
            }
            $subs[] = [
                'lang' => (string) ($sub['lang'] ?? $sub['language'] ?? 'en'),
                'language' => (string) ($sub['language'] ?? $sub['lang'] ?? 'en'),
                'url' => (string) $sub['url'],
            ];
        }

        $list[] = [
            'url' => (string) $best['url'],
            'type' => 'hls',
            'quality' => (string) $best['label'],
            'qualities' => $qualities,
            'provider' => self::PROVIDER_ID,
            'providerName' => self::PROVIDER_NAME,
            'label' => self::PROVIDER_NAME . ' · ' . $server . ' · ' . $best['label'],
            'language' => 'en',
            'hasEnglish' => true,
            'server' => $server,
            'subtitles' => $subs,
            'meta' => [
                'title' => (string) ($raw['title'] ?? ''),
                'year' => (string) ($raw['year'] ?? ''),
                'imdbId' => (string) ($raw['imdbId'] ?? ''),
                'via' => 'videasy-' . strtolower($server),
            ],
        ];

        return ['sources' => $list];
    }

    public static function mintProxy(string $streamUrl, array $headers = [], bool $rewritePlaylist = true): string
    {
        if (class_exists('CineplaySources') && method_exists('CineplaySources', 'mintProxy')) {
            return CineplaySources::mintProxy($streamUrl, $headers, $rewritePlaylist);
        }
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
     * @return array{sources?:list<array<string,mixed>>,error?:string,server?:string,title?:string,year?:string,imdbId?:string,subtitles?:list<array<string,mixed>>}
     */
    private static function scrape(
        string $type,
        int $tmdbId,
        int $season,
        int $episode,
        array &$diagnostics
    ): array {
        $viaRelay = self::scrapeViaRelay($type, $tmdbId, $season, $episode, $diagnostics);
        if (!empty($viaRelay['sources'])) {
            return $viaRelay;
        }

        return self::scrapeViaNode($type, $tmdbId, $season, $episode, $diagnostics);
    }

    /**
     * @param list<array<string,mixed>> $diagnostics
     * @return array{sources?:list<array<string,mixed>>,error?:string,server?:string}
     */
    private static function scrapeViaRelay(
        string $type,
        int $tmdbId,
        int $season,
        int $episode,
        array &$diagnostics
    ): array {
        $relay = '';
        $secret = '';
        $cacheTtl = 7200;
        if (class_exists('WorkerRelayService')) {
            $picked = WorkerRelayService::pickWorker();
            if (is_array($picked)) {
                $relay = (string) ($picked['url'] ?? '');
                $secret = (string) ($picked['secret'] ?? '');
                $cfg = WorkerRelayService::get();
                $cacheTtl = (int) ($cfg['cacheTtlSeconds'] ?? 7200);
            } elseif (WorkerRelayService::get()['enabled'] === false) {
                return ['error' => 'relay disabled'];
            }
        }
        if ($relay === '') {
            $relay = self::envValue('YORU_RELAY_URL');
            $secret = self::envValue('YORU_RELAY_SECRET');
        }
        if ($relay === '') {
            return ['error' => 'relay not configured'];
        }

        // Prefer /videasy (multi-server); fall back to classic /resolve (Yoru only).
        foreach (['videasy', 'resolve'] as $route) {
            $qs = http_build_query([
                'type' => $type,
                'tmdb' => $tmdbId,
                'season' => max(1, $season),
                'episode' => max(1, $episode),
                'cacheTtl' => max(60, $cacheTtl),
            ]);
            $url = rtrim($relay, '/') . '/' . $route . '?' . $qs;
            $ch = curl_init($url);
            if ($ch === false) {
                continue;
            }
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_CONNECTTIMEOUT => 8,
                CURLOPT_TIMEOUT => 45,
                CURLOPT_HTTPHEADER => [
                    'Accept: application/json',
                    'x-yoru-key: ' . $secret,
                ],
            ]);
            $body = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $cerr = (string) curl_error($ch);
            curl_close($ch);
            if (!is_string($body) || $body === '') {
                $diagnostics[] = [
                    'code' => 'VIDEASY_RELAY_EMPTY',
                    'message' => $cerr !== '' ? $cerr : ('relay ' . $route . ' empty'),
                    'severity' => 'warning',
                    'provider' => self::PROVIDER_ID,
                ];
                continue;
            }
            $json = json_decode($body, true);
            if (!is_array($json)) {
                continue;
            }
            if (empty($json['sources'])) {
                $diagnostics[] = [
                    'code' => 'VIDEASY_RELAY_EMPTY',
                    'message' => (string) ($json['error'] ?? ('relay ' . $route . ' HTTP ' . $status)),
                    'severity' => 'warning',
                    'provider' => self::PROVIDER_ID,
                ];
                continue;
            }
            $diagnostics[] = [
                'code' => 'VIDEASY_RELAY_OK',
                'message' => 'Videasy via relay /' . $route . ' (' . (string) ($json['server'] ?? '?') . ', ' . count($json['sources']) . ' streams)',
                'severity' => 'info',
                'provider' => self::PROVIDER_ID,
            ];
            return $json;
        }

        return ['error' => 'relay failed'];
    }

    /**
     * @param list<array<string,mixed>> $diagnostics
     * @return array{sources?:list<array<string,mixed>>,error?:string,server?:string}
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
                'code' => 'VIDEASY_SCRIPT_MISSING',
                'message' => 'videasy-resolve.mjs not found',
                'severity' => 'warning',
                'provider' => self::PROVIDER_ID,
            ];
            return ['error' => 'resolver script missing'];
        }

        $cmd = [
            'node',
            $script,
            '--type',
            $type,
            '--tmdb',
            (string) $tmdbId,
            '--season',
            (string) max(1, $season),
            '--episode',
            (string) max(1, $episode),
        ];
        $desc = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $env = self::resolverEnv();
        $proc = proc_open($cmd, $desc, $pipes, dirname($script), $env);
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
                'code' => 'VIDEASY_RESOLVE_BAD_JSON',
                'message' => trim($stderr) !== '' ? trim($stderr) : 'empty resolver output',
                'severity' => 'warning',
                'provider' => self::PROVIDER_ID,
            ];
            return ['error' => 'resolver returned invalid JSON', 'exit' => $code];
        }
        if (empty($json['sources'])) {
            $diagnostics[] = [
                'code' => 'VIDEASY_NODE_EMPTY',
                'message' => (string) ($json['error'] ?? 'node resolver empty'),
                'severity' => 'warning',
                'provider' => self::PROVIDER_ID,
            ];
            return $json;
        }
        $diagnostics[] = [
            'code' => 'VIDEASY_NODE_OK',
            'message' => 'Videasy via node (' . (string) ($json['server'] ?? '?') . ', ' . count($json['sources']) . ' streams)',
            'severity' => 'info',
            'provider' => self::PROVIDER_ID,
        ];
        return $json;
    }

    private static function resolveScriptPath(): ?string
    {
        foreach ([
            dirname(__DIR__, 2) . '/bin/videasy-resolve.mjs',
            '/var/www/chillflix-newsite/bin/videasy-resolve.mjs',
            dirname(__DIR__, 2) . '/bin/cineplay-yoru-resolve.mjs',
            '/var/www/chillflix-newsite/bin/cineplay-yoru-resolve.mjs',
        ] as $candidate) {
            if (is_readable($candidate)) {
                return $candidate;
            }
        }
        return null;
    }

    /** @return array<string,string> */
    private static function resolverEnv(): array
    {
        $env = [];
        foreach ($_SERVER as $k => $v) {
            if (is_string($k) && is_string($v) && preg_match('/^[A-Z0-9_]+$/', $k)) {
                $env[$k] = $v;
            }
        }
        foreach ([
            'PROVIDER_FETCH_PROXY',
            'OUTBOUND_HTTP_PROXY',
            'HTTPS_PROXY',
            'HTTP_PROXY',
            'VIXSRC_PROXY',
            'PATH',
            'HOME',
        ] as $k) {
            $v = self::envValue($k);
            if ($v !== '') {
                $env[$k] = $v;
            }
        }
        return $env;
    }

    private static function envValue(string $key): string
    {
        $v = getenv($key);
        if (is_string($v) && $v !== '') {
            return $v;
        }
        return isset($_ENV[$key]) && is_string($_ENV[$key]) ? $_ENV[$key] : '';
    }

    private static function cacheDir(): string
    {
        $base = '/var/www/chillflix-newsite/storage/cache/videasy';
        $alt = dirname(__DIR__, 2) . '/storage/cache/videasy';
        $dir = is_dir(dirname($base)) ? $base : $alt;
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        return $dir;
    }

    private static function writeRawCache(string $key, array $raw): void
    {
        $path = self::cacheDir() . '/' . hash('sha256', $key) . '.json';
        $payload = json_encode([
            'exp' => time() + self::RAW_CACHE_TTL_SEC,
            'raw' => $raw,
        ], JSON_UNESCAPED_SLASHES);
        if (is_string($payload)) {
            @file_put_contents($path, $payload, LOCK_EX);
        }
    }

    private static function readRawCache(string $key): ?array
    {
        $path = self::cacheDir() . '/' . hash('sha256', $key) . '.json';
        if (!is_readable($path)) {
            return null;
        }
        $json = json_decode((string) file_get_contents($path), true);
        if (!is_array($json) || (int) ($json['exp'] ?? 0) < time()) {
            return null;
        }
        return is_array($json['raw'] ?? null) ? $json['raw'] : null;
    }
}
