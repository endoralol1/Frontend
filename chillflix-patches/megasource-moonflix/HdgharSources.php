<?php
declare(strict_types=1);

/**
 * Vuflix-only scraper: HDGHAR / Moonflix via hdghartv.cc public API.
 *
 * Catalog + streamingLinks are public (no auth):
 *   GET /api/search?q=...
 *   GET /api/movies/public/{id}
 *   GET /api/series/public/{id}  → seasons[].episodes[].streamingLinks
 *
 * CDN: *.streamraiwind.stream (reachable from VPS with hdghartv Referer).
 * Optional legacy override: HDGHAR_API_BASE → old Railway-style /movie/{tmdb} JSON.
 */
final class HdgharSources
{
    public const PROVIDER_ID = 'hdghar';
    public const PROVIDER_NAME = 'HDGHAR';
    public const SITE = 'https://hdghartv.cc';
    /** @deprecated dead Railway default; only used when HDGHAR_API_BASE is set */
    public const BASE = 'https://mindful-wholeness-production-4ac0.up.railway.app';

    private const UA =
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';

    public static function apiBase(): string
    {
        $env = getenv('HDGHAR_API_BASE');
        if (is_string($env) && trim($env) !== '') {
            return rtrim(trim($env), '/');
        }
        if (function_exists('config')) {
            $cfg = (string) config('hdghar_api_base', '');
            if ($cfg !== '') {
                return rtrim($cfg, '/');
            }
        }
        return '';
    }

    public static function hostAllowed(): bool
    {
        $host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
        $host = preg_replace('/:\d+$/', '', $host) ?: $host;
        $host = preg_replace('/^www\./', '', $host) ?: $host;
        return $host === 'vuflix.co' || $host === 'localhost' || $host === '127.0.0.1';
    }

    /**
     * @return array{ok:bool,sources?:list<array<string,mixed>>,diagnostics?:list<array<string,mixed>>,error?:string}
     */
    public static function fetch(string $type, int $tmdbId, int $season = 1, int $episode = 1): array
    {
        if (!self::hostAllowed()) {
            return [
                'ok' => false,
                'error' => 'HDGHAR is Vuflix-only',
                'sources' => [],
                'diagnostics' => [[
                    'code' => 'HDGHAR_HOST_BLOCKED',
                    'message' => 'HDGHAR is Vuflix-only',
                    'severity' => 'info',
                    'provider' => self::PROVIDER_ID,
                ]],
            ];
        }
        if ($tmdbId < 1) {
            return ['ok' => false, 'error' => 'Invalid TMDB id', 'sources' => [], 'diagnostics' => []];
        }

        $type = $type === 'tv' ? 'tv' : 'movie';
        $diagnostics = [];

        // Optional legacy aggregator (Railway-style) if explicitly configured.
        $legacy = self::apiBase();
        if ($legacy !== '') {
            $fromLegacy = self::fetchLegacyAggregator($legacy, $type, $tmdbId, $season, $episode, $diagnostics);
            if ($fromLegacy !== null) {
                return $fromLegacy;
            }
        }

        $title = self::tmdbTitle($type, $tmdbId);
        $docId = self::findDocId($type, $tmdbId, $title, $diagnostics);
        if ($docId === null) {
            return [
                'ok' => false,
                'error' => 'Not on HDGharTV for this title',
                'sources' => [],
                'diagnostics' => $diagnostics ?: [[
                    'code' => 'HDGHAR_EMPTY',
                    'message' => 'No HDGharTV match for TMDB ' . $tmdbId,
                    'severity' => 'info',
                    'provider' => self::PROVIDER_ID,
                ]],
            ];
        }

        $links = $type === 'tv'
            ? self::seriesLinks($docId, max(1, $season), max(1, $episode), $diagnostics)
            : self::movieLinks($docId, $diagnostics);

        if ($links === []) {
            return [
                'ok' => false,
                'error' => 'No HDGharTV streams',
                'sources' => [],
                'diagnostics' => $diagnostics ?: [[
                    'code' => 'HDGHAR_EMPTY',
                    'message' => 'No streamingLinks on HDGharTV',
                    'severity' => 'warning',
                    'provider' => self::PROVIDER_ID,
                ]],
            ];
        }

        usort($links, static function (array $a, array $b): int {
            return HdgharSources::qualityRank($b['quality']) <=> HdgharSources::qualityRank($a['quality']);
        });

        $headers = [
            'User-Agent' => self::UA,
            'Referer' => self::SITE . '/',
            'Origin' => self::SITE,
            'Accept' => '*/*',
        ];

        // Prefer English audio when multi-audio masters are served (keep CDN Referer).
        if (class_exists('StreamLangProxy')) {
            foreach ($links as &$q) {
                if (($q['type'] ?? '') === 'hls' && !empty($q['url'])) {
                    $q['url'] = StreamLangProxy::mintHlsHeaders((string) $q['url'], $headers, 'eng');
                }
            }
            unset($q);
        }

        $best = $links[0];
        $audioTracks = [
            ['id' => 'en', 'label' => 'English', 'name' => 'English', 'language' => 'en', 'lang' => 'en'],
            ['id' => 'hi', 'label' => 'Hindi', 'name' => 'Hindi', 'language' => 'hi', 'lang' => 'hi'],
            ['id' => 'ta', 'label' => 'Tamil', 'name' => 'Tamil', 'language' => 'ta', 'lang' => 'ta'],
            ['id' => 'te', 'label' => 'Telugu', 'name' => 'Telugu', 'language' => 'te', 'lang' => 'te'],
        ];

        $diagnostics[] = [
            'code' => 'HDGHAR_OK',
            'message' => 'HDGharTV public links ok',
            'severity' => 'info',
            'provider' => self::PROVIDER_ID,
        ];

        $source = [
            'url' => $best['url'],
            'type' => $best['type'],
            'quality' => $best['quality'],
            'provider' => self::PROVIDER_ID,
            'providerName' => self::PROVIDER_NAME,
            'label' => self::PROVIDER_NAME . ' · ' . $best['quality'],
            'language' => 'en',
            'hasEnglish' => true,
            'preferAudio' => 'en',
            'headers' => $headers,
            'qualities' => $links,
            'audioTracks' => $audioTracks,
            'meta' => [
                'backend' => 'hdghartv.cc',
                'docId' => $docId,
                'preferAudio' => 'english',
            ],
        ];

        return [
            'ok' => true,
            'sources' => [$source],
            'diagnostics' => $diagnostics,
            'meta' => [
                'backend' => self::SITE,
                'count' => 1,
            ],
        ];
    }

