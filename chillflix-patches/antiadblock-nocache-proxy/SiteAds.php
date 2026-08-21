<?php
declare(strict_types=1);

/**
 * Site ad network settings (AdCash units + placement targets).
 * File-backed so toggles apply instantly without a DB migration.
 *
 * Audience: guests + regular users only (staff never see ads).
 * User settings can opt out of Pop only; admin may bypass that opt-out.
 * Banner / interstitial / video slider follow admin toggles only.
 */
final class SiteAds
{
    private const FILE = 'site-ads.json';

    /** @var list<string> */
    public const PLACEMENTS = [
        'everywhere',
        'home',
        'watch',
        'player',
        'movies',
        'tv',
        'live',
        'games',
    ];

    /**
     * @return array{
     *   enabled:bool,banner:bool,interstitial:bool,videoSlider:bool,pop:bool,
     *   popBypassUserOptOut:bool,
     *   bannerZone:string,interstitialZone:string,videoSliderZone:string,popZone:string,
     *   antiAdblockSrc:string,placements:list<string>,updated:int
     * }
     */
    public static function defaults(): array
    {
        return [
            'enabled' => true,
            'banner' => true,
            'interstitial' => true,
            'videoSlider' => true,
            'pop' => true,
            'popBypassUserOptOut' => false,
            'bannerZone' => '11970470',
            'interstitialZone' => '11979762',
            'videoSliderZone' => '11979774',
            'popZone' => '11979882',
            'antiAdblockSrc' => '/assets/js/g5wya9be.js',
            'antiAdblockRotatedAt' => 0,
            'antiAdblockAutoRotate' => false,
            'antiAdblockAutoRotateHours' => 24,
            'antiAdblockAutoRotateMinutes' => 1440,
            'placements' => ['everywhere'],
            'updated' => 0,
        ];
    }

    /** @return array<string,mixed> */
    public static function get(): array
    {
        $path = self::path();
        $base = self::defaults();
        if (!is_file($path)) {
            return $base;
        }
        $raw = @file_get_contents($path);
        $data = $raw !== false ? json_decode($raw, true) : null;
        if (!is_array($data)) {
            return $base;
        }
        return self::normalize($data);
    }

