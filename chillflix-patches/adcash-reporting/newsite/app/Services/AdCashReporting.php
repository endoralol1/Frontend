<?php
declare(strict_types=1);

/**
 * AdCash Publisher Reporting API v2 (earnings, balance, zone breakdown).
 * Long-lived api_token is stored server-side; short-lived Bearer tokens are cached ~14m.
 */
final class AdCashReporting
{
    private const FILE = 'adcash-reporting.json';
    private const ACCESS_CACHE = 'adcash-access-token.json';
    private const BASE = 'https://adcash.myadcash.com/api/v2';

    /** @return array{apiToken:string,updated:int} */
    public static function defaults(): array
    {
        return [
            'apiToken' => '',
            'updated' => 0,
        ];
    }

    /** @return array{apiToken:string,updated:int} */
    public static function getSettings(): array
    {
        $base = self::defaults();
        $path = self::path(self::FILE);
        if (is_file($path)) {
            $raw = @file_get_contents($path);
            $data = $raw !== false ? json_decode($raw, true) : null;
            if (is_array($data)) {
                $base['apiToken'] = trim((string) ($data['apiToken'] ?? ''));
                $base['updated'] = (int) ($data['updated'] ?? 0);
            }
        }
        if ($base['apiToken'] === '') {
            $fromEnv = trim((string) (getenv('ADCASH_REPORTING_API_TOKEN') ?: ''));
            if ($fromEnv === '' && function_exists('config')) {
                $fromEnv = trim((string) config('adcash_reporting_api_token', ''));
            }
            if ($fromEnv !== '') {
                $base['apiToken'] = $fromEnv;
            }
        }
        return $base;
    }

    public static function saveApiToken(string $token): array
    {
        $token = trim($token);
        $next = [
            'apiToken' => $token,
            'updated' => time(),
        ];
        self::writeJson(self::path(self::FILE), $next);
        self::clearAccessCache();
        return $next;
    }

    public static function hasToken(): bool
    {
        return self::getSettings()['apiToken'] !== '';
    }

    /** Masked token for admin UI (never send full secret to the browser after save). */
    public static function maskedToken(): string
    {
        $t = self::getSettings()['apiToken'];
        if ($t === '') {
            return '';
        }
        $len = strlen($t);
        if ($len <= 12) {
            return str_repeat('•', max(4, $len - 2)) . substr($t, -2);
        }
        return substr($t, 0, 6) . str_repeat('•', 18) . substr($t, -6);
    }

