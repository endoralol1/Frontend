<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

// Host-based site profile: vuflix.co is the dedicated newsite brand (root app).
// chillflix.lol keeps /newsite + player/proxies unchanged.
(function (): void {
    $host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
    $host = preg_replace('/:\d+$/', '', $host) ?: $host;
    $host = preg_replace('/^www\./', '', $host) ?: $host;
    if ($host !== 'vuflix.co') {
        return;
    }
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || ((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
        || ((int) ($_SERVER['SERVER_PORT'] ?? 80) === 443);
    $scheme = $https ? 'https' : 'http';
    $GLOBALS['__cf_config_override'] = [
        'site_name' => 'Vuflix',
        'site_tagline' => 'Watch Movies & TV Series Online Free',
        'base_url' => $scheme . '://vuflix.co',
        'main_origin' => $scheme . '://vuflix.co',
        // Player source APIs stay on Chillflix infra (proxies/player backend).
        'player_main_api' => 'https://www.chillflix.lol',
        'logo_asset' => 'img/logo-vuflix.webp',
        // Cloudflare Turnstile (vuflix.co widget)
        'turnstile_site_key' => '0x4AAAAAAEFQht9IWsnJ1Be9',
        'turnstile_secret_key' => '0x4AAAAAAEFQhtSaJ-ba1P3rx2GRpPwgdsU',
    ];
})();

require_once __DIR__ . '/Router.php';
require_once __DIR__ . '/Services/Tmdb.php';
require_once __DIR__ . '/Services/PlayerSources.php';
require_once __DIR__ . '/Services/Database.php';
require_once __DIR__ . '/Services/Auth.php';
require_once __DIR__ . '/Services/UserData.php';
require_once __DIR__ . '/Services/SourcesService.php';
require_once __DIR__ . '/Services/StremifySources.php';
require_once __DIR__ . '/Services/VidmolySources.php';
require_once __DIR__ . '/Services/WatchParty.php';
require_once __DIR__ . '/Services/HuhuLiveTv.php';

$tmdb = new Tmdb();
$router = new Router();

require __DIR__ . '/routes.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$uri = $_SERVER['REQUEST_URI'] ?? '/';
$basePath = rtrim((string) (parse_url(base_url(), PHP_URL_PATH) ?: ''), '/');
$requestPath = parse_url($uri, PHP_URL_PATH) ?: '/';
if ($basePath !== '' && str_starts_with($requestPath, $basePath)) {
    $stripped = substr($requestPath, strlen($basePath)) ?: '/';
    $qs = parse_url($uri, PHP_URL_QUERY);
    $uri = $stripped . ($qs ? ('?' . $qs) : '');
}
$router->dispatch($method, $uri);
