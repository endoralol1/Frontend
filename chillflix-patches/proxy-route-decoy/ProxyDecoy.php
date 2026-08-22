<?php
declare(strict_types=1);

/**
 * Old player proxy routes (/media-proxy, /lang-proxy) now serve a "visit vuflix.co"
 * decoy image instead of streams — breaks scrapers stuck on the old paths.
 */
final class ProxyDecoy
{
    public static function serve(): void
    {
        $accept = strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? ''));
        $img = dirname(__DIR__, 2) . '/public/assets/img/visit-vuflix.jpg';
        if (!is_file($img)) {
            $img = dirname(__DIR__, 2) . '/public/assets/img/logo-vuflix.png';
        }

        header('X-Robots-Tag: noindex, nofollow');
        header('Cache-Control: no-store');
        header('Access-Control-Allow-Origin: *');

        // Browsers opening the old URL get a small HTML card; rippers/curl get the image bytes.
        if (str_contains($accept, 'text/html')) {
            header('Content-Type: text/html; charset=utf-8');
            $src = htmlspecialchars(url('/assets/img/visit-vuflix.jpg'), ENT_QUOTES, 'UTF-8');
            echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">'
                . '<meta name="viewport" content="width=device-width,initial-scale=1">'
                . '<title>Visit vuflix.co</title>'
                . '<style>html,body{margin:0;min-height:100%;background:#0b1220;color:#e2e8f0;font-family:system-ui,sans-serif}'
                . '.wrap{min-height:100vh;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:1.25rem;padding:1.5rem;text-align:center}'
                . 'img{max-width:min(920px,100%);border-radius:12px;box-shadow:0 20px 50px rgba(0,0,0,.45)}'
                . 'a{color:#2dd4bf;font-weight:700;font-size:1.15rem;text-decoration:none}</style></head><body>'
                . '<div class="wrap"><a href="https://vuflix.co/"><img src="' . $src . '" alt="Watch free on vuflix.co"></a>'
                . '<p>This stream link moved.</p>'
                . '<a href="https://vuflix.co/">Open vuflix.co →</a></div></body></html>';
            exit;
        }

        if (is_file($img)) {
            $mime = str_ends_with($img, '.png') ? 'image/png' : 'image/jpeg';
            header('Content-Type: ' . $mime);
            header('Content-Length: ' . (string) filesize($img));
            readfile($img);
            exit;
        }

        http_response_code(410);
        header('Content-Type: text/plain; charset=utf-8');
        echo "Gone — visit https://vuflix.co/\n";
        exit;
    }
}