    /**
     * @param array<string,mixed> $patch
     * @return array<string,mixed>
     */
    public static function save(array $patch): array
    {
        $next = self::normalize(array_merge(self::get(), $patch));
        $next['updated'] = time();
        $path = self::path();
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $json = json_encode($next, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        if ($json === false) {
            return $next;
        }
        $tmp = $path . '.tmp';
        if (@file_put_contents($tmp, $json, LOCK_EX) !== false) {
            @rename($tmp, $path);
        }
        return $next;
    }

    public static function masterOn(): bool
    {
        return !empty(self::get()['enabled']);
    }

    /**
     * Ads are for guests + regular users only.
     * Staff (admin / moderator) never see site ads.
     */
    public static function audienceAllowsAds(?array $user = null): bool
    {
        if (!class_exists('Auth')) {
            return true;
        }
        $user = $user ?? Auth::user();
        if ($user && Auth::isStaff($user)) {
            return false;
        }
        return true;
    }

    /** Skip ads on staff/admin surfaces and for staff accounts. */
    public static function shouldRenderOnPage(?string $bodyClass = null, ?string $path = null): bool
    {
        $cls = (string) ($bodyClass ?? '');
        if ($cls !== '' && preg_match('/\bpage-admin\b/', $cls)) {
            return false;
        }
        if (!self::audienceAllowsAds()) {
            return false;
        }
        return self::masterOn();
    }

    /** Banner / interstitial / video slider: master + unit + audience (not Pop placements). */
    public static function unitShouldRender(string $unit, ?string $bodyClass = null, ?string $path = null): bool
    {
        if (!self::shouldRenderOnPage($bodyClass, $path)) {
            return false;
        }
        return self::unitOn($unit);
    }

    /** Pop unit: master + pop toggle + placement match + audience. User opt-out is client-side unless bypass. */
    public static function popShouldRender(?string $bodyClass = null, ?string $path = null): bool
    {
        if (!self::shouldRenderOnPage($bodyClass, $path)) {
            return false;
        }
        $s = self::get();
        if (empty($s['pop'])) {
            return false;
        }
        return self::placementMatches($s['placements'] ?? ['everywhere'], $bodyClass, $path);
    }

    public static function popBypassUserOptOut(): bool
    {
        return !empty(self::get()['popBypassUserOptOut']);
    }

    public static function unitOn(string $unit): bool
    {
        $s = self::get();
        if (empty($s['enabled'])) {
            return false;
        }
        return !empty($s[$unit]);
    }

    /**
     * @param list<string>|mixed $placements
     */
    public static function placementMatches($placements, ?string $bodyClass = null, ?string $path = null): bool
    {
        $list = self::normalizePlacements($placements);
        if (in_array('everywhere', $list, true)) {
            return true;
        }

        $cls = strtolower((string) ($bodyClass ?? ''));
        $path = strtolower((string) ($path ?? (function_exists('current_path') ? current_path() : '/')));
        $path = '/' . ltrim($path, '/');

        $tags = [];
        if ($cls !== '' && preg_match('/\bhome\b/', $cls)) {
            $tags[] = 'home';
        }
        if ($cls !== '' && preg_match('/\bpage-watch\b/', $cls)) {
            $tags[] = 'watch';
        }
        if ($cls !== '' && preg_match('/\bpage-player\b/', $cls)) {
            $tags[] = 'player';
        }
        if ($cls !== '' && preg_match('/\bpage-live\b/', $cls)) {
            $tags[] = 'live';
        }
        if ($cls !== '' && preg_match('/\bpage-games\b/', $cls)) {
            $tags[] = 'games';
        }

        if ($path === '/' || $path === '/home' || str_starts_with($path, '/home/')) {
            $tags[] = 'home';
        }
        if (str_starts_with($path, '/movie/') || str_starts_with($path, '/movies')) {
            $tags[] = 'movies';
            $tags[] = 'watch';
        }
        if (str_starts_with($path, '/tv/') || str_starts_with($path, '/shows')) {
            $tags[] = 'tv';
            $tags[] = 'watch';
        }
        if (str_starts_with($path, '/live') || str_starts_with($path, '/iptv')) {
            $tags[] = 'live';
        }
        if (str_starts_with($path, '/games') || str_starts_with($path, '/game/')) {
            $tags[] = 'games';
        }
        if (str_starts_with($path, '/play/') || str_contains($path, '/player')) {
            $tags[] = 'player';
        }

        $tags = array_values(array_unique($tags));
        foreach ($tags as $tag) {
            if (in_array($tag, $list, true)) {
                return true;
            }
        }
        return false;
    }

    /** @return array<string,string> */
    public static function placementLabels(): array
    {
        return [
            'everywhere' => 'Everywhere',
            'home' => 'Homepage only',
            'watch' => 'Watch page (whole)',
            'player' => 'Player only',
            'movies' => 'Movies',
            'tv' => 'TV shows',
            'live' => 'IPTV / Live TV',
            'games' => 'Games',
        ];
    }

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    private static function normalize(array $data): array
    {
        $base = self::defaults();
        $zone = static function ($v, string $fallback): string {
            $s = preg_replace('/\D+/', '', (string) $v) ?? '';
            return $s !== '' ? $s : $fallback;
        };
        $src = trim((string) ($data['antiAdblockSrc'] ?? $base['antiAdblockSrc']));
        if ($src === '' || !str_starts_with($src, '/')) {
            $src = $base['antiAdblockSrc'];
        }
        return [
            'enabled' => !empty($data['enabled']),
            'banner' => array_key_exists('banner', $data) ? !empty($data['banner']) : true,
            'interstitial' => array_key_exists('interstitial', $data) ? !empty($data['interstitial']) : true,
            'videoSlider' => array_key_exists('videoSlider', $data) ? !empty($data['videoSlider']) : true,
            'pop' => array_key_exists('pop', $data) ? !empty($data['pop']) : true,
            'popBypassUserOptOut' => !empty($data['popBypassUserOptOut']),
            'bannerZone' => $zone($data['bannerZone'] ?? $base['bannerZone'], $base['bannerZone']),
            'interstitialZone' => $zone($data['interstitialZone'] ?? $base['interstitialZone'], $base['interstitialZone']),
            'videoSliderZone' => $zone($data['videoSliderZone'] ?? $base['videoSliderZone'], $base['videoSliderZone']),
            'popZone' => $zone($data['popZone'] ?? $base['popZone'], $base['popZone']),
            'antiAdblockSrc' => $src,
            'antiAdblockRotatedAt' => (int) ($data['antiAdblockRotatedAt'] ?? 0),
            'antiAdblockAutoRotate' => !empty($data['antiAdblockAutoRotate']),
            'antiAdblockAutoRotateHours' => max(1, min(168, (int) ($data['antiAdblockAutoRotateHours'] ?? max(1, (int) round(((int) ($data['antiAdblockAutoRotateMinutes'] ?? 1440)) / 60))))),
            'antiAdblockAutoRotateMinutes' => (static function () use ($data): int {
                $mins = (int) ($data['antiAdblockAutoRotateMinutes'] ?? 0);
                if ($mins <= 0) {
                    $mins = max(1, (int) ($data['antiAdblockAutoRotateHours'] ?? 24)) * 60;
                }
                return max(10, min(10080, $mins));
            })(),
            'placements' => self::normalizePlacements($data['placements'] ?? $base['placements']),
            'updated' => (int) ($data['updated'] ?? 0),
        ];
    }

    /**
     * @param mixed $raw
     * @return list<string>
     */


    /**
     * AdCash tip: when blockers learn the lib filename, rotate to a new obscure name.
     * @return array{ok:bool,message:string,antiAdblockSrc?:string,antiAdblockRotatedAt?:int,error?:string}
     */
    public static function rotateAntiAdblockLib(): array
    {
        $out = [];
        $code = 1;
        @exec('sudo -n /usr/local/sbin/adcash-rotate-antiadblock 2>/dev/null', $out, $code);
        $raw = trim(implode(PHP_EOL, $out));
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return ['ok' => false, 'message' => 'Rotate helper failed', 'error' => 'sudo'];
        }
        $ads = self::get();
        return [
            'ok' => !empty($decoded['ok']),
            'message' => (string) ($decoded['message'] ?? 'Done'),
            'antiAdblockSrc' => isset($decoded['antiAdblockSrc']) ? (string) $decoded['antiAdblockSrc'] : ($ads['antiAdblockSrc'] ?? null),
            'antiAdblockRotatedAt' => (int) ($decoded['antiAdblockRotatedAt'] ?? $ads['antiAdblockRotatedAt'] ?? 0),
            'antiAdblockAutoRotate' => !empty($ads['antiAdblockAutoRotate']),
            'antiAdblockAutoRotateHours' => (int) ($ads['antiAdblockAutoRotateHours'] ?? 24),
            'antiAdblockAutoRotateMinutes' => (int) ($ads['antiAdblockAutoRotateMinutes'] ?? 1440),
            'error' => isset($decoded['error']) ? (string) $decoded['error'] : null,
        ];
    }