    /**
     * @param list<array<string,mixed>> $diagnostics
     * @return array{ok:bool,sources:list<array<string,mixed>>,diagnostics:list<array<string,mixed>>,error:?string}|null
     */
    private static function fetchLegacyAggregator(
        string $base,
        string $type,
        int $tmdbId,
        int $season,
        int $episode,
        array &$diagnostics
    ): ?array {
        $url = $type === 'tv'
            ? sprintf('%s/tv/%d/%d/%d', $base, $tmdbId, max(1, $season), max(1, $episode))
            : sprintf('%s/movie/%d', $base, $tmdbId);
        $payload = self::httpGetJson($url);
        if (!is_array($payload) || isset($payload['error']) || empty($payload['streams'])) {
            $diagnostics[] = [
                'code' => 'HDGHAR_LEGACY_MISS',
                'message' => 'Legacy HDGHAR_API_BASE miss; trying HDGharTV',
                'severity' => 'info',
                'provider' => self::PROVIDER_ID,
            ];
            return null;
        }
        $qualities = [];
        foreach ($payload['streams'] as $src) {
            if (!is_array($src) || empty($src['url'])) {
                continue;
            }
            $streamUrl = trim((string) $src['url']);
            if ($streamUrl === '' || !preg_match('#^https?://#i', $streamUrl)) {
                continue;
            }
            $quality = trim((string) ($src['quality'] ?? 'Auto')) ?: 'Auto';
            $streamType = strtolower((string) ($src['type'] ?? 'hls'));
            if ($streamType === 'm3u8') {
                $streamType = 'hls';
            }
            $qualities[] = [
                'quality' => $quality,
                'url' => $streamUrl,
                'type' => $streamType === 'hls' ? 'hls' : 'file',
            ];
        }
        if ($qualities === []) {
            return null;
        }
        usort($qualities, static fn($a, $b) => self::qualityRank($b['quality']) <=> self::qualityRank($a['quality']));
        if (class_exists('StreamLangProxy')) {
            foreach ($qualities as &$q) {
                if (($q['type'] ?? '') === 'hls' && !empty($q['url'])) {
                    $q['url'] = StreamLangProxy::mintMasterPrefer((string) $q['url'], 'eng');
                }
            }
            unset($q);
        }
        $best = $qualities[0];
        return [
            'ok' => true,
            'sources' => [[
                'url' => $best['url'],
                'type' => $best['type'],
                'quality' => $best['quality'],
                'provider' => self::PROVIDER_ID,
                'providerName' => self::PROVIDER_NAME,
                'label' => self::PROVIDER_NAME . ' · English · ' . $best['quality'],
                'language' => 'en',
                'hasEnglish' => true,
                'preferAudio' => 'en',
                'qualities' => $qualities,
                'audioTracks' => [
                    ['id' => 'en', 'label' => 'English', 'name' => 'English', 'language' => 'en', 'lang' => 'en'],
                    ['id' => 'hi', 'label' => 'Hindi', 'name' => 'Hindi', 'language' => 'hi', 'lang' => 'hi'],
                ],
                'meta' => ['backend' => 'legacy-aggregator', 'title' => (string) ($payload['title'] ?? '')],
            ]],
            'diagnostics' => $diagnostics,
            'error' => null,
        ];
    }

    /**
     * @param list<array<string,mixed>> $diagnostics
     * @return list<array{quality:string,url:string,type:string}>
     */
    private static function movieLinks(string $docId, array &$diagnostics): array
    {
        $json = self::httpGetJson(self::SITE . '/api/movies/public/' . rawurlencode($docId));
        if (!is_array($json)) {
            $diagnostics[] = [
                'code' => 'HDGHAR_HTTP',
                'message' => 'HDGharTV movie detail failed',
                'severity' => 'warning',
                'provider' => self::PROVIDER_ID,
            ];
            return [];
        }
        return self::normalizeLinks($json['streamingLinks'] ?? []);
    }

