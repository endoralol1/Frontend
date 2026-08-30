<?php
declare(strict_types=1);

/**
 * Early Singapore bot gate for vuflix.co (CF-IPCountry).
 * Bypass cookie: cf_human_ok=1 (set via /sg-unlock).
 */
final class SgBotGate
{
    private const BLOCKED = ['SG'];

    public static function enforce(): void
    {
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        if (self::isExemptPath($path)) {
            return;
        }

        if (isset($_GET['unlock_sg']) && (string) $_GET['unlock_sg'] === '1') {
            self::unlockAndRedirect();
            return;
        }

        if (($path === '/sg-unlock' || $path === '/sg-unlock/')) {
            self::unlockAndRedirect();
            return;
        }

        if (!empty($_COOKIE['cf_human_ok']) && (string) $_COOKIE['cf_human_ok'] === '1') {
            return;
        }

        $country = strtoupper(trim((string) ($_SERVER['HTTP_CF_IPCOUNTRY'] ?? '')));
        if ($country === '' || !in_array($country, self::BLOCKED, true)) {
            return;
        }

        http_response_code(403);
        header('Cache-Control: no-store');
        header('CDN-Cache-Control: no-store');
        header('Cloudflare-CDN-Cache-Control: no-store');
        header('Content-Type: text/plain; charset=UTF-8');
        echo "Forbidden\n";
        exit;
    }

    private static function isExemptPath(string $path): bool
    {
        $p = strtolower($path);
        foreach (['/api/', '/assets/', '/embed', '/admin', '/.well-known/'] as $prefix) {
            if (str_starts_with($p, $prefix)) {
                return true;
            }
        }
        return false;
    }

    private static function unlockAndRedirect(): void
    {
        setcookie('cf_human_ok', '1', [
            'expires' => time() + 604800,
            'path' => '/',
            'secure' => true,
            'httponly' => false,
            'samesite' => 'Lax',
        ]);
        header('Cache-Control: no-store');
        header('Location: /', true, 302);
        exit;
    }
}