    /**
     * Dashboard payload: balance + date rows + zone rows + period totals.
     *
     * @return array<string,mixed>
     */
    public static function dashboard(?string $startDate = null, ?string $endDate = null): array
    {
        $end = self::normalizeDate($endDate) ?? gmdate('Y-m-d');
        $start = self::normalizeDate($startDate) ?? gmdate('Y-m-d', strtotime($end . ' UTC') - 13 * 86400);
        if ($start > $end) {
            [$start, $end] = [$end, $start];
        }
        // API: start_date cannot be more than 120 days before today
        $minStart = gmdate('Y-m-d', time() - 119 * 86400);
        if ($start < $minStart) {
            $start = $minStart;
        }

        if (!self::hasToken()) {
            return [
                'ok' => false,
                'error' => 'Reporting API token not configured',
                'configured' => false,
                'start_date' => $start,
                'end_date' => $end,
            ];
        }

        try {
            $balance = self::balance();
            $byDate = self::report($start, $end, 'date', '-date');
            $byZone = self::report($start, $end, 'zone', '-earnings');
        } catch (Throwable $e) {
            return [
                'ok' => false,
                'configured' => true,
                'error' => $e->getMessage(),
                'start_date' => $start,
                'end_date' => $end,
            ];
        }

        $dateRows = is_array($byDate['rows'] ?? null) ? $byDate['rows'] : [];
        $zoneRows = is_array($byZone['rows'] ?? null) ? $byZone['rows'] : [];
        $currency = (string) ($byDate['currency'] ?? $balance['currency'] ?? 'EUR');
        $timezone = (string) ($byDate['timezone'] ?? 'Europe/Paris');

        $totals = [
            'earnings' => 0.0,
            'clicks' => 0,
            'unique_users' => 0,
        ];
        foreach ($dateRows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $totals['earnings'] += (float) ($row['earnings'] ?? 0);
            $totals['clicks'] += (int) ($row['clicks'] ?? 0);
            $totals['unique_users'] += (int) ($row['unique_users'] ?? 0);
        }

        // AdCash returns parent zones + child/sub-zones. Roll children into parents
        // and keep only the site's configured units (Banner / Pop / …).
        $zonesOut = self::rollupZones($zoneRows);

        $daysOut = [];
        foreach ($dateRows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $daysOut[] = [
                'date' => (string) ($row['date'] ?? ''),
                'earnings' => (float) ($row['earnings'] ?? 0),
                'clicks' => (int) ($row['clicks'] ?? 0),
                'unique_users' => (int) ($row['unique_users'] ?? 0),
                'unique_users_ecpm' => (float) ($row['unique_users_ecpm'] ?? 0),
            ];
        }

        return [
            'ok' => true,
            'configured' => true,
            'start_date' => $start,
            'end_date' => $end,
            'currency' => $currency,
            'timezone' => $timezone,
            'balance' => [
                'amount' => (float) ($balance['balance'] ?? 0),
                'currency' => (string) ($balance['currency'] ?? $currency),
            ],
            'totals' => [
                'earnings' => round($totals['earnings'], 2),
                'clicks' => $totals['clicks'],
                'unique_users' => $totals['unique_users'],
            ],
            'by_date' => $daysOut,
            'by_zone' => $zonesOut,
            'token_masked' => self::maskedToken(),
            'fetched_at' => gmdate('c'),
        ];
    }

    /** @return array{balance:float|int,currency:string} */
    public static function balance(): array
    {
        $json = self::authedGet('/publishers/balance');
        $data = is_array($json['data'] ?? null) ? $json['data'] : [];
        return [
            'balance' => $data['balance'] ?? 0,
            'currency' => (string) ($data['currency'] ?? 'EUR'),
        ];
    }

    /**
     * @return array{rows:list<array<string,mixed>>,currency:string,timezone:string}
     */
    public static function report(string $start, string $end, string $groupBy, string $sortBy = '-earnings'): array
    {
        $qs = http_build_query([
            'start_date' => $start,
            'end_date' => $end,
            'group_by' => $groupBy,
            'sort_by' => $sortBy,
        ]);
        $json = self::authedGet('/publishers/reports?' . $qs);
        $data = is_array($json['data'] ?? null) ? $json['data'] : [];
        $meta = is_array($json['meta'] ?? null) ? $json['meta'] : [];
        $rows = is_array($data['rows'] ?? null) ? $data['rows'] : [];
        return [
            'rows' => $rows,
            'currency' => (string) ($meta['currency'] ?? 'EUR'),
            'timezone' => (string) ($meta['timezone'] ?? 'Europe/Paris'),
        ];
    }

    /** @return array<string,string> */
    public static function zoneLabels(): array
    {
        $labels = [];
        if (class_exists('SiteAds')) {
            $ads = SiteAds::get();
            $map = [
                'bannerZone' => 'Banner',
                'interstitialZone' => 'Interstitial',
                'videoSliderZone' => 'Video slider',
                'popZone' => 'Pop',
            ];
            foreach ($map as $key => $name) {
                $zid = trim((string) ($ads[$key] ?? ''));
                if ($zid !== '') {
                    $labels[$zid] = $name;
                }
            }
        }
        return $labels;
    }

