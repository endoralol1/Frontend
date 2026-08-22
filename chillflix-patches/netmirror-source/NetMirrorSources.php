<?php
declare(strict_types=1);

/**
 * NetMirror / nfMirror scraper (netNN.cc fronts).
 *
 * Discovery uses Jina reader against search.php + playlist.php (CF HTML challenge
 * blocks datacenter IPs). Playback URLs are netNN.cc HLS; VidmolySources v-relay
 * should fetch those via the Yoru Worker /stream path (net*.cc allowlisted).
 */
final class NetMirrorSources
{
    private const FRONTS = [
        'https://net77.cc',
        'https://net52.cc',
        'https://net51.cc',
    ];

    private const JINA = 'https://r.jina.ai';
    private const RESULT_TTL = 600;
    /** @var array<string,array{expires:int,payload:array}> */
    private static array $cache = [];

    /**
     * @return array{ok:bool,sources?:list<array<string,mixed>>,diagnostics?:list<array<string,mixed>>,error?:string}
     */
    public static function fetch(string $type, int $tmdbId, int $season = 1, int $episode = 1): array
    {
        $type = $type === 'tv' ? 'tv' : 'movie';
        $diagnostics = [];
        $titleInfo = self::resolveTitle($type, $tmdbId);
        $title = trim((string) ($titleInfo['title'] ?? ''));
        $year = trim((string) ($titleInfo['year'] ?? ''));
        if ($title === '') {
            return [
                'ok' => false,
                'sources' => [],
                'diagnostics' => [[
                    'code' => 'NETMIRROR_NO_TITLE',
                    'message' => 'Could not resolve TMDB title',
                    'severity' => 'warning',
                    'provider' => 'netmirror',
                ]],
                'error' => 'No title',
            ];
        }

        $cacheKey = $type === 'tv'
            ? "tv:{$tmdbId}:s{$season}:e{$episode}"
            : "movie:{$tmdbId}";
        if (isset(self::$cache[$cacheKey]) && self::$cache[$cacheKey]['expires'] > time()) {
            return self::$cache[$cacheKey]['payload'];
        }

        $hit = self::searchBest($title, $year, $diagnostics);
        if ($hit === null) {
            $payload = [
                'ok' => false,
                'sources' => [],
                'diagnostics' => $diagnostics ?: [[
                    'code' => 'NETMIRROR_EMPTY',
                    'message' => 'No NetMirror match for “' . $title . '”',
                    'severity' => 'warning',
                    'provider' => 'netmirror',
                ]],
                'error' => 'No NetMirror match',
            ];
            self::$cache[$cacheKey] = ['expires' => time() + 120, 'payload' => $payload];
            return $payload;
        }

        $nmId = (string) $hit['id'];
        // TV: prefer Netflix-style episode id when playlist supports it.
        if ($type === 'tv') {
            $epId = $nmId . '-' . max(1, $season) . 'x' . max(1, $episode);
            $epPlaylist = self::fetchPlaylist($epId, $diagnostics);
            if ($epPlaylist !== []) {
                $nmId = $epId;
                $playlist = $epPlaylist;
            } else {
                $playlist = self::fetchPlaylist($nmId, $diagnostics);
            }
        } else {
            $playlist = self::fetchPlaylist($nmId, $diagnostics);
        }

        if ($playlist === []) {
            $payload = [
                'ok' => false,
                'sources' => [],
                'diagnostics' => $diagnostics ?: [[
                    'code' => 'NETMIRROR_EMPTY',
                    'message' => 'NetMirror playlist empty for id ' . $nmId,
                    'severity' => 'warning',
                    'provider' => 'netmirror',
                ]],
                'error' => 'Empty playlist',
            ];
            self::$cache[$cacheKey] = ['expires' => time() + 90, 'payload' => $payload];
            return $payload;
        }

        $front = self::FRONTS[0];
        $headers = [
            'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
            'Referer' => rtrim($front, '/') . '/',
            'Origin' => rtrim($front, '/'),
            'Accept' => '*/*',
        ];

        $sources = [];
        foreach ($playlist as $item) {
            if (!is_array($item)) {
                continue;
            }
            $file = trim((string) ($item['file'] ?? ''));
            if ($file === '') {
                continue;
            }
            if (str_starts_with($file, '//')) {
                $file = 'https:' . $file;
            } elseif (str_starts_with($file, '/')) {
                $file = rtrim($front, '/') . $file;
            }
            if (!preg_match('#^https?://#i', $file)) {
                continue;
            }
            $label = trim((string) ($item['label'] ?? 'Auto'));
            if ($label === '') {
                $label = 'Auto';
            }
            $quality = self::labelToQuality($label);
            $sources[] = [
                'url' => $file,
                'type' => 'hls',
                'quality' => $quality,
                'provider' => 'netmirror',
                'providerName' => 'NetMirror',
                'label' => 'NetMirror · ' . $quality,
                'language' => 'en',
                'hasEnglish' => true,
                'headers' => $headers,
            ];
        }

        // Prefer Full HD / highest first.
        usort($sources, static function (array $a, array $b): int {
            return self::qualityRank((string) $b['quality']) <=> self::qualityRank((string) $a['quality']);
        });
        // Cap to 3 qualities for the race pack / Quality menu.
        $sources = array_slice($sources, 0, 3);

        $payload = [
            'ok' => $sources !== [],
            'sources' => $sources,
            'diagnostics' => $diagnostics,
            'error' => $sources === [] ? 'No playable NetMirror streams' : null,
            'meta' => [
                'netmirrorId' => $nmId,
                'matchedTitle' => (string) ($hit['t'] ?? ''),
                'queryTitle' => $title,
            ],
        ];
        self::$cache[$cacheKey] = ['expires' => time() + self::RESULT_TTL, 'payload' => $payload];
        return $payload;
    }

