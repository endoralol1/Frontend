<?php
declare(strict_types=1);

/**
 * VidPlay / stream.torrentio.to "instant" path.
 *
 * They don't stream magnets in the browser for cached titles — they expose a
 * public cache of torrents already uploaded to file hosts (Byse, Lulu, VOE…):
 *   GET https://stream.torrentio.to/api/v1/torrent/cache?imdbId=tt…
 *   (+ season=&episode= for TV)
 *
 * We take the best Byse embed and resolve HLS via ByseSources::resolveFromEmbed.
 */
final class TorrentioCacheSources
{
    public const PROVIDER_ID = 'torrentio';
    public const PROVIDER_NAME = 'Torrentio';

    private const API = 'https://stream.torrentio.to/api/v1/torrent/cache';
    private const UA =
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';

    /**
     * @return array{ok:bool,sources?:list<array<string,mixed>>,diagnostics?:list<array<string,mixed>>,error?:string}
     */
    public static function fetch(string $type, int $tmdbId, int $season = 1, int $episode = 1): array
    {
        $type = $type === 'tv' ? 'tv' : 'movie';
        $diagnostics = [];
        if ($tmdbId < 1) {
            return ['ok' => false, 'error' => 'Invalid TMDB id', 'sources' => [], 'diagnostics' => []];
        }

        $imdb = self::imdbFor($type, $tmdbId);
        if ($imdb === null) {
            return [
                'ok' => false,
                'sources' => [],
                'diagnostics' => [[
                    'code' => 'TORRENTIO_NO_IMDB',
                    'message' => 'No IMDb id for TMDB ' . $tmdbId,
                    'severity' => 'warning',
                    'provider' => self::PROVIDER_ID,
                ]],
                'error' => 'No IMDb id',
            ];
        }

        $rows = self::fetchCache($imdb, $type, max(1, $season), max(1, $episode), $diagnostics);
        if ($rows === []) {
            return [
                'ok' => false,
                'sources' => [],
                'diagnostics' => $diagnostics,
                'error' => 'No Torrentio cache for this title',
            ];
        }

        usort($rows, static function (array $a, array $b): int {
            return TorrentioCacheSources::rowScore($b) <=> TorrentioCacheSources::rowScore($a);
        });

        if (!class_exists('ByseSources') || !method_exists('ByseSources', 'resolveFromEmbed')) {
            return [
                'ok' => false,
                'sources' => [],
                'diagnostics' => [[
                    'code' => 'TORRENTIO_NO_BYSE',
                    'message' => 'ByseSources::resolveFromEmbed missing',
                    'severity' => 'error',
                    'provider' => self::PROVIDER_ID,
                ]],
                'error' => 'Byse resolver missing',
            ];
        }

        foreach (array_slice($rows, 0, 4) as $row) {
            $byse = self::byseEmbed($row);
            if ($byse === null) {
                continue;
            }
            $resolved = ByseSources::resolveFromEmbed($byse, $diagnostics);
            if (empty($resolved['ok']) || empty($resolved['sources'][0]['url'])) {
                $diagnostics[] = [
                    'code' => 'TORRENTIO_BYSE_FAIL',
                    'message' => (string) ($resolved['error'] ?? 'Byse resolve failed') . ' for ' . $byse,
                    'severity' => 'warning',
                    'provider' => self::PROVIDER_ID,
                ];
                continue;
            }

            $quality = self::qualityFromName((string) ($row['torrent_name'] ?? ''));
            if ($quality === 'Auto' && !empty($resolved['sources'][0]['quality'])) {
                $quality = (string) $resolved['sources'][0]['quality'];
            }

            $sources = [];
            foreach ($resolved['sources'] as $src) {
                if (!is_array($src) || empty($src['url'])) {
                    continue;
                }
                $direct = (string) $src['url'];
                $base = (string) ($resolved['base'] ?? 'https://bysesayeveum.com');
                $headers = [
                    'Referer' => rtrim($base, '/') . '/',
                    'Origin' => rtrim($base, '/'),
                    'Accept' => '*/*',
                    'User-Agent' => self::UA,
                ];
                $playUrl = $direct;
                if (class_exists('VidmolySources') && method_exists('VidmolySources', 'signedProxyUrl')) {
                    $playUrl = VidmolySources::signedProxyUrl($direct, $headers, true);
                }
                $q = (string) ($src['quality'] ?? $quality);
                $sources[] = [
                    'url' => $playUrl,
                    'type' => 'hls',
                    'quality' => $q !== '' ? $q : $quality,
                    'provider' => self::PROVIDER_ID,
                    'providerName' => self::PROVIDER_NAME,
                    'label' => self::PROVIDER_NAME . ' · ' . ($q !== '' ? $q : $quality),
                    'language' => 'en',
                    'hasEnglish' => true,
                    'meta' => [
                        'backend' => 'torrentio-cache',
                        'via' => 'byse',
                        'embed' => $byse,
                        'torrent' => self::shortName((string) ($row['torrent_name'] ?? '')),
                        'session_id' => (string) ($row['session_id'] ?? ''),
                        'direct' => $direct,
                        'cached' => !empty($resolved['cached']),
                    ],
                ];
            }

            if ($sources === []) {
                continue;
            }

            $diagnostics[] = [
                'code' => 'TORRENTIO_OK',
                'message' => 'Cache → Byse (' . self::shortName((string) ($row['torrent_name'] ?? '')) . ')',
                'severity' => 'info',
                'provider' => self::PROVIDER_ID,
            ];

            return [
                'ok' => true,
                'sources' => $sources,
                'diagnostics' => $diagnostics,
            ];
        }

        return [
            'ok' => false,
            'sources' => [],
            'diagnostics' => $diagnostics,
            'error' => 'Torrentio cache embeds failed to resolve',
        ];
    }

