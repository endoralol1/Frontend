<?php
declare(strict_types=1);

/**
 * Huhu.to Live TV (MediaURL IPTV catalog) — file-cached, lean.
 *
 * Catalog: POST https://huhu.to/mediaurl-catalog.json  { catalogId: "iptv" }
 * Resolve: POST https://huhu.to/mediaurl-resolve.json  { url: playPage }
 * Playback: reuse main-site HLS proxy /api/iptv/live/{id}/index.m3u8
 */
final class HuhuLiveTv
{
    private const ORIGIN = 'https://huhu.to';
    private const CATALOG = 'https://huhu.to/mediaurl-catalog.json';
    private const RESOLVE = 'https://huhu.to/mediaurl-resolve.json';
    private const PAGE_SIZE = 300;
    /** Huhu serves ~10k+ IPTV rows; keep paging until nextCursor ends (safety cap). */
    private const MAX_PAGES = 50;
    private const CACHE_TTL = 600; // 10 minutes
    private const CACHE_FILE = 'huhu-live-tv.json';

    /** @return array{ok:bool,updated:int,categories:list<array>,channels:list<array>,error?:string} */
    public static function catalog(?string $search = null, ?string $category = null): array
    {
        try {
            $pack = self::cachedCatalog();
        } catch (Throwable $e) {
            return ['ok' => false, 'updated' => 0, 'categories' => [], 'channels' => [], 'error' => $e->getMessage()];
        }

        $channels = $pack['channels'];
        $q = strtolower(trim((string) $search));
        $cat = self::slug((string) $category);

        if ($q !== '' || $cat !== '') {
            $channels = array_values(array_filter($channels, static function (array $ch) use ($q, $cat): bool {
                if ($cat !== '' && ($ch['cat'] ?? '') !== $cat) {
                    return false;
                }
                if ($q === '') {
                    return true;
                }
                return str_contains(strtolower((string) ($ch['name'] ?? '')), $q)
                    || str_contains(strtolower((string) ($ch['group'] ?? '')), $q);
            }));
        }

        return [
            'ok' => true,
            'updated' => (int) ($pack['updated'] ?? 0),
            'categories' => $pack['categories'],
            'channels' => $channels,
            'total' => count($channels),
            'totalAll' => count($pack['channels']),
        ];
    }

    /** @return array{ok:bool,id?:string,playUrl?:string,name?:string,error?:string} */
    public static function play(string $id): array
    {
        $id = preg_replace('/[^a-zA-Z0-9_-]/', '', $id) ?? '';
        if ($id === '') {
            return ['ok' => false, 'error' => 'Missing channel id'];
        }

        $main = rtrim((string) config('player_main_api', config('main_origin', 'https://www.chillflix.lol')), '/');
        $name = '';
        try {
            $pack = self::cachedCatalog();
            foreach ($pack['channels'] as $ch) {
                if (($ch['id'] ?? '') === $id) {
                    $name = (string) ($ch['name'] ?? '');
                    break;
                }
            }
        } catch (Throwable) {
            // still allow play URL
        }

        return [
            'ok' => true,
            'id' => $id,
            'name' => $name,
            // Same-origin HLS proxy already on main Chillflix app
            'playUrl' => $main . '/api/iptv/live/' . rawurlencode($id) . '/index.m3u8',
        ];
    }

    /** Warm / refresh cache (optional cron). */
    public static function refresh(): array
    {
        $pack = self::fetchAll();
        self::writeCache($pack);
        return ['ok' => true, 'updated' => $pack['updated'], 'total' => count($pack['channels'])];
    }

    private static bool $refreshScheduled = false;

    /** @return array{updated:int,categories:list<array>,channels:list<array>} */
    private static function cachedCatalog(): array
    {
        $path = self::cachePath();
        $stale = null;
        if (is_file($path)) {
            $raw = @file_get_contents($path);
            $data = $raw !== false ? json_decode($raw, true) : null;
            if (is_array($data) && !empty($data['channels'])) {
                $mtime = (int) @filemtime($path);
                $fresh = $mtime > 0 && (time() - $mtime) < self::CACHE_TTL;
                if ($fresh) {
                    return $data;
                }
                // Serve expired cache immediately — refresh after response.
                $stale = $data;
            }
        }

        if ($stale !== null) {
            self::scheduleRefresh();
            return $stale;
        }

        // Cold start only: no local catalog yet.
        $pack = self::fetchAll();
        self::writeCache($pack);
        return $pack;
    }

    /** Refresh huhu catalog after the HTTP response is sent (non-blocking for clients). */
    private static function scheduleRefresh(): void
    {
        if (self::$refreshScheduled) {
            return;
        }
        self::$refreshScheduled = true;
        register_shutdown_function(static function (): void {
            if (function_exists('fastcgi_finish_request')) {
                @fastcgi_finish_request();
            }
            try {
                ignore_user_abort(true);
                @set_time_limit(120);
                $pack = self::fetchAll();
                self::writeCache($pack);
            } catch (Throwable) {
                // keep serving last good cache
            }
        });
    }