    /**
     * @param list<array<string,mixed>> $diagnostics
     * @return list<array{quality:string,url:string,type:string}>
     */
    private static function seriesLinks(string $docId, int $season, int $episode, array &$diagnostics): array
    {
        $json = self::httpGetJson(self::SITE . '/api/series/public/' . rawurlencode($docId));
        if (!is_array($json)) {
            $diagnostics[] = [
                'code' => 'HDGHAR_HTTP',
                'message' => 'HDGharTV series detail failed',
                'severity' => 'warning',
                'provider' => self::PROVIDER_ID,
            ];
            return [];
        }
        foreach ($json['seasons'] ?? [] as $seasonRow) {
            if (!is_array($seasonRow)) {
                continue;
            }
            if ((int) ($seasonRow['seasonNumber'] ?? 0) !== $season) {
                continue;
            }
            foreach ($seasonRow['episodes'] ?? [] as $ep) {
                if (!is_array($ep)) {
                    continue;
                }
                if ((int) ($ep['episodeNumber'] ?? 0) !== $episode) {
                    continue;
                }
                return self::normalizeLinks($ep['streamingLinks'] ?? []);
            }
        }
        $diagnostics[] = [
            'code' => 'HDGHAR_EMPTY',
            'message' => "No S{$season}E{$episode} links on HDGharTV",
            'severity' => 'info',
            'provider' => self::PROVIDER_ID,
        ];
        return [];
    }

    /**
     * @param mixed $raw
     * @return list<array{quality:string,url:string,type:string}>
     */
    private static function normalizeLinks($raw): array
    {
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $src) {
            if (!is_array($src) || empty($src['url'])) {
                continue;
            }
            if (isset($src['isActive']) && !$src['isActive']) {
                continue;
            }
            $streamUrl = trim((string) $src['url']);
            if ($streamUrl === '' || !preg_match('#^https?://#i', $streamUrl)) {
                continue;
            }
            $quality = trim((string) ($src['quality'] ?? 'Auto')) ?: 'Auto';
            $streamType = strtolower((string) ($src['type'] ?? 'hls'));
            if ($streamType === 'm3u8') {
                $streamType = 'hls';
            }
            $out[] = [
                'quality' => $quality,
                'url' => $streamUrl,
                'type' => $streamType === 'hls' ? 'hls' : 'file',
            ];
        }
        return $out;
    }

    /**
     * @param list<array<string,mixed>> $diagnostics
     */
    private static function findDocId(string $type, int $tmdbId, ?string $title, array &$diagnostics): ?string
    {
        $queries = [];
        if ($title !== null && trim($title) !== '') {
            $queries[] = trim($title);
        }
        $queries[] = (string) $tmdbId;

        $bucket = $type === 'tv' ? 'series' : 'movies';
        foreach ($queries as $q) {
            $json = self::httpGetJson(self::SITE . '/api/search?q=' . rawurlencode($q));
            if (!is_array($json)) {
                continue;
            }
            foreach ($json[$bucket] ?? [] as $row) {
                if (!is_array($row)) {
                    continue;
                }
                if ((int) ($row['tmdbId'] ?? 0) === $tmdbId && !empty($row['_id'])) {
                    return (string) $row['_id'];
                }
            }
        }

        // Fallback: scan public list pages for exact tmdbId (slower, limited first page).
        $listPath = $type === 'tv' ? '/api/series/public' : '/api/movies/public';
        $json = self::httpGetJson(self::SITE . $listPath);
        if (is_array($json)) {
            foreach ($json['data'] ?? [] as $row) {
                if (!is_array($row)) {
                    continue;
                }
                if ((int) ($row['tmdbId'] ?? 0) === $tmdbId && !empty($row['_id'])) {
                    return (string) $row['_id'];
                }
            }
        }

        $diagnostics[] = [
            'code' => 'HDGHAR_SEARCH_MISS',
            'message' => 'HDGharTV search miss for TMDB ' . $tmdbId,
            'severity' => 'info',
            'provider' => self::PROVIDER_ID,
        ];
        return null;
    }

    private static function tmdbTitle(string $type, int $tmdbId): ?string
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
            if (!is_array($details)) {
                return null;
            }
            $title = trim((string) ($details['title'] ?? $details['name'] ?? ''));
            return $title !== '' ? $title : null;
        } catch (Throwable $e) {
            return null;
        }
    }

    public static function qualityRank(string $q): int
    {
        if (preg_match('/(2160|4k|uhd)/i', $q)) {
            return 2160;
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
        if (preg_match('/360/', $q)) {
            return 360;
        }
        return 0;
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
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'User-Agent: ' . self::UA,
                'Referer: ' . self::SITE . '/',
                'Origin: ' . self::SITE,
            ],
            CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if (!is_string($body) || $body === '' || $code < 200 || $code >= 400) {
            return null;
        }
        return $body;
    }
}