    /**
     * @param list<array<string,mixed>> $diagnostics
     * @return list<array<string,mixed>>
     */
    private static function fetchCache(
        string $imdb,
        string $type,
        int $season,
        int $episode,
        array &$diagnostics
    ): array {
        $qs = ['imdbId' => $imdb];
        if ($type === 'tv') {
            $qs['season'] = (string) $season;
            $qs['episode'] = (string) $episode;
        }
        $url = self::API . '?' . http_build_query($qs);
        $res = self::httpRaw($url);
        if ($res === null) {
            $diagnostics[] = [
                'code' => 'TORRENTIO_HTTP',
                'message' => 'cache API request failed',
                'severity' => 'warning',
                'provider' => self::PROVIDER_ID,
            ];
            return [];
        }
        [$code, $body] = $res;
        if ($code < 200 || $code >= 300) {
            $diagnostics[] = [
                'code' => 'TORRENTIO_HTTP',
                'message' => 'cache API HTTP ' . $code,
                'severity' => 'warning',
                'provider' => self::PROVIDER_ID,
            ];
            return [];
        }
        $json = json_decode($body, true);
        $cache = is_array($json) ? ($json['cache'] ?? []) : [];
        if (!is_array($cache) || $cache === []) {
            $diagnostics[] = [
                'code' => 'TORRENTIO_EMPTY',
                'message' => 'No cached uploads for ' . $imdb,
                'severity' => 'warning',
                'provider' => self::PROVIDER_ID,
            ];
            return [];
        }
        $out = [];
        foreach ($cache as $row) {
            if (is_array($row) && self::byseEmbed($row) !== null) {
                $out[] = $row;
            }
        }
        if ($out === []) {
            $diagnostics[] = [
                'code' => 'TORRENTIO_NO_BYSE_EMBED',
                'message' => 'Cache had ' . count($cache) . ' rows but no Byse embeds',
                'severity' => 'warning',
                'provider' => self::PROVIDER_ID,
            ];
        }
        return $out;
    }

    /** @param array<string,mixed> $row */
    private static function byseEmbed(array $row): ?string
    {
        $servers = is_array($row['servers'] ?? null) ? $row['servers'] : [];
        $byse = trim((string) ($servers['byse'] ?? ''));
        if ($byse !== '' && preg_match('#^https?://[^/]+/e/[a-zA-Z0-9]+#', $byse)) {
            return $byse;
        }
        // Sometimes streaming_url itself is Byse.
        $stream = trim((string) ($row['streaming_url'] ?? ''));
        if ($stream !== '' && preg_match('#^https?://(?:[\w.-]*byse[^/]*|bysesayeveum\.com)/e/[a-zA-Z0-9]+#i', $stream)) {
            return $stream;
        }
        return null;
    }

    /** Prefer clean 1080p with peers; demote CAM/TS. */
    private static function rowScore(array $row): int
    {
        $name = (string) ($row['torrent_name'] ?? '');
        $score = 0;
        $low = strtolower($name);
        if (str_contains($low, '2160') || str_contains($low, '4k')) {
            $score += 400;
        } elseif (str_contains($low, '1080')) {
            $score += 300;
        } elseif (str_contains($low, '720')) {
            $score += 200;
        }
        if (preg_match('/cam|hdts|telesync|\bts\b|hdcam|scr\b/i', $name)) {
            $score -= 250;
        }
        if (preg_match('/👤\s*(\d+)/u', $name, $m)) {
            $score += min(200, (int) $m[1]);
        }
        // Prefer more recent uploads slightly.
        $score += (int) min(50, ((int) ($row['timestamp'] ?? 0)) % 100);
        return $score;
    }

    private static function qualityFromName(string $name): string
    {
        $low = strtolower($name);
        if (str_contains($low, '2160') || str_contains($low, '4k')) {
            return '2160p';
        }
        if (str_contains($low, '1080')) {
            return '1080p';
        }
        if (str_contains($low, '720')) {
            return '720p';
        }
        if (preg_match('/cam|hdts|telesync|\bts\b/i', $name)) {
            return 'CAM';
        }
        return 'Auto';
    }

    private static function shortName(string $name): string
    {
        $line = trim(explode("\n", $name)[0] ?? $name);
        if (strlen($line) > 72) {
            return substr($line, 0, 69) . '…';
        }
        return $line;
    }

    private static function imdbFor(string $type, int $tmdbId): ?string
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

    /** @return array{0:int,1:string}|null */
    private static function httpRaw(string $url): ?array
    {
        if (!function_exists('curl_init')) {
            return null;
        }
        $ch = curl_init($url);
        if ($ch === false) {
            return null;
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 25,
            CURLOPT_HTTPHEADER => [
                'User-Agent: ' . self::UA,
                'Accept: application/json, */*',
                'Origin: https://stream.torrentio.to',
                'Referer: https://stream.torrentio.to/',
            ],
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if (!is_string($body)) {
            return null;
        }
        return [$code, $body];
    }
}