    /**
     * Collapse AdCash child/sub-zones into configured parent units.
     *
     * @param list<array<string,mixed>> $zoneRows
     * @return list<array<string,mixed>>
     */
    public static function rollupZones(array $zoneRows): array
    {
        $labels = self::zoneLabels();
        $known = array_fill_keys(array_keys($labels), true);
        /** @var array<string,array{zone:string,label:string,earnings:float,clicks:int,unique_users:int,children:int}> $buckets */
        $buckets = [];

        // Seed configured units so empty units still appear.
        foreach ($labels as $zid => $label) {
            $buckets[$zid] = [
                'zone' => $zid,
                'label' => $label,
                'earnings' => 0.0,
                'clicks' => 0,
                'unique_users' => 0,
                'children' => 0,
            ];
        }

        foreach ($zoneRows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $zid = trim((string) ($row['zone'] ?? ''));
            if ($zid === '') {
                continue;
            }
            $parent = trim((string) ($row['parent_zone'] ?? ''));
            $bucketId = null;
            if (isset($known[$zid])) {
                $bucketId = $zid;
            } elseif ($parent !== '' && isset($known[$parent])) {
                $bucketId = $parent;
            } elseif ($parent !== '') {
                // Unknown parent chain — still group under parent id.
                $bucketId = $parent;
            } else {
                $bucketId = $zid;
            }

            if (!isset($buckets[$bucketId])) {
                $buckets[$bucketId] = [
                    'zone' => $bucketId,
                    'label' => $labels[$bucketId] ?? ('Zone ' . $bucketId),
                    'earnings' => 0.0,
                    'clicks' => 0,
                    'unique_users' => 0,
                    'children' => 0,
                ];
            }
            $buckets[$bucketId]['earnings'] += (float) ($row['earnings'] ?? 0);
            $buckets[$bucketId]['clicks'] += (int) ($row['clicks'] ?? 0);
            $buckets[$bucketId]['unique_users'] += (int) ($row['unique_users'] ?? 0);
            if ($zid !== $bucketId) {
                $buckets[$bucketId]['children']++;
            }
        }

        // Prefer only configured units in the admin table.
        if ($known !== []) {
            $buckets = array_filter(
                $buckets,
                static fn(array $b): bool => isset($known[$b['zone']])
            );
        }

        $out = [];
        foreach ($buckets as $b) {
            $users = (int) $b['unique_users'];
            $earn = round((float) $b['earnings'], 2);
            $out[] = [
                'zone' => $b['zone'],
                'label' => $b['label'],
                'parent_zone' => null,
                'earnings' => $earn,
                'clicks' => (int) $b['clicks'],
                'unique_users' => $users,
                'unique_users_ecpm' => $users > 0 ? round(($earn / $users) * 1000, 2) : 0.0,
                'sub_zones' => (int) $b['children'],
            ];
        }

        usort($out, static function (array $a, array $b): int {
            return ($b['earnings'] <=> $a['earnings']) ?: strcmp($a['label'], $b['label']);
        });
        return array_values($out);
    }

    private static function normalizeDate(?string $d): ?string
    {
        $d = trim((string) $d);
        if ($d === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) {
            return null;
        }
        return $d;
    }

    /** @return array<string,mixed> */
    private static function authedGet(string $pathAndQuery): array
    {
        $token = self::accessToken();
        $url = self::BASE . $pathAndQuery;
        $res = self::httpJson('GET', $url, null, [
            'Authorization: Bearer ' . $token,
            'Accept: application/json',
        ]);
        if (($res['status'] ?? 0) === 401) {
            self::clearAccessCache();
            $token = self::accessToken(true);
            $res = self::httpJson('GET', $url, null, [
                'Authorization: Bearer ' . $token,
                'Accept: application/json',
            ]);
        }
        if (($res['status'] ?? 0) < 200 || ($res['status'] ?? 0) >= 300) {
            $msg = 'AdCash API error';
            if (is_array($res['json'] ?? null)) {
                $msg = (string) (($res['json']['error']['message'] ?? null)
                    ?: ($res['json']['message'] ?? null)
                    ?: $msg);
            }
            throw new RuntimeException($msg . ' (HTTP ' . (int) ($res['status'] ?? 0) . ')');
        }
        return is_array($res['json'] ?? null) ? $res['json'] : [];
    }

