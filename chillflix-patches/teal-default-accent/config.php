<?php
declare(strict_types=1);

return [
    'site_name'    => 'Chillflix',
    'site_tagline' => 'Watch Movies & TV Series Online Free',
    'base_url'     => 'https://www.chillflix.lol/newsite', // auto-detected if empty
    'theme_color'  => '#14b8a6',
    'locale'       => 'en_US',
    'lang'         => 'en',

    // Main-site auth (same domain cookies) + Cloudflare Turnstile
    'main_origin' => 'https://www.chillflix.lol',
    'turnstile_site_key' => '0x4AAAAAADkt609V4bDdbUKh',

    // Newsite MySQL (accounts, favorites, continue watching, sources)
    'db' => [
        'host' => '127.0.0.1',
        'port' => 3306,
        'database' => 'newsite',
        'user' => 'newsite',
        'password' => "c6T3FpoFUD4pXTgcuR8SMy4lYygL",
        'charset' => 'utf8mb4',
    ],
    'auth_secret' => "414aed62a6f9fe92617e608c2ccdcbd5ac989670b62af0c901b4cd161a1f987a",
    'turnstile_secret_key' => "0x4AAAAAADkt69d2CxrD59E7-eZ8HBwkOJ0",


    // Newsite /player — only these scrapers by default (clean + focused)
    'player_providers' => ['vaplayer', 'huhu'],
    'player_main_api' => 'https://www.chillflix.lol',

    // TMDB — replace with your own key for production
    'tmdb_api_key' => '829a43a98259bc44cae297489c7e3bba',
    'tmdb_base'    => 'https://api.themoviedb.org/3',
    'tmdb_img'     => 'https://image.tmdb.org/t/p',
    'tmdb_lang'    => 'en-US',

    // File cache (seconds). 0 = off
    'cache_ttl' => 1800,
    'cache_dir' => __DIR__ . '/../storage/cache',

    // Visual server list (matches original UI). Playback uses official trailers.
    'servers' => [
        'premium' => [
            ['id' => 'vidfast', 'name' => 'VidFast'],
            ['id' => 'videasy', 'name' => 'VidEasy'],
            ['id' => 'vidrock', 'name' => 'VidRock'],
            ['id' => 'vidup',   'name' => 'VidUp'],
            ['id' => 'vidlink', 'name' => 'VidLink'],
        ],
        'backup' => [
            ['id' => '2embedstream', 'name' => '1Embed'],
            ['id' => 'vidcore',      'name' => 'Vidcore'],
            ['id' => '2embed',       'name' => '2embed'],
            ['id' => '1movies',      'name' => '1Movies'],
        ],
    ],

    'genres_movie' => [
        28 => 'Action', 12 => 'Adventure', 16 => 'Animation', 35 => 'Comedy', 80 => 'Crime',
        99 => 'Documentary', 18 => 'Drama', 10751 => 'Family', 14 => 'Fantasy', 36 => 'History',
        27 => 'Horror', 10402 => 'Music', 9648 => 'Mystery', 10749 => 'Romance', 878 => 'Science Fiction',
        10770 => 'TV Movie', 53 => 'Thriller', 10752 => 'War', 37 => 'Western',
    ],
    'genres_tv' => [
        10759 => 'Action & Adventure', 16 => 'Animation', 35 => 'Comedy', 80 => 'Crime', 99 => 'Documentary',
        18 => 'Drama', 10751 => 'Family', 10762 => 'Kids', 9648 => 'Mystery', 10763 => 'News',
        10764 => 'Reality', 10765 => 'Sci-Fi & Fantasy', 10766 => 'Soap', 10767 => 'Talk',
        10768 => 'War & Politics', 37 => 'Western',
    ],
];