    /** @return array{updated:int,categories:list<array>,channels:list<array>} */
    private static function fetchAll(): array
    {
        $seen = [];
        $channels = [];
        $groups = [];
        $cursor = null;

        for ($page = 0; $page < self::MAX_PAGES; $page++) {
            $payload = self::post(self::CATALOG, [
                'catalogId' => 'iptv',
                'id' => '',
                'adult' => false,
                'search' => '',
                'sort' => 'trending-region',
                'filter' => new stdClass(),
                'cursor' => $cursor,
            ]);

            if (!$groups) {
                foreach (($payload['features']['filter'] ?? []) as $filter) {
                    if (($filter['id'] ?? '') === 'group' && !empty($filter['values'])) {
                        $groups = array_values(array_filter(array_map('strval', $filter['values'])));
                        break;
                    }
                }
            }

            $items = $payload['items'] ?? [];
            if (!is_array($items) || !$items) {
                break;
            }

            foreach ($items as $item) {
                $row = self::mapItem($item);
                if (!$row) {
                    continue;
                }
                $key = $row['id'];
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $channels[] = $row;
            }

            $next = $payload['nextCursor'] ?? null;
            if ($next === null || (count($items) < self::PAGE_SIZE && $page > 0)) {
                break;
            }
            $cursor = $next;
        }

        if (!$channels) {
            throw new RuntimeException('Huhu Live TV catalog empty');
        }

        if (!$groups) {
            $groups = array_values(array_unique(array_map(static fn ($c) => (string) ($c['group'] ?? 'Other'), $channels)));
            sort($groups);
        }

        $categories = [];
        foreach ($groups as $g) {
            $slug = self::slug($g);
            $categories[] = [
                'id' => $slug,
                'name' => $g,
                'count' => count(array_filter($channels, static fn ($c) => ($c['cat'] ?? '') === $slug)),
            ];
        }
        usort($categories, static fn ($a, $b) => ($b['count'] <=> $a['count']) ?: strcmp($a['name'], $b['name']));

        return [
            'updated' => time(),
            'categories' => $categories,
            'channels' => $channels,
        ];
    }

    /** @param mixed $item */
    private static function mapItem($item): ?array
    {
        if (!is_array($item)) {
            return null;
        }
        $id = isset($item['ids']['id']) ? (string) $item['ids']['id'] : '';
        if ($id === '') {
            return null;
        }
        $group = trim((string) ($item['group'] ?? 'Other')) ?: 'Other';
        $name = trim((string) ($item['name'] ?? $id)) ?: $id;
        $epg = '';
        if (!empty($item['epg'][0]['name'])) {
            $epg = trim((string) $item['epg'][0]['name']);
        }

        return [
            'id' => $id,
            'name' => $name,
            'group' => $group,
            'cat' => self::slug($group),
            'logo' => trim((string) ($item['logo'] ?? '')),
            'epg' => $epg,
            'playPage' => (string) ($item['url'] ?? (self::ORIGIN . '/huhu-iptv/play/' . rawurlencode($id))),
        ];
    }

    private static function post(string $url, array $body): array
    {
        $payload = json_encode(array_merge([
            'language' => 'en',
            'region' => 'US',
        ], $body), JSON_UNESCAPED_SLASHES);
        if ($payload === false) {
            throw new RuntimeException('Failed to encode request');
        }

        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('curl init failed');
        }
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT => 25,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json; charset=utf-8',
                'Accept: */*',
                'Origin: ' . self::ORIGIN,
                'Referer: ' . self::ORIGIN . '/',
                'User-Agent: Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
            ],
        ]);
        $raw = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($raw === false || $code < 200 || $code >= 300) {
            throw new RuntimeException('Huhu request failed' . ($err ? ": {$err}" : ": HTTP {$code}"));
        }
        if (str_starts_with(ltrim($raw), '<')) {
            throw new RuntimeException('Huhu returned HTML instead of JSON');
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            throw new RuntimeException('Huhu returned invalid JSON');
        }
        if (!empty($data['error'])) {
            throw new RuntimeException((string) $data['error']);
        }
        return $data;
    }

    private static function cachePath(): string
    {
        $dir = (string) config('cache_dir', __DIR__ . '/../../storage/cache');
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        return rtrim($dir, '/') . '/' . self::CACHE_FILE;
    }

    /** @param array{updated:int,categories:list<array>,channels:list<array>} $pack */
    private static function writeCache(array $pack): void
    {
        $path = self::cachePath();
        $json = json_encode($pack, JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return;
        }
        $tmp = $path . '.tmp';
        if (@file_put_contents($tmp, $json, LOCK_EX) !== false) {
            @rename($tmp, $path);
        }

        // Lean public feed for fast browser cache (no playPage/logo bloat)
        $publicDir = __DIR__ . '/../../public/assets/data';
        if (!is_dir($publicDir)) {
            @mkdir($publicDir, 0775, true);
        }
        $lean = [
            'ok' => true,
            'updated' => $pack['updated'],
            'categories' => $pack['categories'],
            'channels' => array_map(static function (array $ch): array {
                return [
                    'id' => $ch['id'],
                    'name' => $ch['name'],
                    'group' => $ch['group'],
                    'cat' => $ch['cat'],
                    'epg' => $ch['epg'] ?? '',
                ];
            }, $pack['channels']),
            'total' => count($pack['channels']),
            'totalAll' => count($pack['channels']),
        ];
        $leanJson = json_encode($lean, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($leanJson !== false) {
            $pub = $publicDir . '/live-channels.json';
            $pubTmp = $pub . '.tmp';
            if (@file_put_contents($pubTmp, $leanJson, LOCK_EX) !== false) {
                @rename($pubTmp, $pub);
            }
        }
    }

    private static function slug(string $value): string
    {
        $value = strtolower(trim($value));
        if ($value === '') {
            return '';
        }
        $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? '';
        return trim($value, '-') ?: 'other';
    }
}
