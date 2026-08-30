<?php
declare(strict_types=1);

/**
 * Early Singapore bot gate for vuflix.co (CF-IPCountry).
 * Bypass cookie: cf_human_ok=1 (set via /sg-unlock).
 * Nginx serves /sg-gate.html for most SG hits; this is defense-in-depth.
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
        header('Content-Type: text/html; charset=UTF-8');
        echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<meta name="robots" content="noindex,nofollow"><title>Continue</title></head><body style="font-family:system-ui;background:#0b0d12;color:#e8eaed;display:grid;place-items:center;min-height:100vh;margin:0">'
            . '<div style="max-width:26rem;padding:1.75rem;text-align:center"><h1 style="font-size:1.25rem">Quick check</h1>'
            . '<p style="color:#a8b0bd;line-height:1.45">We temporarily limit automated traffic from some networks. Continue once to unlock browsing on this device.</p>'
            . '<p><a href="/sg-unlock" style="display:inline-block;padding:.7rem 1.15rem;border-radius:.55rem;background:#3b82f6;color:#fff;text-decoration:none;font-weight:600">Continue</a></p>'
            . '</div></body></html>';
        exit;
    }

    private static function isExemptPath(string $path): bool
    {
        $p = strtolower($path);
        foreach (['/api/', '/assets/', '/embed', '/admin', '/.well-known/', '/sg-gate.html'] as $prefix) {
            if ($p === $prefix || str_starts_with($p, $prefix)) {
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
