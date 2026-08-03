<?php
declare(strict_types=1);

/**
 * Vuflix learning provider: Torrentio → RealDebrid → playable HTTP URLs.
 *
 * Flow:
 *  1) TMDB id → IMDb id
 *  2) Torrentio Stremio API with RD key returns stream URLs (cached = instant)
 *  3) Normalize into PlayerSources shape for the native player
 *
 * Does NOT download torrents on this VPS — RealDebrid does that on their side.
 */
final class RealDebridSources
{
    public const PROVIDER_ID = 'realdebrid';
    public const PROVIDER_NAME = 'RealDebrid';

    private const TORRENTIO = 'https://torrentio.strem.fun';
    private const RD_API = 'https://api.real-debrid.com/rest/1.0';
    private const MAX_STREAMS = 8;

    public static function hostAllowed(): bool
    {
        $host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
        $host = preg_replace('/:\d+$/', '', $host) ?: $host;
        $host = preg_replace('/^www\./', '', $host) ?: $host;
        return $host === 'vuflix.co';
    }

    public static function settingsPath(): string
    {
        $dir = dirname(__DIR__, 2) . '/storage';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        return $dir . '/realdebrid.json';
    }

    /** @return array{apiKey:string,updatedAt:int} */
    public static function settings(): array
    {
        $path = self::settingsPath();
        if (!is_file($path)) {
            return ['apiKey' => '', 'updatedAt' => 0];
        }
        $raw = @file_get_contents($path);
        $data = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($data)) {
            return ['apiKey' => '', 'updatedAt' => 0];
        }
        return [
            'apiKey' => trim((string) ($data['apiKey'] ?? '')),
            'updatedAt' => (int) ($data['updatedAt'] ?? 0),
        ];
    }

    public static function saveApiKey(string $apiKey): array
    {
        $apiKey = trim($apiKey);
        $payload = [
            'apiKey' => $apiKey,
            'updatedAt' => time(),
        ];
        $path = self::settingsPath();
        $tmp = $path . '.tmp';
        if (@file_put_contents($tmp, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX) === false) {
            return ['ok' => false, 'error' => 'Could not write settings'];
        }
        @rename($tmp, $path);
        @chmod($path, 0600);
        return ['ok' => true, 'configured' => $apiKey !== ''];
    }

    public static function maskedKey(): string
    {
        $key = self::settings()['apiKey'];
        if ($key === '') {
            return '';
        }
        $len = strlen($key);
        if ($len <= 8) {
            return str_repeat('•', $len);
        }
        return substr($key, 0, 4) . str_repeat('•', max(4, $len - 8)) . substr($key, -4);
    }

    public static function apiKeyConfigured(): bool
    {
        return self::settings()['apiKey'] !== '';
    }

    /**
     * @return array{ok:bool,sources:list<array<string,mixed>>,error?:string,diagnostics?:list<array<string,mixed>>,meta?:array<string,mixed>}
     */
    public static function fetch(string $type, int $tmdbId, int $season = 1, int $episode = 1): array
    {
        if (!self::hostAllowed()) {
            return ['ok' => false, 'sources' => [], 'error' => 'RealDebrid is Vuflix-only'];
        }
        $type = $type === 'tv' ? 'tv' : 'movie';
        if ($tmdbId < 1) {
            return ['ok' => false, 'sources' => [], 'error' => 'Invalid tmdbId'];
        }

        $apiKey = self::settings()['apiKey'];
        if ($apiKey === '') {
            return [
                'ok' => false,
                'sources' => [],
                'error' => 'Add a RealDebrid API key in Admin → Sources',
                'diagnostics' => [[
                    'code' => 'RD_KEY_MISSING',
                    'message' => 'RealDebrid API key not configured',
                    'severity' => 'warning',
                    'provider' => self::PROVIDER_ID,
                ]],
            ];
        }

        $imdb = self::imdbIdFor($type, $tmdbId);
        if ($imdb === null) {
            return ['ok' => false, 'sources' => [], 'error' => 'No IMDb id for this title'];
        }

        $torrentioId = $type === 'tv'
            ? ($imdb . ':' . max(1, $season) . ':' . max(1, $episode))
            : $imdb;
        $kind = $type === 'tv' ? 'series' : 'movie';
        $url = self::TORRENTIO . '/realdebrid=' . rawurlencode($apiKey)
            . '/stream/' . $kind . '/' . rawurlencode($torrentioId) . '.json';

        $payload = self::httpGetJson($url);
        if (!is_array($payload)) {
            return ['ok' => false, 'sources' => [], 'error' => 'Torrentio request failed'];
        }

        $streams = is_array($payload['streams'] ?? null) ? $payload['streams'] : [];
        if (!$streams) {
            return ['ok' => false, 'sources' => [], 'error' => 'No RealDebrid streams from Torrentio'];
        }

        // Prefer cached RD+ (instant). Skip known failure placeholders.
        usort($streams, static function ($a, $b) {
            $sa = self::streamScore(is_array($a) ? $a : []);
            $sb = self::streamScore(is_array($b) ? $b : []);
            return $sb <=> $sa;
        });

        $sources = [];
        $diagnostics = [];
        foreach ($streams as $row) {
            if (!is_array($row)) {
                continue;
            }
            $name = (string) ($row['name'] ?? '');
            $title = (string) ($row['title'] ?? '');
            $streamUrl = trim((string) ($row['url'] ?? ''));
            if ($streamUrl === '' || str_contains($streamUrl, 'failed_access') || str_contains($streamUrl, '/videos/failed')) {
                if (stripos($name . $title, 'invalid') !== false || stripos($title, 'apikey') !== false) {
                    return [
                        'ok' => false,
                        'sources' => [],
                        'error' => 'Invalid RealDebrid API key',
                        'diagnostics' => [[
                            'code' => 'RD_KEY_INVALID',
                            'message' => $title !== '' ? $title : 'Invalid RealDebrid API key',
                            'severity' => 'error',
                            'provider' => self::PROVIDER_ID,
                        ]],
                    ];
                }
                continue;
            }
            if (!preg_match('#^https?://#i', $streamUrl)) {
                continue;
            }

            $cached = self::isCachedLabel($name . "\n" . $title);
            $quality = self::guessQuality($name . ' ' . $title);
            $labelBits = ['RealDebrid'];
            if ($cached) {
                $labelBits[] = 'Instant';
            }
            if ($quality !== '') {
                $labelBits[] = $quality;
            }

            $sources[] = [
                'url' => $streamUrl,
                'type' => self::guessType($streamUrl, $title),
                'quality' => $quality !== '' ? $quality : 'Auto',
                'provider' => self::PROVIDER_ID,
                'providerName' => self::PROVIDER_NAME,
                'label' => implode(' · ', $labelBits),
                'language' => '',
                'rdCached' => $cached,
                'rdTitle' => self::shortTitle($title),
            ];
            if (count($sources) >= self::MAX_STREAMS) {
                break;
            }
        }

        if (!$sources) {
            return [
                'ok' => false,
                'sources' => [],
                'error' => 'No playable RealDebrid links (may need cache / different title)',
                'diagnostics' => $diagnostics,
                'meta' => ['imdbId' => $imdb],
            ];
        }

        return [
            'ok' => true,
            'sources' => $sources,
            'meta' => [
                'imdbId' => $imdb,
                'via' => 'torrentio+realdebrid',
                'instantCount' => count(array_filter($sources, static fn ($s) => !empty($s['rdCached']))),
            ],
            'diagnostics' => $diagnostics,
        ];
    }

    /** Validate key against RD /user (for admin Save). */
    public static function validateApiKey(string $apiKey): array
    {
        $apiKey = trim($apiKey);
        if ($apiKey === '') {
            return ['ok' => false, 'error' => 'Empty API key'];
        }
        $ch = curl_init(self::RD_API . '/user');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 12,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $apiKey,
                'Accept: application/json',
            ],
        ]);
        $raw = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if (!is_string($raw) || $code >= 400) {
            return ['ok' => false, 'error' => 'RealDebrid rejected this API key'];
        }
        $data = json_decode($raw, true);
        if (!is_array($data) || empty($data['id'])) {
            return ['ok' => false, 'error' => 'Unexpected RealDebrid response'];
        }
        return [
            'ok' => true,
            'username' => (string) ($data['username'] ?? ''),
            'premium' => (int) ($data['premium'] ?? 0),
            'expiration' => (string) ($data['expiration'] ?? ''),
        ];
    }

    private static function imdbIdFor(string $type, int $tmdbId): ?string
    {
        try {
            $tmdb = new Tmdb();
            $details = $tmdb->details($type, $tmdbId);
            $imdb = (string) ($details['external_ids']['imdb_id'] ?? '');
            if ($imdb !== '' && str_starts_with($imdb, 'tt')) {
                return $imdb;
            }
        } catch (Throwable $e) {
            // fall through
        }
        return null;
    }

    /** @param array<string,mixed> $row */
    private static function streamScore(array $row): int
    {
        $blob = strtolower((string) ($row['name'] ?? '') . "\n" . (string) ($row['title'] ?? ''));
        $score = 0;
        if (str_contains($blob, '[rd+]') || str_contains($blob, 'rd+')) {
            $score += 1000;
        }
        if (str_contains($blob, 'download')) {
            $score -= 200;
        }
        if (str_contains($blob, '2160') || str_contains($blob, '4k')) {
            $score += 40;
        } elseif (str_contains($blob, '1080')) {
            $score += 30;
        } elseif (str_contains($blob, '720')) {
            $score += 20;
        }
        if (str_contains($blob, 'hdr') || str_contains($blob, 'dv')) {
            $score += 5;
        }
        return $score;
    }

    private static function isCachedLabel(string $blob): bool
    {
        $b = strtolower($blob);
        return str_contains($b, '[rd+]') || str_contains($b, 'rd+');
    }

    private static function guessQuality(string $blob): string
    {
        if (preg_match('/\b(2160p|4k)\b/i', $blob)) {
            return '4K';
        }
        if (preg_match('/\b1080p\b/i', $blob)) {
            return '1080p';
        }
        if (preg_match('/\b720p\b/i', $blob)) {
            return '720p';
        }
        if (preg_match('/\b480p\b/i', $blob)) {
            return '480p';
        }
        return '';
    }

    private static function guessType(string $url, string $title): string
    {
        $u = strtolower($url . ' ' . $title);
        if (str_contains($u, '.m3u8') || str_contains($u, 'hls')) {
            return 'hls';
        }
        return 'file';
    }

    private static function shortTitle(string $title): string
    {
        $line = trim(explode("\n", $title)[0] ?? '');
        if (strlen($line) > 90) {
            return substr($line, 0, 87) . '…';
        }
        return $line;
    }

    /** @return array<string,mixed>|null */
    private static function httpGetJson(string $url): ?array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 25,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'User-Agent: VuflixRealDebrid/1.0',
            ],
        ]);
        $raw = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if (!is_string($raw) || $raw === '' || $code >= 400) {
            return null;
        }
        $data = json_decode($raw, true);
        return is_array($data) ? $data : null;
    }
}
