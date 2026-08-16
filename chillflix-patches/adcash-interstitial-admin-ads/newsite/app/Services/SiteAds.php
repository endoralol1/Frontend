<?php
declare(strict_types=1);

/**
 * Site ad network settings (AdCash banner + interstitial).
 * File-backed so toggles apply instantly without a DB migration.
 */
final class SiteAds
{
    private const FILE = 'site-ads.json';

    /** @return array{enabled:bool,banner:bool,interstitial:bool,bannerZone:string,interstitialZone:string,updated:int} */
    public static function defaults(): array
    {
        return [
            'enabled' => true,
            'banner' => true,
            'interstitial' => true,
            'bannerZone' => '11970470',
            'interstitialZone' => '11979762',
            'updated' => 0,
        ];
    }

    /** @return array{enabled:bool,banner:bool,interstitial:bool,bannerZone:string,interstitialZone:string,updated:int} */
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
     * @return array{enabled:bool,banner:bool,interstitial:bool,bannerZone:string,interstitialZone:string,updated:int}
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

    public static function bannerOn(): bool
    {
        $s = self::get();
        return !empty($s['enabled']) && !empty($s['banner']);
    }

    public static function interstitialOn(): bool
    {
        $s = self::get();
        return !empty($s['enabled']) && !empty($s['interstitial']);
    }

    /** Skip ads on staff/admin surfaces. */
    public static function shouldRenderOnPage(?string $bodyClass = null): bool
    {
        $cls = (string) ($bodyClass ?? '');
        if ($cls !== '' && preg_match('/\bpage-admin\b/', $cls)) {
            return false;
        }
        return self::masterOn();
    }

    /**
     * @param array<string,mixed> $data
     * @return array{enabled:bool,banner:bool,interstitial:bool,bannerZone:string,interstitialZone:string,updated:int}
     */
    private static function normalize(array $data): array
    {
        $base = self::defaults();
        $zone = static function ($v, string $fallback): string {
            $s = preg_replace('/\D+/', '', (string) $v) ?? '';
            return $s !== '' ? $s : $fallback;
        };
        return [
            'enabled' => !empty($data['enabled']),
            'banner' => array_key_exists('banner', $data) ? !empty($data['banner']) : true,
            'interstitial' => array_key_exists('interstitial', $data) ? !empty($data['interstitial']) : true,
            'bannerZone' => $zone($data['bannerZone'] ?? $base['bannerZone'], $base['bannerZone']),
            'interstitialZone' => $zone($data['interstitialZone'] ?? $base['interstitialZone'], $base['interstitialZone']),
            'updated' => (int) ($data['updated'] ?? 0),
        ];
    }

    private static function path(): string
    {
        $dir = (string) config('cache_dir', __DIR__ . '/../../storage/cache');
        return rtrim($dir, '/') . '/' . self::FILE;
    }
}