    /** @return array{title?:string,year?:string} */
    private static function resolveTitle(string $type, int $tmdbId): array
    {
        try {
            if (!class_exists('Tmdb')) {
                return [];
            }
            $tmdb = $GLOBALS['tmdb'] ?? null;
            if (!$tmdb instanceof Tmdb) {
                $tmdb = new Tmdb();
            }
            $details = $tmdb->details($type, $tmdbId);
            if ($type === 'tv') {
                return [
                    'title' => trim((string) ($details['name'] ?? '')),
                    'year' => substr((string) ($details['first_air_date'] ?? ''), 0, 4),
                ];
            }
            return [
                'title' => trim((string) ($details['title'] ?? '')),
                'year' => substr((string) ($details['release_date'] ?? ''), 0, 4),
            ];
        } catch (Throwable $e) {
            return [];
        }
    }

    /**
     * @param list<array<string,mixed>> $diagnostics
     * @return array{id:string,t:string}|null
     */
    private static function searchBest(string $title, string $year, array &$diagnostics): ?array
    {
        $queries = array_values(array_unique(array_filter([
            $title,
            $year !== '' ? ($title . ' ' . $year) : '',
            preg_replace('/\s*\([^)]*\)\s*/', ' ', $title) ?: $title,
        ], static fn($q) => trim((string) $q) !== '')));

        $best = null;
        $bestScore = -1;
        foreach ($queries as $q) {
            $results = self::search($q, $diagnostics);
            foreach ($results as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $id = trim((string) ($row['id'] ?? ''));
                $t = trim((string) ($row['t'] ?? $row['title'] ?? ''));
                if ($id === '' || $t === '') {
                    continue;
                }
                $score = self::titleScore($title, $t, $year);
                if ($score > $bestScore) {
                    $bestScore = $score;
                    $best = ['id' => $id, 't' => $t];
                }
            }
            if ($bestScore >= 90) {
                break;
            }
        }
        if ($best === null || $bestScore < 45) {
            $diagnostics[] = [
                'code' => 'NETMIRROR_NO_MATCH',
                'message' => 'Search miss for “' . $title . '” (best=' . $bestScore . ')',
                'severity' => 'warning',
                'provider' => 'netmirror',
            ];
            return null;
        }
        $diagnostics[] = [
            'code' => 'NETMIRROR_MATCH',
            'message' => 'Matched “' . $best['t'] . '” (id ' . $best['id'] . ', score ' . $bestScore . ')',
            'severity' => 'info',
            'provider' => 'netmirror',
        ];
        return $best;
    }

    /**
     * @param list<array<string,mixed>> $diagnostics
     * @return list<array<string,mixed>>
     */
    private static function search(string $query, array &$diagnostics): array
    {
        $tm = (string) (int) round(microtime(true) * 1000);
        foreach (self::FRONTS as $front) {
            $url = rtrim($front, '/') . '/search.php?s=' . rawurlencode($query) . '&t=' . $tm;
            $json = self::jinaJson($url);
            if (!is_array($json)) {
                continue;
            }
            $rows = $json['searchResult'] ?? null;
            if (is_array($rows) && $rows !== []) {
                return $rows;
            }
        }
        $diagnostics[] = [
            'code' => 'NETMIRROR_SEARCH_EMPTY',
            'message' => 'search.php returned no rows for “' . $query . '”',
            'severity' => 'info',
            'provider' => 'netmirror',
        ];
        return [];
    }