    /**
     * Serve anti-adblock JS with Cache-Control: no-store so Cloudflare/browsers
     * cannot keep a stale copy for hours (direct /cdn/*.js was CF HIT max-age=14400).
     */

    /** Absolute filesystem path for the cached anti-adblock library, or "". */
    public static function antiAdblockFilePath(?array $ads = null): string
    {
        $ads = is_array($ads) ? $ads : self::get();
        $file = trim((string) ($ads['antiAdblockFile'] ?? ''));
        if ($file !== '' && is_file($file)) {
            return $file;
        }
        $src = trim((string) ($ads['antiAdblockSrc'] ?? ''));
        if ($src === '' || !str_starts_with($src, '/')) {
            return '';
        }
        // /n/NAME.js and /cdn/NAME.js and /assets/js/NAME.js
        $base = basename(parse_url($src, PHP_URL_PATH) ?: $src);
        if (!preg_match('/^[a-z0-9]{6,32}\.js$/i', $base)) {
            return '';
        }
        $root = dirname(__DIR__, 2) . '/public';
        foreach ([
            $root . '/assets/js/' . $base,
            $root . '/cdn/' . $base,
        ] as $cand) {
            if (is_file($cand)) {
                return $cand;
            }
        }
        return '';
    }

    /**
     * AdCash docs: inject library SOURCE into the page (not only script src).
     * Cached on disk via cron; safe for <script> embedding.
     */
    public static function antiAdblockInlineJs(?array $ads = null): string
    {
        $path = self::antiAdblockFilePath($ads);
        if ($path === '') {
            return '';
        }
        $js = @file_get_contents($path);
        if (!is_string($js) || strlen($js) < 1000) {
            return '';
        }
        // Prevent early </script> breakout if present in payload.
        return str_replace(['</script>', '</Script>', '</SCRIPT>'], ['<\\/script>', '<\\/script>', '<\\/script>'], $js);
    }

    public static function serveAntiAdblockNocache(string $name): void
    {
        $name = strtolower(trim($name));
        $name = preg_replace('/\.js$/', '', $name) ?? $name;
        if (!preg_match('/^[a-z0-9]{6,32}$/', $name)) {
            http_response_code(404);
            header('Content-Type: text/plain; charset=UTF-8');
            echo 'Not found';
            return;
        }
        $root = dirname(__DIR__, 2);
        $cdn = $root . '/public/cdn/' . $name . '.js';
        $assets = $root . '/public/assets/js/' . $name . '.js';
        $path = is_file($cdn) ? $cdn : (is_file($assets) ? $assets : '');
        if ($path === '') {
            http_response_code(404);
            header('Content-Type: text/plain; charset=UTF-8');
            echo 'Not found';
            return;
        }
        header('Content-Type: application/javascript; charset=UTF-8');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('Expires: 0');
        header('X-Content-Type-Options: nosniff');
        readfile($path);
    }

    private static function normalizePlacements($raw): array
    {
        if (is_string($raw) && $raw !== '') {
            $raw = preg_split('/\s*,\s*/', $raw) ?: [];
        }
        if (!is_array($raw)) {
            return ['everywhere'];
        }
        $out = [];
        foreach ($raw as $p) {
            $p = strtolower(trim((string) $p));
            if (in_array($p, self::PLACEMENTS, true)) {
                $out[] = $p;
            }
        }
        $out = array_values(array_unique($out));
        if (!$out) {
            return ['everywhere'];
        }
        if (in_array('everywhere', $out, true)) {
            return ['everywhere'];
        }
        return $out;
    }

    private static function path(): string
    {
        $dir = (string) config('cache_dir', __DIR__ . '/../../storage/cache');
        return rtrim($dir, '/') . '/' . self::FILE;
    }
}