    private static function accessToken(bool $force = false): string
    {
        if (!$force) {
            $cached = self::readAccessCache();
            if ($cached !== null) {
                return $cached;
            }
        }
        $apiToken = self::getSettings()['apiToken'];
        if ($apiToken === '') {
            throw new RuntimeException('Reporting API token not configured');
        }
        $res = self::httpJson('POST', self::BASE . '/auth/token', [
            'api_token' => $apiToken,
        ], [
            'Accept: application/json',
            'Content-Type: application/json',
        ]);
        if (($res['status'] ?? 0) < 200 || ($res['status'] ?? 0) >= 300) {
            $msg = 'AdCash auth failed';
            if (is_array($res['json'] ?? null)) {
                $msg = (string) (($res['json']['error']['message'] ?? null)
                    ?: ($res['json']['message'] ?? null)
                    ?: $msg);
            }
            throw new RuntimeException($msg . ' (HTTP ' . (int) ($res['status'] ?? 0) . ')');
        }
        $data = is_array($res['json']['data'] ?? null) ? $res['json']['data'] : [];
        $access = trim((string) ($data['access_token'] ?? ''));
        $expiresIn = (int) ($data['expires_in'] ?? 900);
        if ($access === '') {
            throw new RuntimeException('AdCash auth returned empty access token');
        }
        // Refresh a minute early
        $ttl = max(60, $expiresIn - 60);
        self::writeAccessCache($access, time() + $ttl);
        return $access;
    }

    private static function readAccessCache(): ?string
    {
        $path = self::path(self::ACCESS_CACHE);
        if (!is_file($path)) {
            return null;
        }
        $raw = @file_get_contents($path);
        $data = $raw !== false ? json_decode($raw, true) : null;
        if (!is_array($data)) {
            return null;
        }
        $token = trim((string) ($data['access_token'] ?? ''));
        $exp = (int) ($data['expires_at'] ?? 0);
        if ($token === '' || $exp <= time()) {
            return null;
        }
        return $token;
    }

    private static function writeAccessCache(string $token, int $expiresAt): void
    {
        self::writeJson(self::path(self::ACCESS_CACHE), [
            'access_token' => $token,
            'expires_at' => $expiresAt,
        ]);
    }

    private static function clearAccessCache(): void
    {
        $path = self::path(self::ACCESS_CACHE);
        if (is_file($path)) {
            @unlink($path);
        }
    }

    /**
     * @param array<string,mixed>|null $body
     * @param list<string> $headers
     * @return array{status:int,json:?array,raw:string}
     */
    private static function httpJson(string $method, string $url, ?array $body, array $headers): array
    {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('curl_init failed');
        }
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 12,
            CURLOPT_TIMEOUT => 25,
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_USERAGENT => 'VuflixAdCashReporting/1.0',
        ];
        if ($body !== null) {
            $opts[CURLOPT_POSTFIELDS] = json_encode($body, JSON_UNESCAPED_SLASHES);
        }
        curl_setopt_array($ch, $opts);
        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($raw === false) {
            throw new RuntimeException('AdCash request failed: ' . ($err !== '' ? $err : 'network error'));
        }
        $json = json_decode((string) $raw, true);
        return [
            'status' => $status,
            'json' => is_array($json) ? $json : null,
            'raw' => (string) $raw,
        ];
    }

    private static function path(string $file): string
    {
        $dir = (string) (function_exists('config') ? config('cache_dir', __DIR__ . '/../../storage/cache') : (__DIR__ . '/../../storage/cache'));
        return rtrim($dir, '/') . '/' . $file;
    }

    /** @param array<string,mixed> $data */
    private static function writeJson(string $path, array $data): void
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        if ($json === false) {
            return;
        }
        $tmp = $path . '.tmp';
        if (@file_put_contents($tmp, $json, LOCK_EX) !== false) {
            @rename($tmp, $path);
            // Readable/writable by the web user (php-fpm), not root-only.
            @chmod($path, 0660);
            $webUser = 'www-data';
            if (function_exists('posix_getpwnam')) {
                $pw = @posix_getpwnam($webUser);
                if (is_array($pw) && isset($pw['uid'], $pw['gid'])) {
                    @chown($path, (int) $pw['uid']);
                    @chgrp($path, (int) $pw['gid']);
                }
            }
        }
    }
}