    /**
     * @param list<array<string,mixed>> $diagnostics
     * @return list<array<string,mixed>>
     */
    private static function fetchPlaylist(string $id, array &$diagnostics): array
    {
        $tm = (string) (int) round(microtime(true) * 1000);
        foreach (self::FRONTS as $front) {
            // Hash is currently ignored by playlist.php; keep a placeholder for compatibility.
            $url = rtrim($front, '/') . '/playlist.php?id=' . rawurlencode($id) . '&tm=' . $tm . '&h=1';
            $json = self::jinaJson($url);
            if (!is_array($json)) {
                continue;
            }
            // Shape: [ { sources: [ {file,label,type} ], tracks: [...] } ]
            $first = $json[0] ?? $json;
            if (!is_array($first)) {
                continue;
            }
            $sources = $first['sources'] ?? null;
            if (is_array($sources) && $sources !== []) {
                return $sources;
            }
        }
        $diagnostics[] = [
            'code' => 'NETMIRROR_PLAYLIST_EMPTY',
            'message' => 'playlist.php empty for id ' . $id,
            'severity' => 'warning',
            'provider' => 'netmirror',
        ];
        return [];
    }

    /** @return array<string,mixed>|list<mixed>|null */
    private static function jinaJson(string $targetUrl): mixed
    {
        $endpoint = self::JINA . '/' . $targetUrl;
        if (!function_exists('curl_init')) {
            return null;
        }
        $ch = curl_init($endpoint);
        if ($ch === false) {
            return null;
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT => 35,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'User-Agent: curl/8.0',
            ],
        ]);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if (!is_string($body) || $body === '' || $status >= 400) {
            return null;
        }
        $wrap = json_decode($body, true);
        $content = null;
        if (is_array($wrap)) {
            $content = $wrap['data']['content'] ?? $wrap['content'] ?? null;
        }
        if (!is_string($content) || $content === '') {
            // Sometimes body is already the upstream JSON.
            $direct = json_decode($body, true);
            return is_array($direct) ? $direct : null;
        }
        $content = trim($content);
        $decoded = json_decode($content, true);
        if (is_array($decoded)) {
            return $decoded;
        }
        // playlist.php occasionally returns JS-ish whitespace; try to extract JSON array/object.
        if (preg_match('/(\[.*\]|\{.*\})/s', $content, $m)) {
            $decoded = json_decode($m[1], true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }
        return null;
    }

    private static function titleScore(string $want, string $got, string $year): int
    {
        $a = self::normTitle($want);
        $b = self::normTitle($got);
        if ($a === '' || $b === '') {
            return 0;
        }
        if ($a === $b) {
            $score = 100;
        } elseif (str_contains($b, $a) || str_contains($a, $b)) {
            $score = 80;
        } else {
            similar_text($a, $b, $pct);
            $score = (int) round($pct);
        }
        if ($year !== '' && preg_match('/\b' . preg_quote($year, '/') . '\b/', $got)) {
            $score = min(100, $score + 8);
        }
        return $score;
    }

    private static function normTitle(string $t): string
    {
        $t = strtolower($t);
        $t = preg_replace('/[^a-z0-9]+/', ' ', $t) ?? $t;
        return trim(preg_replace('/\s+/', ' ', $t) ?? $t);
    }

    private static function labelToQuality(string $label): string
    {
        if (preg_match('/2160|4k|uhd/i', $label)) {
            return '2160p';
        }
        if (preg_match('/1080|full\s*hd/i', $label)) {
            return '1080p';
        }
        if (preg_match('/720|mid\s*hd/i', $label)) {
            return '720p';
        }
        if (preg_match('/480|low\s*hd/i', $label)) {
            return '480p';
        }
        return 'Auto';
    }

    private static function qualityRank(string $q): int
    {
        if (preg_match('/(\d{3,4})/', $q, $m)) {
            return (int) $m[1];
        }
        return 0;
    }

    /** True when upstream host should egress via Yoru Worker (CF-challenged). */
    public static function needsWorkerEgress(string $url): bool
    {
        $host = strtolower((string) (parse_url($url, PHP_URL_HOST) ?: ''));
        if ($host === '') {
            return false;
        }
        if (preg_match('/^net\d{2,3}\.cc$/', $host)) {
            return true;
        }
        return str_ends_with($host, 'nfmirrorcdn.top')
            || $host === 'netmirror.gg'
            || $host === 'netmirror.app'
            || $host === 'imgcdn.kim'
            || $host === 'subscdn.top';
    }
}
