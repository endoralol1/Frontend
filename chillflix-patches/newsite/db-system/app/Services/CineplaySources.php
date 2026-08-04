<?php
declare(strict_types=1);

/**
 * Cineplay / Vidking → Yoru (4K) scraper for the native Vuflix player.
 *
 * Cineplay's player calls api.speedracelight.com `/cdn/sources-with-title`
 * (server name "Yoru") with enc=2 + seed decrypt. We scrape that same path
 * and wrap HLS through /api/player/media-proxy so hls.js plays in our UI.
 *
 * Datacenter IPs are often CF-banned (1015/429). Set PROVIDER_FETCH_PROXY
 * (residential) for reliable server-side resolve. If scrape fails, we return
 * a client-resolve stub so the browser can retry, then iframe as last resort.
 */
final class CineplaySources
{
    public const EMBED_ORIGIN = 'https://www.vidking.net';
    public const API_ORIGIN = 'https://api.speedracelight.com';

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
        $scraped = self::scrapeYoru($type, $tmdbId, $season, $episode, $diagnostics);
        if (!empty($scraped['sources'])) {
            return [
                'ok' => true,
                'sources' => $scraped['sources'],
                'diagnostics' => $diagnostics,
            ];
        }

        // Browser will scrape Yoru (viewer IP) then mint a media-proxy URL.
        $sources = [
            [
                'url' => 'cineplay-yoru://' . $type . '/' . $tmdbId
                    . ($type === 'tv' ? ('/' . max(1, $season) . '/' . max(1, $episode)) : ''),
                'type' => 'cineplay-yoru',
                'quality' => '4K',
                'provider' => 'cineplay',
                'providerName' => 'Cineplay',
                'label' => 'Cineplay · Yoru 4K',
                'language' => 'en',
                'meta' => [
                    'server' => 'Yoru',
                    'mediaType' => $type,
                    'tmdbId' => $tmdbId,
                    'season' => max(1, $season),
                    'episode' => max(1, $episode),
                    'title' => (string) ($scraped['title'] ?? ''),
                    'year' => (string) ($scraped['year'] ?? ''),
                    'imdbId' => (string) ($scraped['imdbId'] ?? ''),
                    'embedFallback' => self::embedUrl($type, $tmdbId, $season, $episode),
                ],
            ],
        ];

        $diagnostics[] = [
            'code' => 'CINEPLAY_CLIENT_RESOLVE',
            'message' => (string) ($scraped['error'] ?? 'Server scrape blocked; browser will resolve Yoru'),
            'severity' => 'info',
            'provider' => 'cineplay',
        ];

        return [
            'ok' => true,
            'sources' => $sources,
            'diagnostics' => $diagnostics,
        ];
    }

    public static function embedUrl(string $type, int $tmdbId, int $season = 1, int $episode = 1): string
    {
        $qs = ['autoPlay' => 'true', 'color' => 'e50914'];
        if ($type === 'tv') {
            $qs['nextEpisode'] = 'true';
            $qs['episodeSelector'] = 'true';
            $path = '/embed/tv/' . $tmdbId . '/' . max(1, $season) . '/' . max(1, $episode);
        } else {
            $path = '/embed/movie/' . $tmdbId;
        }
        return self::EMBED_ORIGIN . $path . '?' . http_build_query($qs);
    }

    /**
     * Mint a signed media-proxy URL for a scraped stream (used by browser resolve).
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

        $out = [];
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
            $streamType = str_contains(strtolower($rawUrl), '.m3u8') ? 'hls' : (string) ($src['type'] ?? 'hls');
            try {
                $playUrl = self::mintProxy($rawUrl, $headers, true);
            } catch (Throwable $e) {
                $playUrl = $rawUrl;
            }
            $out[] = [
                'url' => $playUrl,
                'type' => $streamType === 'iframe' ? 'hls' : $streamType,
                'quality' => $quality !== '' ? $quality : 'Auto',
                'provider' => 'cineplay',
                'providerName' => 'Cineplay',
                'label' => 'Cineplay · Yoru' . ($quality !== '' && strcasecmp($quality, 'Auto') !== 0 ? (' · ' . $quality) : ''),
                'language' => 'en',
                'meta' => [
                    'server' => 'Yoru',
                    'via' => 'speedracelight-cdn',
                    'rawHost' => (string) (parse_url($rawUrl, PHP_URL_HOST) ?: ''),
                ],
            ];
        }

        // Prefer 4K / 2160p first
        usort($out, static function (array $a, array $b): int {
            return self::qualityRank((string) $b['quality']) <=> self::qualityRank((string) $a['quality']);
        });

        $diagnostics[] = [
            'code' => 'CINEPLAY_YORU_OK',
            'message' => 'Scraped ' . count($out) . ' Yoru stream(s)',
            'severity' => 'info',
            'provider' => 'cineplay',
        ];

        return [
            'sources' => $out,
            'title' => (string) ($json['title'] ?? ''),
            'year' => (string) ($json['year'] ?? ''),
            'imdbId' => (string) ($json['imdbId'] ?? ''),
        ];
    }

    private static function qualityRank(string $q): int
    {
        $q = strtolower(trim($q));
        if (str_contains($q, '2160') || $q === '4k' || $q === '4K') {
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
        // Prefer cinepro's provider proxy if present in process env / dotenv-less deploy.
        foreach (['PROVIDER_FETCH_PROXY', 'OUTBOUND_HTTP_PROXY', 'HTTPS_PROXY', 'HTTP_PROXY', 'VIXSRC_PROXY'] as $k) {
            $v = getenv($k);
            if (is_string($v) && $v !== '') {
                $env[$k] = $v;
            }
        }
        // Pull from cinepro .env if our process lacks proxy.
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
                    if (in_array($k, ['PROVIDER_FETCH_PROXY', 'VIXSRC_PROXY', 'OUTBOUND_HTTP_PROXY', 'HTTPS_PROXY', 'HTTP_PROXY'], true) && $v !== '') {
                        $env[$k] = $v;
                    }
                }
            }
        }
        $env['PATH'] = $env['PATH'] ?? '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin';
        // Resolve undici from cinepro when available
        $env['NODE_PATH'] = '/var/www/cinepro/node_modules' . (isset($env['NODE_PATH']) && $env['NODE_PATH'] !== '' ? (':' . $env['NODE_PATH']) : '');
        return $env;
    }
}
