<?php
declare(strict_types=1);

/**
 * Vuflix-only scraper: HDGHAR / Moonflix aggregator family.
 * Streams are multi-audio HLS; player must prefer English track (DEFAULT often Hindi-first).
 *
 * Override dead Railway default via env HDGHAR_API_BASE (no trailing slash).
 */
final class HdgharSources
{
    public const PROVIDER_ID = 'hdghar';
    public const PROVIDER_NAME = 'HDGHAR';
    public const BASE = 'https://mindful-wholeness-production-4ac0.up.railway.app';

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
        return self::BASE;
    }

    public static function hostAllowed(): bool
    {
        $host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
        $host = preg_replace('/:\d+$/', '', $host) ?: $host;
        $host = preg_replace('/^www\./', '', $host) ?: $host;
        return $host === 'vuflix.co' || $host === 'localhost' || $host === '127.0.0.1';
    }

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
        $base = self::apiBase();
        $url = $type === 'tv'
            ? sprintf('%s/tv/%d/%d/%d', $base, $tmdbId, max(1, $season), max(1, $episode))
            : sprintf('%s/movie/%d', $base, $tmdbId);

        $payload = self::httpGetJson($url);
        if (!is_array($payload)) {
            return [
                'ok' => false,
                'error' => 'HDGHAR fetch failed',
                'sources' => [],
                'diagnostics' => [[
                    'code' => 'HDGHAR_HTTP',
                    'message' => 'HDGHAR fetch failed',
                    'severity' => 'warning',
                    'provider' => self::PROVIDER_ID,
                ]],
            ];
        }
        // Aggregator returns 404 JSON for missing titles / TMDB mismatches.
        if (isset($payload['error']) || (empty($payload['streams']) && empty($payload['tmdbId']) && empty($payload['tmdb_id']))) {
            $msg = 'Not available on HDGHAR for this title';
            if (is_array($payload['error'] ?? null)) {
                $msg = trim((string) (($payload['error']['message'] ?? '') ?: $msg));
            } elseif (is_string($payload['error'] ?? null)) {
                $msg = trim((string) $payload['error']) ?: $msg;
            }
            return [
                'ok' => false,
                'error' => $msg,
                'sources' => [],
                'diagnostics' => [[
                    'code' => 'HDGHAR_EMPTY',
                    'message' => $msg,
                    'severity' => 'info',
                    'provider' => self::PROVIDER_ID,
                ]],
            ];
        }

        $qualities = [];
        foreach ($payload['streams'] ?? [] as $src) {
            if (!is_array($src) || empty($src['url'])) continue;
            $streamUrl = trim((string) $src['url']);
            if ($streamUrl === '' || !preg_match('#^https?://#i', $streamUrl)) continue;
            $quality = trim((string) ($src['quality'] ?? 'Auto')) ?: 'Auto';
            $streamType = strtolower((string) ($src['type'] ?? 'hls'));
            if ($streamType === 'm3u8') $streamType = 'hls';
            $qualities[] = [
                'quality' => $quality,
                'url' => $streamUrl,
                'type' => $streamType === 'hls' ? 'hls' : 'file',
            ];
        }
        if ($qualities === []) {
            return [
                'ok' => false,
                'error' => 'No HDGHAR streams',
                'sources' => [],
                'diagnostics' => [[
                    'code' => 'HDGHAR_EMPTY',
                    'message' => 'No HDGHAR streams',
                    'severity' => 'warning',
                    'provider' => self::PROVIDER_ID,
                ]],
            ];
        }

        usort($qualities, static fn($a, $b) => self::qualityRank($b['quality']) <=> self::qualityRank($a['quality']));

        // Rewrite masters so English is DEFAULT=YES (hls.js / Safari otherwise pick Hindi-first).
        if (class_exists('StreamLangProxy')) {
            foreach ($qualities as &$q) {
                if (($q['type'] ?? '') === 'hls' && !empty($q['url'])) {
                    $q['url'] = StreamLangProxy::mintMasterPrefer((string) $q['url'], 'eng');
                }
            }
            unset($q);
        }
        $best = $qualities[0];

        $audioTracks = [
            ['id' => 'en', 'label' => 'English', 'name' => 'English', 'language' => 'en', 'lang' => 'en'],
            ['id' => 'hi', 'label' => 'Hindi', 'name' => 'Hindi', 'language' => 'hi', 'lang' => 'hi'],
            ['id' => 'ta', 'label' => 'Tamil', 'name' => 'Tamil', 'language' => 'ta', 'lang' => 'ta'],
            ['id' => 'te', 'label' => 'Telugu', 'name' => 'Telugu', 'language' => 'te', 'lang' => 'te'],
        ];

        $source = [
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
            'audioTracks' => $audioTracks,
            'meta' => [
                'backend' => 'hdghar',
                'title' => (string) ($payload['title'] ?? ''),
                'preferAudio' => 'english',
            ],
        ];

        return [
            'ok' => true,
            'sources' => [$source],
            'diagnostics' => [],
            'meta' => [
                'backend' => self::apiBase(),
                'count' => 1,
                'cached' => !empty($payload['cached']),
            ],
        ];
    }

    private static function qualityRank(string $q): int
    {
        if (preg_match('/\b(2160|4k|uhd)\b/i', $q)) return 2160;
        if (preg_match('/\b1080\b/i', $q)) return 1080;
        if (preg_match('/\b720\b/i', $q)) return 720;
        if (preg_match('/\b480\b/i', $q)) return 480;
        if (preg_match('/\b360\b/i', $q)) return 360;
        return 0;
    }

    private static function httpGetJson(string $url): ?array
    {
        $raw = self::httpGetRaw($url);
        if ($raw === null || $raw === '') return null;
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
                    'User-Agent: VuflixHdghar/1.1',
                ],
            ]);
            $body = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if (!is_string($body) || $body === '' || $code < 200 || $code >= 500) return null;
            return $body;
        }
        $ctx = stream_context_create([
            'http' => [
                'timeout' => 45,
                'header' => "Accept: application/json\r\nUser-Agent: VuflixHdghar/1.1\r\n",
                'ignore_errors' => true,
            ],
        ]);
        $body = @file_get_contents($url, false, $ctx);
        return is_string($body) ? $body : null;
    }
}
