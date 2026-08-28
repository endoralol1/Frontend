<?php
declare(strict_types=1);

/**
 * Load newsite services without dispatching the HTTP router.
 * Used by CLI helpers (parallel provider fetch) and by bootstrap.php.
 */
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/Services/Locale.php';

// Host-based site profile: vuflix.co is the dedicated newsite brand (root app).
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
        'player_main_api' => 'https://www.chillflix.lol',
        'logo_asset' => 'img/logo-vuflix.webp',
        'turnstile_site_key' => '0x4AAAAAAEFQht9IWsnJ1Be9',
        'turnstile_secret_key' => '0x4AAAAAAEFQhtSaJ-ba1P3rx2GRpPwgdsU',
    ];
})();

Locale::boot();

require_once __DIR__ . '/Router.php';
require_once __DIR__ . '/Services/Tmdb.php';
require_once __DIR__ . '/Services/PlayerSources.php';
require_once __DIR__ . '/Services/IntroDb.php';
require_once __DIR__ . '/Services/Database.php';
require_once __DIR__ . '/Services/Auth.php';
require_once __DIR__ . '/Services/UserData.php';
require_once __DIR__ . '/Services/SourcesService.php';
require_once __DIR__ . '/Services/StremifySources.php';
require_once __DIR__ . '/Services/NxshaSources.php';
require_once __DIR__ . '/Services/HdgharSources.php';
require_once __DIR__ . '/Services/HollyBoxSources.php';
require_once __DIR__ . '/Services/StreamLangProxy.php';
require_once __DIR__ . '/Services/ProxyDecoy.php';
require_once __DIR__ . '/Services/ProxyGuard.php';
require_once __DIR__ . '/Services/VidmolySources.php';
require_once __DIR__ . '/Services/NetMirrorSources.php';
require_once __DIR__ . '/Services/MegaSourceSources.php';
require_once __DIR__ . '/Services/MoonflixSources.php';
require_once __DIR__ . '/Services/OpStreamSources.php';
require_once __DIR__ . '/Services/TorrentioCacheSources.php';
require_once __DIR__ . '/Services/YesMoviesSources.php';
require_once __DIR__ . '/Services/VideasySources.php';
require_once __DIR__ . '/Services/ByseSources.php';
require_once __DIR__ . '/Services/CineplaySources.php';
require_once __DIR__ . '/Services/WorkerRelayService.php';
require_once __DIR__ . '/Services/WatchParty.php';
require_once __DIR__ . '/Services/HuhuLiveTv.php';
require_once __DIR__ . '/Services/NewsFeed.php';
require_once __DIR__ . '/Services/InboxService.php';
require_once __DIR__ . '/Services/SiteAds.php';
require_once __DIR__ . '/Services/SiteStorage.php';
require_once __DIR__ . '/Services/AdCashReporting.php';
