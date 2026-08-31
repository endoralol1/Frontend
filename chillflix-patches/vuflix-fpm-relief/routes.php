<?php
declare(strict_types=1);

/** @var Router $router */
/** @var Tmdb $tmdb */

$router->get('/', fn () => home($tmdb));
$router->get('/home', function () { redirect('/', 301); });

$router->get('/api/home/because', function () use ($tmdb) {
    $type = ($_GET['type'] ?? 'movie') === 'tv' ? 'tv' : 'movie';
    $id = (int) ($_GET['id'] ?? 0);
    if ($id < 1) {
        json_response(['ok' => false, 'error' => 'id required', 'items' => []], 400);
    }
    $data = $tmdb->recommendations($type, $id, 1);
    $items = $data['results'] ?? [];
    if (count($items) < 6) {
        $similar = $tmdb->similar($type, $id, 1)['results'] ?? [];
        $seen = [];
        foreach ($items as $row) {
            $seen[(int) ($row['id'] ?? 0)] = true;
        }
        foreach ($similar as $row) {
            $rid = (int) ($row['id'] ?? 0);
            if ($rid < 1 || isset($seen[$rid])) {
                continue;
            }
            $items[] = $row;
            $seen[$rid] = true;
            if (count($items) >= 18) {
                break;
            }
        }
    }
    $out = [];
    foreach (array_slice($items, 0, 18) as $row) {
        if (!is_array($row) || empty($row['id'])) {
            continue;
        }
        $row['media_type'] = $type;
        $out[] = $row;
    }
    json_response(['ok' => true, 'type' => $type, 'id' => $id, 'items' => $out]);
});

$router->get('/api/home/network', function () use ($tmdb) {
    $provider = preg_replace('/[^0-9]/', '', (string) ($_GET['provider'] ?? '8')) ?: '8';
    $type = ($_GET['type'] ?? 'movie') === 'tv' ? 'tv' : 'movie';
    $data = $tmdb->discover($type, [
        'with_watch_providers' => $provider,
        'watch_region' => 'US',
        'sort_by' => 'popularity.desc',
        'vote_count.gte' => 40,
        'page' => 1,
    ]);
    $items = [];
    foreach (array_slice($data['results'] ?? [], 0, 18) as $row) {
        if (!is_array($row) || empty($row['id'])) {
            continue;
        }
        $row['media_type'] = $type;
        $items[] = $row;
    }
    json_response(['ok' => true, 'provider' => $provider, 'type' => $type, 'items' => $items]);
});




$router->get('/live', function () {
    // Catalog is file-cached with stale-while-revalidate — never blocks on huhu when cache exists.
    $pack = HuhuLiveTv::catalog();
    $channels = $pack['ok'] ? array_slice($pack['channels'] ?? [], 0, 60) : [];
    $categories = $pack['ok'] ? ($pack['categories'] ?? []) : [];
    $liveAssetV = '20260816-theme1';
    view('pages/live', [
        'categories' => $categories,
        'channels' => $channels,
        'initial' => $channels[0] ?? null,
        'totalAll' => (int) ($pack['totalAll'] ?? count($channels)),
        'extraCss' => [
            asset('css/live.css') . '?v=' . $liveAssetV,
            asset('css/player.css') . '?v=' . $liveAssetV,
        ],
        'extraJs' => [
            'https://cdn.jsdelivr.net/npm/hls.js@1.6.16/dist/hls.min.js',
            asset('js/live.js') . '?v=' . $liveAssetV,
        ],
        'bodyClass' => 'page-live',
        'seo' => [
            'title' => 'Live TV — Watch Channels Online | ' . config('site_name'),
            'description' => 'Watch live TV channels online — sports, news, and entertainment on ' . config('site_name') . '.',
            'canonical' => url('/live'),
        ],
    ]);
});

$router->get('/api/live/channels', function () {
    header('Cache-Control: public, max-age=60');
    $search = isset($_GET['q']) ? (string) $_GET['q'] : (isset($_GET['search']) ? (string) $_GET['search'] : null);
    $cat = isset($_GET['cat']) ? (string) $_GET['cat'] : (isset($_GET['category']) ? (string) $_GET['category'] : null);
    json_response(HuhuLiveTv::catalog($search, $cat));
});

$router->get('/api/live/play', function () {
    $id = (string) ($_GET['id'] ?? '');
    json_response(HuhuLiveTv::play($id));
});

$router->get('/api/live/refresh', function () {
    json_response(HuhuLiveTv::refresh());
});












function loadGamesCatalogCached(): array {
    $cache = __DIR__ . '/../storage/games/catalog.cache';
    $json  = __DIR__ . '/../storage/games/catalog.json';
    if (file_exists($cache) && file_exists($json) && filemtime($cache) >= filemtime($json)) {
        $data = @unserialize(file_get_contents($cache));
        if (is_array($data)) return $data;
    }
    $data = json_decode(file_get_contents($json), true) ?: [];
    file_put_contents($cache, serialize($data));
    return $data;
}

$router->get('/games', function () {
    header('Cache-Control: no-store, must-revalidate');
    $all   = loadGamesCatalogCached();
    $total = count($all);
    $totalPages = max(1, (int) ceil($total / 20));
    $initial = array_slice($all, 0, 20);
    view('pages/games', [
        'headerClass'   => 'absolute',
        'bodyClass'     => 'page-games',
        'initialGames'  => $initial,
        'initialTotal'  => $total,
        'initialPages'  => $totalPages,
        'seo' => [
            'title' => 'Games — Free PC Downloads | ' . config('site_name'),
            'description' => 'Browse and download free PC games. Thousands of titles across action, adventure, RPG, strategy, and more on ' . config('site_name') . '.',
            'canonical' => url('/games'),
        ],
    ]);
});

$router->get('/api/games/list', function () {
    header('Cache-Control: public, max-age=60');
    $all = loadGamesCatalogCached();
    $search   = trim((string) ($_GET['search']   ?? ''));
    $category = trim((string) ($_GET['category'] ?? ''));
    $sort     = trim((string) ($_GET['sort']     ?? 'newest'));
    $page     = max(1, (int) ($_GET['page']  ?? 1));
    $limit    = min(40, max(1, (int) ($_GET['limit'] ?? 20)));
    $filtered = $all;
    if ($category !== '') {
        $catLow = strtolower($category);
        $filtered = array_values(array_filter($filtered, function ($g) use ($catLow) {
            if (strtolower((string) ($g['category'] ?? '')) === $catLow) return true;
            foreach ((array) ($g['genres'] ?? []) as $gr) {
                if (strtolower((string) $gr) === $catLow) return true;
            }
            return false;
        }));
    }
    if ($search !== '') {
        $sl = strtolower($search);
        $filtered = array_values(array_filter($filtered, function ($g) use ($sl) {
            return str_contains(strtolower((string) ($g['title'] ?? '')), $sl)
                || str_contains(strtolower((string) ($g['description'] ?? '')), $sl);
        }));
    }
    if ($sort === 'az') {
        usort($filtered, static fn ($a, $b) => strcasecmp((string) ($a['title'] ?? ''), (string) ($b['title'] ?? '')));
    } elseif ($sort === 'size') {
        usort($filtered, static function ($a, $b) {
            $pa = (float) preg_replace('/[^0-9.]/', '', (string) ($a['fileSize'] ?? '0'));
            $pb = (float) preg_replace('/[^0-9.]/', '', (string) ($b['fileSize'] ?? '0'));
            return $pb <=> $pa;
        });
    }
    // 'newest' = catalog's natural order (already newest-added first)

    $total      = count($filtered);
    $totalPages = max(1, (int) ceil($total / $limit));
    $games      = array_slice($filtered, ($page - 1) * $limit, $limit);
    json_response(['success' => true, 'page' => $page, 'limit' => $limit, 'total' => $total, 'totalPages' => $totalPages, 'games' => $games]);
});

$router->get('/api/games/counts', function () {
    header('Cache-Control: public, max-age=300');
    $all = loadGamesCatalogCached();
    $counts = ['total' => count($all)];
    foreach ($all as $g) {
        $cat = (string) ($g['category'] ?? '');
        if ($cat !== '') $counts[$cat] = ($counts[$cat] ?? 0) + 1;
    }
    json_response(['success' => true, 'counts' => $counts]);
});

$router->post('/api/games/download', function () {
    $body = json_body();
    $gameId  = trim((string) ($body['id'] ?? ''));
    $canvasId = trim((string) ($body['canvasId'] ?? ''));

    if ($gameId === '') {
        json_response(['error' => 'Missing game ID'], 400);
        return;
    }
    if ($canvasId === '') {
        json_response(['error' => 'Missing canvasId'], 400);
        return;
    }

    $secretKey = 'f6i6@m29r3fwi^yqd';
    $timestamp  = (string) time();
    $hash       = hash('sha256', $timestamp . $gameId . $secretKey);

    $postData = http_build_query([
        'id'        => $gameId,
        'timestamp' => $timestamp,
        'secretKey' => $hash,
        'canvasId'  => $canvasId,
    ]);

    $ua = $_SERVER['HTTP_USER_AGENT'] ?? 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/120.0 Safari/537.36';

    $ch = curl_init('https://playzip.com/api/getGamesDownloadUrl');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $postData,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 25,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/x-www-form-urlencoded',
            'User-Agent: ' . $ua,
            'Referer: https://playzip.com/download/' . $gameId,
            'Origin: https://playzip.com',
            'Cookie: site_auth=1; key=NBQEah#h@6qHr7T!k',
        ],
    ]);
    $raw  = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($raw === false) {
        json_response(['error' => 'Provider unreachable'], 504);
        return;
    }

    // Parse URL from response (may be plain text or JSON string)
    $url = null;
    $trimmed = trim($raw);
    if (str_starts_with($trimmed, '"') || str_starts_with($trimmed, '{')) {
        $decoded = json_decode($trimmed, true);
        if (is_string($decoded) && str_starts_with($decoded, 'http')) {
            $url = $decoded;
        } elseif (is_array($decoded) && isset($decoded['downloadUrl']) && str_starts_with((string) $decoded['downloadUrl'], 'http')) {
            $url = (string) $decoded['downloadUrl'];
        }
    }
    if (!$url && str_starts_with($trimmed, 'http')) {
        $url = $trimmed;
    }

    if ($url) {
        $fileName = basename(parse_url($url, PHP_URL_PATH) ?: '');
        json_response(['downloadUrl' => $url, 'fileName' => $fileName]);
        return;
    }

    if ($code === 429) {
        json_response(['error' => 'RATE_LIMITED', 'message' => 'Provider is cooling down. Retry in ~2 minutes.', 'retryAfter' => 120], 429);
        return;
    }

    json_response(['error' => 'Provider did not return a download link'], 502);
});



$router->get('/movies', function () use ($tmdb) {
    discover_page($tmdb, 'movie');
});

$router->get('/tv-series', function () use ($tmdb) {
    discover_page($tmdb, 'tv');
});

$router->get('/top-imdb', function () use ($tmdb) {
    $page = max(1, (int) ($_GET['page'] ?? 1));
    $type = ($_GET['type'] ?? 'movie') === 'tv' ? 'tv' : 'movie';
    $data = $tmdb->topRated($type, $page);
    view('pages/top-imdb', [
        'type' => $type,
        'page' => $page,
        'data' => $data,
        'seo' => [
            'title' => 'Top IMDB | ' . config('site_name'),
            'description' => 'Browse the highest-rated movies and TV shows. Stream top IMDb titles online on ' . config('site_name') . '.',
            'canonical' => url('/top-imdb'),
        ],
    ]);
});

$router->get('/favorites', function () {
    view('pages/favorites', [
        'seo' => [
            'title' => 'My Watchlist - Your Personal Movie & Series Collection | ' . config('site_name'),
            'description' => 'Access and manage your personal watchlist of movies and TV series on ' . config('site_name') . '.',
            'robots' => 'noindex, follow',
            'canonical' => url('/favorites'),
        ],
    ]);
});

$router->get('/contact', function () {
    $sent = false;
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        $sent = true; // UI-only form handler
    }
    view('pages/contact', [
        'sent' => $sent,
        'seo' => [
            'title' => 'Contact Us | ' . config('site_name'),
            'description' => 'Contact ' . config('site_name') . ' for inquiries, support, or feedback.',
            'canonical' => url('/contact'),
        ],
    ]);
});
$router->post('/contact', function () use ($router) {
    view('pages/contact', [
        'sent' => true,
        'seo' => [
            'title' => 'Contact Us | ' . config('site_name'),
            'description' => 'Contact ' . config('site_name') . ' for inquiries, support, or feedback.',
            'canonical' => url('/contact'),
            'robots' => 'noindex, follow',
        ],
    ]);
});

$router->get('/request', function () {
    view('pages/request', [
        'sent' => false,
        'seo' => [
            'title' => 'Request Movies & TV Shows | ' . config('site_name'),
            'description' => "Can't find your favorite movie or TV show? Submit a request and we'll add it soon.",
            'canonical' => url('/request'),
        ],
    ]);
});
$router->post('/request', function () {
    view('pages/request', [
        'sent' => true,
        'seo' => [
            'title' => 'Request Movies & TV Shows | ' . config('site_name'),
            'description' => "Can't find your favorite movie or TV show? Submit a request and we'll add it soon.",
            'canonical' => url('/request'),
            'robots' => 'noindex, follow',
        ],
    ]);
});


function newsite_parse_search_filters(): array
{
    $type = strtolower(trim((string) ($_GET['type'] ?? 'all')));
    if (!in_array($type, ['all', 'movie', 'tv'], true)) {
        $type = 'all';
    }
    $genresRaw = $_GET['with_genres'] ?? '';
    if (is_array($genresRaw)) {
        $genres = array_values(array_filter(array_map('intval', $genresRaw)));
    } else {
        $s = trim((string) $genresRaw);
        if ($s === '') {
            $genres = [];
        } elseif (str_contains($s, '|')) {
            $genres = array_values(array_filter(array_map('intval', explode('|', $s))));
        } elseif (str_contains($s, ',')) {
            $genres = array_values(array_filter(array_map('intval', explode(',', $s))));
        } else {
            $genres = array_values(array_filter([(int) $s]));
        }
    }
    $yearFrom = trim((string) ($_GET['year_from'] ?? ''));
    $yearTo = trim((string) ($_GET['year_to'] ?? ''));
    if ($yearFrom !== '' && !preg_match('/^\d{4}$/', $yearFrom)) $yearFrom = '';
    if ($yearTo !== '' && !preg_match('/^\d{4}$/', $yearTo)) $yearTo = '';
    $ratingFrom = trim((string) ($_GET['rating_from'] ?? ''));
    $ratingTo = trim((string) ($_GET['rating_to'] ?? ''));
    if ($ratingFrom !== '' && !is_numeric($ratingFrom)) $ratingFrom = '';
    if ($ratingTo !== '' && !is_numeric($ratingTo)) $ratingTo = '';

    // Full-range sliders mean "no filter"
    if ($yearFrom !== '' && $yearTo !== '' && (int) $yearFrom <= 1970 && (int) $yearTo >= 2026) {
        $yearFrom = '';
        $yearTo = '';
    }
    if ($ratingFrom !== '' && (float) $ratingFrom <= 0 && ($ratingTo === '' || (float) $ratingTo >= 10)) {
        $ratingFrom = '';
        $ratingTo = '';
    }
    if ($ratingFrom !== '' && (float) $ratingFrom <= 0 && $ratingTo === '') {
        $ratingFrom = '';
    }

    return [
        'type' => $type,
        'genres' => $genres,
        'year_from' => $yearFrom,
        'year_to' => $yearTo,
        'rating_from' => $ratingFrom,
        'rating_to' => $ratingTo,
    ];
}

function newsite_filter_search_results(array $results, array $filters): array
{
    $type = $filters['type'] ?? 'all';
    $genres = $filters['genres'] ?? [];
    $yearFrom = $filters['year_from'] ?? '';
    $yearTo = $filters['year_to'] ?? '';
    $ratingFrom = $filters['rating_from'] ?? '';
    $ratingTo = $filters['rating_to'] ?? '';

    return array_values(array_filter($results, static function ($r) use ($type, $genres, $yearFrom, $yearTo, $ratingFrom, $ratingTo) {
        $media = ($r['media_type'] ?? '') === 'tv' ? 'tv' : ((($r['media_type'] ?? '') === 'movie') ? 'movie' : '');
        if ($media === '') return false;
        if ($type !== 'all' && $media !== $type) return false;

        if ($genres) {
            $ids = array_map('intval', $r['genre_ids'] ?? []);
            if ($ids) {
                $hit = false;
                foreach ($genres as $g) {
                    if (in_array((int) $g, $ids, true)) { $hit = true; break; }
                }
                if (!$hit) return false;
            }
        }

        if ($yearFrom !== '' || $yearTo !== '') {
            $y = year_of(date_of($r));
            // Keep undated titles instead of wiping the whole result set
            if ($y !== '') {
                $yi = (int) $y;
                if ($yearFrom !== '' && $yi < (int) $yearFrom) return false;
                if ($yearTo !== '' && $yi > (int) $yearTo) return false;
            }
        }

        if ($ratingFrom !== '' || $ratingTo !== '') {
            $rating = isset($r['vote_average']) ? (float) $r['vote_average'] : 0.0;
            if ($ratingFrom !== '' && $rating < (float) $ratingFrom) return false;
            if ($ratingTo !== '' && $rating > (float) $ratingTo) return false;
        }

        return true;
    }));
}

$router->get('/search', function () use ($tmdb) {
    $q = trim((string) ($_GET['keyword'] ?? $_GET['q'] ?? ''));
    $page = max(1, (int) ($_GET['page'] ?? 1));
    $filters = newsite_parse_search_filters();
    $data = $q !== '' ? $tmdb->search($q, $page) : ['results' => [], 'total_results' => 0];
    $results = array_values(array_filter($data['results'] ?? [], static function ($r) {
        return in_array($r['media_type'] ?? '', ['movie', 'tv'], true);
    }));
    $results = newsite_filter_search_results($results, $filters);
    view('pages/search', [
        'q' => $q,
        'page' => $page,
        'results' => $results,
        'filters' => $filters,
        'total' => count($results),
        'seo' => [
            'title' => ($q !== '' ? 'Search: ' . $q . ' | ' : 'Search | ') . config('site_name'),
            'description' => $q !== ''
                ? 'Search results for "' . $q . '" — movies and TV shows on ' . config('site_name') . '.'
                : 'Search movies and TV shows on ' . config('site_name') . '.',
            'canonical' => url('/search' . ($q !== '' ? '?keyword=' . rawurlencode($q) : '')),
            'robots' => $q === '' ? 'noindex, follow' : 'index, follow',
        ],
    ]);
});

$router->get('/filters', function () use ($tmdb) {
    discover_page($tmdb, ($_GET['type'] ?? 'movie') === 'tv' ? 'tv' : 'movie', true);
});

$router->get('/anime', function () use ($tmdb) {
    $type = ($_GET['type'] ?? 'tv') === 'movie' ? 'movie' : 'tv';
    discover_page($tmdb, $type, false, [
        'animeMode' => true,
        'label' => 'Anime',
        'path' => '/anime',
        'forceGenres' => [16],
        'forceOriginalLanguage' => 'ja',
        'voteCountGte' => 100,
    ]);
});


$router->get('/player/movie/{slug}/{id}', function (array $p) use ($tmdb) {
    player_page($tmdb, 'movie', (int) $p['id'], (string) $p['slug']);
});

$router->get('/player/tv/{slug}/{id}', function (array $p) use ($tmdb) {
    player_page($tmdb, 'tv', (int) $p['id'], (string) $p['slug']);
});

$router->get('/api/player/session', function () {
    if (!class_exists('ProxyGuard')) {
        json_response(['ok' => false, 'error' => 'Guard unavailable'], 500);
    }
    $s = ProxyGuard::ensureSession();
    json_response(['ok' => true, 'exp' => (int) ($s['exp'] ?? 0)]);
});

$router->get('/api/player/providers', function () {
    $result = PlayerSources::listProviders();
    json_response($result, 200);
});

$router->get('/api/player/sources', function () {
    if (class_exists('ProxyGuard')) {
        $gate = ProxyGuard::gate('sources');
        if (empty($gate['ok'])) {
            json_response(['ok' => false, 'error' => (string) ($gate['error'] ?? 'Forbidden'), 'sources' => [], 'diagnostics' => []], (int) ($gate['code'] ?? 403));
        }
    }
    // VSEmbed / remote scrapers can take 15–40s cold; default max_execution_time=30 cuts them off.
    if (function_exists('set_time_limit')) {
        @set_time_limit(40);
    }
    @ini_set('max_execution_time', '40');
    $type = ($_GET['type'] ?? '') === 'tv' ? 'tv' : 'movie';
    $tmdbId = (int) ($_GET['tmdbId'] ?? 0);
    $season = max(1, (int) ($_GET['season'] ?? $_GET['s'] ?? 1));
    $episode = max(1, (int) ($_GET['episode'] ?? $_GET['e'] ?? 1));
    $only = isset($_GET['provider']) ? strtolower(trim((string) $_GET['provider'])) : '';
    $result = PlayerSources::fetch($type, $tmdbId, $season, $episode, $only !== '' ? $only : null);
    // Always return JSON 200 for scrape misses — HTTP 502 made Cloudflare/HTML bodies that
    // the player could not parse, so it skipped the source even when a retry would work.
    json_response($result, 200);
});

$router->get('/api/player/skip-segments', function () {
    $type = ($_GET['type'] ?? '') === 'tv' ? 'tv' : 'movie';
    $tmdbId = (int) ($_GET['tmdbId'] ?? 0);
    $season = max(1, (int) ($_GET['season'] ?? $_GET['s'] ?? 1));
    $episode = max(1, (int) ($_GET['episode'] ?? $_GET['e'] ?? 1));
    $durationMs = isset($_GET['durationMs']) ? (int) $_GET['durationMs'] : null;
    if ($durationMs !== null && $durationMs <= 0) {
        $durationMs = null;
    }
    if (!class_exists('IntroDb')) {
        json_response(['ok' => false, 'segments' => [], 'error' => 'IntroDb unavailable'], 200);
    }
    $result = IntroDb::fetchSegments($type, $tmdbId, $season, $episode, $durationMs);
    json_response($result, 200);
});


$router->get('/api/player/episodes', function () use ($tmdb) {
    $type = ($_GET['type'] ?? '') === 'tv' ? 'tv' : 'movie';
    $id = (int) ($_GET['tmdbId'] ?? $_GET['id'] ?? 0);
    $season = max(1, (int) ($_GET['season'] ?? $_GET['s'] ?? 1));
    if ($type !== 'tv' || $id < 1) {
        json_response(['ok' => false, 'episodes' => [], 'error' => 'invalid'], 400);
    }
    $item = $tmdb->details('tv', $id);
    if (!$item) {
        json_response(['ok' => false, 'episodes' => [], 'error' => 'not_found'], 404);
    }
    $title = title_of($item);
    $seasonData = $tmdb->get("tv/{$id}/season/{$season}");
    $episodes = [];
    foreach (($seasonData['episodes'] ?? []) as $epRow) {
        $en = (int) ($epRow['episode_number'] ?? 0);
        if ($en < 1) {
            continue;
        }
        $still = !empty($epRow['still_path']) ? img_url($epRow['still_path'], 'w300') : null;
        $episodes[] = [
            'season' => $season,
            'episode' => $en,
            'title' => (string) ($epRow['name'] ?? ('Episode ' . $en)),
            'overview' => truncate((string) ($epRow['overview'] ?? ''), 140),
            'runtime' => isset($epRow['runtime']) ? (int) $epRow['runtime'] : null,
            'still' => $still,
            'airDate' => (string) ($epRow['air_date'] ?? ''),
            'url' => media_url('tv', $id, $title) . '?s=' . $season . '&e=' . $en . '&play=1',
        ];
    }
    json_response(['ok' => true, 'season' => $season, 'episodes' => $episodes], 200);
});

$router->get('/api/player/subtitles', function () {
    $type = ($_GET['type'] ?? '') === 'tv' ? 'tv' : 'movie';
    $tmdbId = (int) ($_GET['tmdbId'] ?? 0);
    $imdbId = isset($_GET['imdbId']) ? trim((string) $_GET['imdbId']) : null;
    $season = max(1, (int) ($_GET['season'] ?? $_GET['s'] ?? 1));
    $episode = max(1, (int) ($_GET['episode'] ?? $_GET['e'] ?? 1));
    $result = PlayerSources::fetchSubtitles($type, $tmdbId, $imdbId ?: null, $season, $episode);
    json_response($result, !empty($result['ok']) ? 200 : 502);
});

$router->get('/api/player/caption', function () {
    $url = trim((string) ($_GET['url'] ?? ''));
    PlayerSources::proxyCaption($url);
});


// Old proxy paths — decoy image pointing scrapers/curious visitors to vuflix.co
$router->get('/api/player/lang-proxy', function () {
    ProxyDecoy::serve();
});
$router->get('/api/player/media-proxy', function () {
    ProxyDecoy::serve();
});

// Current stream relays (rotated off media-proxy / lang-proxy)
$router->get('/api/player/a-relay', function () {
    if (class_exists('ProxyGuard')) {
        $gate = ProxyGuard::gate('relay');
        if (empty($gate['ok'])) {
            http_response_code((int) ($gate['code'] ?? 403));
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'error' => (string) ($gate['error'] ?? 'Forbidden')]);
            exit;
        }
    }
    $token = trim((string) ($_GET['t'] ?? ''));
    $sig = trim((string) ($_GET['s'] ?? ''));
    if (!class_exists('StreamLangProxy')) {
        json_response(['ok' => false, 'error' => 'Lang proxy unavailable'], 404);
    }
    StreamLangProxy::handle($token, $sig);
});

$router->get('/api/player/v-relay', function () {
    if (class_exists('ProxyGuard')) {
        $gate = ProxyGuard::gate('relay');
        if (empty($gate['ok'])) {
            http_response_code((int) ($gate['code'] ?? 403));
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'error' => (string) ($gate['error'] ?? 'Forbidden')]);
            exit;
        }
    }
    $token = trim((string) ($_GET['t'] ?? ''));
    $sig = trim((string) ($_GET['s'] ?? ''));
    if (class_exists('VidmolySources')) {
        VidmolySources::proxyMedia($token, $sig);
    }
    if (class_exists('StremifySources')) {
        StremifySources::proxyMedia($token, $sig);
    }
    json_response(['ok' => false, 'error' => 'Media proxy unavailable'], 404);
});

/** Browser-scraped Cineplay/Yoru stream → signed v-relay URL for native player */
$router->post('/api/cineplay/mint-proxy', function () {
    if (!class_exists('CineplaySources')) {
        json_response(['ok' => false, 'error' => 'Cineplay unavailable'], 404);
    }
    $body = json_body();
    $url = trim((string) ($body['url'] ?? ''));
    if ($url === '' || !preg_match('#^https?://#i', $url)) {
        json_response(['ok' => false, 'error' => 'url required'], 400);
    }
    try {
        $proxy = CineplaySources::mintProxy($url);
        json_response(['ok' => true, 'url' => $proxy, 'type' => 'hls']);
    } catch (Throwable $e) {
        json_response(['ok' => false, 'error' => $e->getMessage()], 500);
    }
});

/** Optional server-side Yoru scrape (needs non-banned egress / PROVIDER_FETCH_PROXY) */
$router->get('/api/cineplay/yoru', function () {
    if (!class_exists('CineplaySources')) {
        json_response(['ok' => false, 'error' => 'Cineplay unavailable'], 404);
    }
    $type = (($_GET['type'] ?? '') === 'tv') ? 'tv' : 'movie';
    $tmdbId = (int) ($_GET['tmdbId'] ?? 0);
    $season = max(1, (int) ($_GET['season'] ?? 1));
    $episode = max(1, (int) ($_GET['episode'] ?? 1));
    if ($tmdbId < 1) {
        json_response(['ok' => false, 'error' => 'tmdbId required'], 400);
    }
    $GLOBALS['__cf_config_override'] = array_merge(
        is_array($GLOBALS['__cf_config_override'] ?? null) ? $GLOBALS['__cf_config_override'] : [],
        ['player_providers' => ['cineplay']]
    );
    try {
        $result = CineplaySources::fetch($type, $tmdbId, $season, $episode);
        // Only return already-scraped HLS (not client stubs)
        $hls = [];
        foreach ($result['sources'] ?? [] as $src) {
            if (!is_array($src)) {
                continue;
            }
            $t = strtolower((string) ($src['type'] ?? ''));
            if (in_array($t, ['hls', 'mp4', 'file'], true) && !empty($src['url']) && !str_starts_with((string) $src['url'], 'cineplay-yoru://')) {
                $hls[] = $src;
            }
        }
        if (!$hls) {
            json_response([
                'ok' => false,
                'error' => (string) (($result['diagnostics'][0]['message'] ?? null) ?: 'Yoru scrape blocked from server'),
                'diagnostics' => $result['diagnostics'] ?? [],
            ], 502);
        }
        json_response(['ok' => true, 'sources' => $hls, 'subtitles' => []]);
    } finally {
        // keep host override; only drop provider force
        if (isset($GLOBALS['__cf_config_override']['player_providers'])) {
            unset($GLOBALS['__cf_config_override']['player_providers']);
        }
    }
});

// ——— Newsite auth + sync + admin APIs ———
$router->post('/api/auth/register', function () {
    $body = json_body();
    if (!empty(config('turnstile_secret_key')) && !Auth::verifyTurnstile($body['turnstileToken'] ?? null)) {
        json_response(['ok' => false, 'error' => 'Captcha failed'], 400);
    }
    try {
        $user = Auth::register((string) ($body['name'] ?? ''), (string) ($body['email'] ?? ''), (string) ($body['password'] ?? ''));
        json_response(['ok' => true, 'user' => $user]);
    } catch (InvalidArgumentException $e) {
        json_response(['ok' => false, 'error' => $e->getMessage()], 400);
    } catch (Throwable $e) {
        json_response(['ok' => false, 'error' => 'Register failed'], 500);
    }
});

$router->post('/api/auth/login', function () {
    $body = json_body();
    if (!empty(config('turnstile_secret_key')) && !Auth::verifyTurnstile($body['turnstileToken'] ?? null)) {
        json_response(['ok' => false, 'error' => 'Captcha failed'], 400);
    }
    try {
        $user = Auth::login((string) ($body['email'] ?? ''), (string) ($body['password'] ?? ''));
        json_response(['ok' => true, 'user' => $user]);
    } catch (InvalidArgumentException $e) {
        json_response(['ok' => false, 'error' => $e->getMessage()], 401);
    } catch (Throwable $e) {
        json_response(['ok' => false, 'error' => 'Login failed'], 500);
    }
});

$router->post('/api/auth/logout', function () {
    Auth::logout();
    json_response(['ok' => true]);
});

$router->get('/api/auth/me', function () {
    $user = Auth::user();
    json_response(['ok' => true, 'user' => $user]);
});

$router->get('/api/user/library', function () use ($tmdb) {
    $user = Auth::requireUser();
    $lib = UserData::library((string) $user['id']);
    $cw = [];
    foreach (($lib['continueWatching'] ?? []) as $row) {
        if (!is_array($row)) {
            continue;
        }
        $type = (($row['type'] ?? '') === 'tv') ? 'tv' : 'movie';
        $id = (int) ($row['id'] ?? 0);
        if ($id > 0) {
            $item = $tmdb->get("{$type}/{$id}");
            if (is_array($item)) {
                $row['title'] = title_of($item);
                $row['poster'] = img_url($item['poster_path'] ?? null, 'w500');
                $row['backdrop'] = img_url($item['backdrop_path'] ?? null, 'w780');
                $y = year_of(date_of($item));
                if ($y !== '') {
                    $row['year'] = $y;
                }
                $row['titleLang'] = class_exists('Locale') ? Locale::current() : (string) config('lang', 'en');
            }
        }
        $cw[] = $row;
    }
    $lib['continueWatching'] = $cw;
    json_response(array_merge(['ok' => true], $lib));
});

$router->post('/api/user/favorites', function () {
    $user = Auth::requireUser();
    $body = json_body();
    try {
        UserData::upsertFavorite((string) $user['id'], $body);
        json_response(['ok' => true, 'favorites' => UserData::listFavorites((string) $user['id'])]);
    } catch (InvalidArgumentException $e) {
        json_response(['ok' => false, 'error' => $e->getMessage()], 400);
    }
});

$router->map(['DELETE', 'POST'], '/api/user/favorites/remove', function () {
    $user = Auth::requireUser();
    $body = json_body();
    $type = (string) ($body['type'] ?? 'movie');
    $id = (int) ($body['id'] ?? 0);
    UserData::removeFavorite((string) $user['id'], $type, $id);
    json_response(['ok' => true, 'favorites' => UserData::listFavorites((string) $user['id'])]);
});

$router->post('/api/user/favorites/clear', function () {
    $user = Auth::requireUser();
    UserData::clearFavorites((string) $user['id']);
    json_response(['ok' => true, 'favorites' => []]);
});

$router->post('/api/user/continue', function () {
    $user = Auth::requireUser();
    $body = json_body();
    try {
        UserData::upsertContinue((string) $user['id'], $body);
        json_response(['ok' => true]);
    } catch (InvalidArgumentException $e) {
        json_response(['ok' => false, 'error' => $e->getMessage()], 400);
    }
});

$router->post('/api/user/continue/remove', function () {
    $user = Auth::requireUser();
    $body = json_body();
    UserData::removeContinue((string) $user['id'], (string) ($body['key'] ?? ''));
    json_response(['ok' => true]);
});

$router->map(['PATCH', 'POST'], '/api/user/prefs', function () {
    $user = Auth::requireUser();
    $body = json_body();
    $updated = UserData::updatePrefs((string) $user['id'], $body);
    json_response(['ok' => true, 'user' => $updated]);
});


$router->post('/api/admin/games/sync', function () {
    $user = Auth::user();
    if (!$user || !Auth::isStaff($user)) {
        json_response(['ok' => false, 'error' => 'Unauthorized'], 401);
        return;
    }
    $catalog = __DIR__ . '/../storage/games/catalog.json';
    $cache   = __DIR__ . '/../storage/games/catalog.cache';
    $metaFile = __DIR__ . '/../storage/games/sync-meta.json';
    $source  = '/var/www/chillflix.lol/koyso-games-detailed-all.json';

    if (!is_writable(dirname($catalog))) {
        json_response(['ok' => false, 'error' => 'storage/games is not writable by the web server user'], 500);
        return;
    }

    $copied = false;
    if (file_exists($source)) {
        $copied = @copy($source, $catalog);
        if (!$copied) {
            json_response(['ok' => false, 'error' => 'Failed to copy from main site (permissions?)'], 500);
            return;
        }
    }

    $data = json_decode(file_get_contents($catalog), true) ?: [];
    if (empty($data)) {
        json_response(['ok' => false, 'error' => 'Catalog is empty after sync — aborted to avoid data loss'], 500);
        return;
    }
    if (@file_put_contents($cache, serialize($data)) === false) {
        json_response(['ok' => false, 'error' => 'Failed to write cache file (permissions?)'], 500);
        return;
    }

    $meta = [
        'lastSyncAt'     => date('c'),
        'lastSyncStatus' => 'success',
        'totalGames'     => count($data),
        'source'         => $copied ? 'main-site' : 'existing',
    ];
    @file_put_contents($metaFile, json_encode($meta, JSON_PRETTY_PRINT));
    json_response(['ok' => true, 'total' => count($data), 'meta' => $meta]);
});

$router->get('/api/admin/games/status', function () {
    $user = Auth::user();
    if (!$user || !Auth::isStaff($user)) {
        json_response(['ok' => false, 'error' => 'Unauthorized'], 401);
        return;
    }
    $metaFile = __DIR__ . '/../storage/games/sync-meta.json';
    $meta = file_exists($metaFile) ? (json_decode(file_get_contents($metaFile), true) ?: []) : [];
    $catalog = __DIR__ . '/../storage/games/catalog.json';
    $meta['catalogExists'] = file_exists($catalog);
    $meta['catalogBytes']  = file_exists($catalog) ? filesize($catalog) : 0;
    $source = '/var/www/chillflix.lol/koyso-games-detailed-all.json';
    $meta['mainSiteAvailable'] = file_exists($source);
    json_response(['ok' => true, 'meta' => $meta]);
});

// Admin pages
$router->get('/admin', function () {
    $user = Auth::user();
    if (!$user || !Auth::isStaff($user)) {
        redirect('/home');
    }
    view('pages/admin/dashboard', [
        'adminUser' => $user,
        'seo' => ['title' => 'Admin | ' . config('site_name'), 'robots' => 'noindex'],
        'bodyClass' => 'page-admin page-admin-dashboard',
    ]);
});

$router->get('/admin/users', function () {
    $user = Auth::user();
    if (!$user || !Auth::isStaff($user)) {
        redirect('/home');
    }
    view('pages/admin/users', [
        'adminUser' => $user,
        'seo' => ['title' => 'Users | Admin', 'robots' => 'noindex'],
        'bodyClass' => 'page-admin',
    ]);
});


$router->get('/admin/inbox', function () {
    $user = Auth::user();
    if (!$user || !Auth::isStaff($user)) {
        redirect('/home');
    }
    if (class_exists('InboxService')) {
        InboxService::ensureTables();
    }
    view('pages/admin/inbox', [
        'adminUser' => $user,
        'seo' => ['title' => 'Inbox | Admin', 'robots' => 'noindex'],
        'bodyClass' => 'page-admin',
    ]);
});

$router->get('/api/admin/inbox', function () {
    Auth::requireRole('admin', 'moderator');
    InboxService::ensureTables();
    json_response(['ok' => true, 'items' => InboxService::adminList()]);
});

$router->post('/api/admin/inbox', function () {
    $actor = Auth::requireRole('admin', 'moderator');
    $body = json_body();
    try {
        $item = InboxService::adminCreate(is_array($body) ? $body : [], (string) $actor['id']);
        json_response(['ok' => true, 'item' => $item]);
    } catch (Throwable $e) {
        json_response(['ok' => false, 'error' => $e->getMessage()], 400);
    }
});

$router->map(['PATCH', 'POST'], '/api/admin/inbox/{id}', function (array $p) {
    Auth::requireRole('admin', 'moderator');
    $body = json_body();
    try {
        $item = InboxService::adminUpdate((string) $p['id'], is_array($body) ? $body : []);
        json_response(['ok' => true, 'item' => $item]);
    } catch (Throwable $e) {
        json_response(['ok' => false, 'error' => $e->getMessage()], 400);
    }
});

$router->map(['DELETE', 'POST'], '/api/admin/inbox/{id}/delete', function (array $p) {
    Auth::requireRole('admin', 'moderator');
    InboxService::adminDelete((string) $p['id']);
    json_response(['ok' => true]);
});

// Allow DELETE on /api/admin/inbox/{id}
$router->map(['DELETE'], '/api/admin/inbox/{id}', function (array $p) {
    Auth::requireRole('admin', 'moderator');
    InboxService::adminDelete((string) $p['id']);
    json_response(['ok' => true]);
});

$router->post('/api/admin/inbox/{id}/ramp', function (array $p) {
    Auth::requireRole('admin', 'moderator');
    $body = json_body();
    try {
        $ramps = InboxService::adminStartRamps((string) $p['id'], is_array($body) ? $body : []);
        json_response(['ok' => true, 'ramps' => $ramps]);
    } catch (Throwable $e) {
        json_response(['ok' => false, 'error' => $e->getMessage()], 400);
    }
});

$router->post('/api/admin/inbox/{id}/ramp/cancel', function (array $p) {
    Auth::requireRole('admin', 'moderator');
    $body = json_body();
    $group = is_array($body) ? ($body['group'] ?? $body['kind'] ?? null) : null;
    $n = InboxService::adminCancelRamps((string) $p['id'], is_string($group) ? $group : null);
    json_response(['ok' => true, 'cancelled' => $n]);
});

$router->get('/api/inbox', function () {
    if (!class_exists('InboxService')) {
        json_response(['ok' => false, 'error' => 'Inbox unavailable'], 500);
    }
    $feed = InboxService::feedForViewer(Auth::user());
    json_response(['ok' => true] + $feed);
});

$router->post('/api/inbox/{id}/vote', function (array $p) {
    $body = json_body();
    $optionIds = $body['optionIds'] ?? $body['options'] ?? [];
    if (!is_array($optionIds)) {
        $optionIds = [];
    }
    try {
        $item = InboxService::vote((string) $p['id'], $optionIds, Auth::user());
        json_response(['ok' => true, 'item' => $item]);
    } catch (Throwable $e) {
        json_response(['ok' => false, 'error' => $e->getMessage()], 400);
    }
});

$router->post('/api/inbox/{id}/react', function (array $p) {
    $body = json_body();
    $reaction = $body['reaction'] ?? null;
    if ($reaction === '' || $reaction === 'null') {
        $reaction = null;
    }
    try {
        $item = InboxService::react((string) $p['id'], $reaction !== null ? (string) $reaction : null, Auth::user());
        json_response(['ok' => true, 'item' => $item]);
    } catch (Throwable $e) {
        json_response(['ok' => false, 'error' => $e->getMessage()], 400);
    }
});

$router->post('/api/inbox/{id}/read', function (array $p) {
    InboxService::markRead((string) $p['id'], Auth::user());
    json_response(['ok' => true]);
});

$router->post('/api/inbox/read-all', function () {
    $n = InboxService::markAllRead(Auth::user());
    json_response(['ok' => true, 'count' => $n]);
});




$router->get('/admin/proxy-guard', function () {
    $user = Auth::user();
    if (!$user || !Auth::isStaff($user)) {
        redirect(url('/login'));
    }
    if (class_exists('ProxyGuard')) {
        ProxyGuard::bootSchema();
    }
    view('pages/admin/proxy-guard', [
        'adminUser' => $user,
        'stats' => class_exists('ProxyGuard') ? ProxyGuard::stats24h() : [],
        'blocks' => class_exists('ProxyGuard') ? ProxyGuard::listBlocks() : [],
        'suspects' => class_exists('ProxyGuard') ? ProxyGuard::topSuspects(40) : [],
        'events' => class_exists('ProxyGuard') ? ProxyGuard::recentEvents(150) : [],
        'bodyClass' => 'page-admin',
    ]);
});

$router->get('/api/admin/proxy-guard', function () {
    Auth::requireRole('admin', 'moderator');
    if (!class_exists('ProxyGuard')) {
        json_response(['ok' => false, 'error' => 'unavailable'], 500);
    }
    ProxyGuard::bootSchema();
    json_response([
        'ok' => true,
        'stats' => ProxyGuard::stats24h(),
        'blocks' => ProxyGuard::listBlocks(),
        'suspects' => ProxyGuard::topSuspects(40),
        'events' => ProxyGuard::recentEvents(100),
    ]);
});

$router->post('/api/admin/proxy-guard', function () {
    $actor = Auth::requireRole('admin', 'moderator');
    if (!class_exists('ProxyGuard')) {
        json_response(['ok' => false, 'error' => 'unavailable'], 500);
    }
    $body = json_body();
    $action = strtolower(trim((string) ($body['action'] ?? '')));
    $ip = trim((string) ($body['ip'] ?? ''));
    if ($action === 'block') {
        $hours = array_key_exists('hours', $body) ? $body['hours'] : 24;
        $hoursInt = ($hours === null || $hours === '') ? null : max(1, (int) $hours);
        $ok = ProxyGuard::blockIp($ip, (string) ($body['reason'] ?? 'manual'), $hoursInt, (string) ($actor['id'] ?? 'admin'));
        json_response(['ok' => $ok, 'error' => $ok ? null : 'Invalid IP']);
    }
    if ($action === 'unblock') {
        json_response(['ok' => ProxyGuard::unblockIp($ip)]);
    }
    json_response(['ok' => false, 'error' => 'Unknown action'], 400);
});

$router->get('/admin/storage', function () {
    $user = Auth::user();
    if (!$user || !Auth::isStaff($user)) {
        redirect('/home');
    }
    view('pages/admin/storage', [
        'adminUser' => $user,
        'disk' => SiteStorage::disk(),
        'items' => SiteStorage::inventory(),
        'seo' => ['title' => 'Storage | Admin', 'robots' => 'noindex'],
        'bodyClass' => 'page-admin',
    ]);
});

$router->get('/api/admin/storage', function () {
    Auth::requireRole('admin', 'moderator');
    json_response([
        'ok' => true,
        'disk' => SiteStorage::disk(),
        'items' => SiteStorage::inventory(),
    ]);
});

$router->map(['POST', 'DELETE'], '/api/admin/storage', function () {
    Auth::requireRole('admin', 'moderator');
    $body = json_decode(file_get_contents('php://input') ?: '{}', true);
    if (!is_array($body)) {
        $body = $_POST;
    }
    $id = trim((string) ($body['id'] ?? ''));
    if ($id === '') {
        json_response(['ok' => false, 'error' => 'id required'], 400);
    }
    $result = SiteStorage::clean($id);
    json_response($result, !empty($result['ok']) ? 200 : 400);
});

$router->get('/api/admin/storage/log', function () {
    Auth::requireRole('admin', 'moderator');
    $name = (string) ($_GET['name'] ?? '');
    $result = SiteStorage::readLog($name);
    json_response($result, !empty($result['ok']) ? 200 : 400);
});

$router->get('/n/{name}.js', function (array $params = []) {
    SiteAds::serveAntiAdblockNocache((string) ($params['name'] ?? ''));
});

$router->get('/admin/ads', function () {
    $user = Auth::user();
    if (!$user || !Auth::isStaff($user)) {
        redirect('/home');
    }
    view('pages/admin/ads', [
        'adminUser' => $user,
        'ads' => SiteAds::get(),
        'seo' => ['title' => 'Ads | Admin', 'robots' => 'noindex'],
        'bodyClass' => 'page-admin',
    ]);
});

$router->get('/api/admin/ads', function () {
    Auth::requireRole('admin', 'moderator');
    json_response(['ok' => true, 'ads' => SiteAds::get()]);
});



$router->map(['POST', 'PATCH'], '/api/admin/ads/antiadblock-schedule', function () {
    Auth::requireRole('admin');
    $body = json_decode(file_get_contents('php://input') ?: '{}', true);
    if (!is_array($body)) {
        $body = $_POST;
    }
    $minutes = (int) ($body['antiAdblockAutoRotateMinutes'] ?? $body['minutes'] ?? 0);
    if ($minutes <= 0) {
        $hours = (int) ($body['antiAdblockAutoRotateHours'] ?? $body['hours'] ?? 24);
        $minutes = max(1, $hours) * 60;
    }
    $minutes = max(10, min(10080, $minutes));
    $ads = SiteAds::save([
        'antiAdblockAutoRotate' => !empty($body['antiAdblockAutoRotate'] ?? $body['enabled']),
        'antiAdblockAutoRotateMinutes' => $minutes,
        'antiAdblockAutoRotateHours' => max(1, (int) round($minutes / 60)),
    ]);
    json_response([
        'ok' => true,
        'antiAdblockSrc' => $ads['antiAdblockSrc'] ?? null,
        'antiAdblockRotatedAt' => (int) ($ads['antiAdblockRotatedAt'] ?? 0),
        'antiAdblockAutoRotate' => !empty($ads['antiAdblockAutoRotate']),
        'antiAdblockAutoRotateHours' => (int) ($ads['antiAdblockAutoRotateHours'] ?? 24),
        'antiAdblockAutoRotateMinutes' => (int) ($ads['antiAdblockAutoRotateMinutes'] ?? $minutes),
    ]);
});

$router->map(['POST'], '/api/admin/ads/rotate-antiadblock', function () {
    Auth::requireRole('admin');
    $result = SiteAds::rotateAntiAdblockLib();
    json_response($result, !empty($result['ok']) ? 200 : 400);
});

$router->map(['PATCH', 'POST'], '/api/admin/ads', function () {
    Auth::requireRole('admin', 'moderator');
    $body = json_decode(file_get_contents('php://input') ?: '{}', true);
    if (!is_array($body)) {
        $body = $_POST;
    }
    $ads = SiteAds::save([
        'enabled' => !empty($body['enabled']),
        'banner' => !empty($body['banner']),
        'interstitial' => !empty($body['interstitial']),
        'videoSlider' => !empty($body['videoSlider']),
        'pop' => !empty($body['pop']),
        'popBypassUserOptOut' => !empty($body['popBypassUserOptOut']),
        'placements' => $body['placements'] ?? ['everywhere'],
    ]);
    json_response(['ok' => true, 'ads' => $ads]);
});

$router->get('/api/admin/ads/earnings', function () {
    Auth::requireRole('admin', 'moderator');
    $start = isset($_GET['start_date']) ? (string) $_GET['start_date'] : null;
    $end = isset($_GET['end_date']) ? (string) $_GET['end_date'] : null;
    $payload = AdCashReporting::dashboard($start, $end);
    json_response($payload, !empty($payload['ok']) ? 200 : 400);
});

$router->map(['POST', 'PATCH'], '/api/admin/ads/reporting-token', function () {
    Auth::requireRole('admin'); // token changes: admin only
    $body = json_decode(file_get_contents('php://input') ?: '{}', true);
    if (!is_array($body)) {
        $body = $_POST;
    }
    $token = trim((string) ($body['apiToken'] ?? $body['api_token'] ?? ''));
    if ($token === '') {
        json_response(['ok' => false, 'error' => 'apiToken required'], 400);
        return;
    }
    AdCashReporting::saveApiToken($token);
    json_response([
        'ok' => true,
        'token_masked' => AdCashReporting::maskedToken(),
        'configured' => AdCashReporting::hasToken(),
    ]);
});



$router->get('/admin/sources', function () {
    $user = Auth::user();
    if (!$user || !Auth::isStaff($user)) {
        redirect('/home');
    }
    view('pages/admin/sources', [
        'adminUser' => $user,
        'seo' => ['title' => 'Sources | Admin', 'robots' => 'noindex'],
        'bodyClass' => 'page-admin',
    ]);
});

// Admin APIs
$router->get('/api/admin/users', function () {
    Auth::requireRole('admin', 'moderator');
    $q = trim((string) ($_GET['q'] ?? ''));
    $pdo = Database::pdo();
    if ($q !== '') {
        $stmt = $pdo->prepare(
            'SELECT * FROM users
             WHERE email LIKE ? OR name LIKE ? OR id = ?
             ORDER BY created_at DESC LIMIT 200'
        );
        $like = '%' . $q . '%';
        $stmt->execute([$like, $like, $q]);
    } else {
        $stmt = $pdo->query('SELECT * FROM users ORDER BY created_at DESC LIMIT 200');
    }
    $users = array_map([Auth::class, 'publicUser'], $stmt->fetchAll() ?: []);
    json_response(['ok' => true, 'users' => $users]);
});

$router->get('/api/admin/users/{id}', function (array $p) {
    Auth::requireRole('admin', 'moderator');
    $stmt = Database::pdo()->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([(string) $p['id']]);
    $row = $stmt->fetch();
    if (!$row) {
        json_response(['ok' => false, 'error' => 'Not found'], 404);
    }
    $uid = (string) $row['id'];
    json_response([
        'ok' => true,
        'user' => Auth::publicUser($row),
        'favorites' => UserData::listFavorites($uid),
        'continueWatching' => UserData::listContinue($uid),
    ]);
});

$router->map(['PATCH', 'POST'], '/api/admin/users/{id}', function (array $p) {
    $actor = Auth::requireRole('admin', 'moderator');
    $body = json_body();
    $stmt = Database::pdo()->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([(string) $p['id']]);
    $row = $stmt->fetch();
    if (!$row) {
        json_response(['ok' => false, 'error' => 'Not found'], 404);
    }

    $fields = [];
    $vals = [];
    if (isset($body['name'])) {
        $fields[] = 'name = ?';
        $vals[] = substr(trim((string) $body['name']), 0, 120);
    }
    if (isset($body['email'])) {
        $email = strtolower(trim((string) $body['email']));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            json_response(['ok' => false, 'error' => 'Invalid email'], 400);
        }
        $fields[] = 'email = ?';
        $vals[] = $email;
    }
    if (isset($body['status']) && in_array($body['status'], ['active', 'suspended'], true)) {
        $fields[] = 'status = ?';
        $vals[] = $body['status'];
    }
    if (isset($body['role']) && in_array($body['role'], ['admin', 'moderator', 'user'], true)) {
        if (($actor['role'] ?? '') !== 'admin') {
            json_response(['ok' => false, 'error' => 'Only admin can change roles'], 403);
        }
        $fields[] = 'role = ?';
        $vals[] = $body['role'];
    }
    if (!empty($body['password'])) {
        if (strlen((string) $body['password']) < 6) {
            json_response(['ok' => false, 'error' => 'Password too short'], 400);
        }
        $fields[] = 'password_hash = ?';
        $vals[] = password_hash((string) $body['password'], PASSWORD_DEFAULT);
    }
    if (!$fields) {
        json_response(['ok' => true, 'user' => Auth::publicUser($row)]);
    }
    $fields[] = 'updated_at = ?';
    $vals[] = Auth::now();
    $vals[] = (string) $p['id'];
    Database::pdo()->prepare('UPDATE users SET ' . implode(', ', $fields) . ' WHERE id = ?')->execute($vals);
    $stmt->execute([(string) $p['id']]);
    json_response(['ok' => true, 'user' => Auth::publicUser($stmt->fetch() ?: $row)]);
});

$router->map(['DELETE', 'POST'], '/api/admin/users/{id}/delete', function (array $p) {
    $actor = Auth::requireRole('admin');
    if ((string) $actor['id'] === (string) $p['id']) {
        json_response(['ok' => false, 'error' => 'Cannot delete yourself'], 400);
    }
    Database::pdo()->prepare('DELETE FROM users WHERE id = ?')->execute([(string) $p['id']]);
    json_response(['ok' => true]);
});

$router->get('/api/admin/sources', function () {
    Auth::requireRole('admin', 'moderator');
    if (class_exists('SourcesService')) {
        SourcesService::ensureCatalogRows();
    }
    json_response(['ok' => true, 'sources' => SourcesService::all(), 'catalog' => SourcesService::CATALOG]);
});


$router->post('/api/admin/sources/reorder', function () {
    Auth::requireRole('admin', 'moderator');
    $body = json_body();
    $order = $body['order'] ?? [];
    if (!is_array($order)) {
        json_response(['ok' => false, 'error' => 'Invalid order'], 400);
    }
    SourcesService::reorder(array_map('strval', $order));
    json_response(['ok' => true, 'sources' => SourcesService::all()]);
});

$router->map(['PATCH', 'POST'], '/api/admin/sources/{id}', function (array $p) {
    Auth::requireRole('admin', 'moderator');
    $body = json_body();
    $id = (string) $p['id'];
    if (array_key_exists('enabled', $body)) {
        SourcesService::setEnabled($id, !empty($body['enabled']));
    }
    if (array_key_exists('autoLoad', $body)) {
        SourcesService::setAutoLoad($id, !empty($body['autoLoad']));
    }
    if (array_key_exists('autoloadNonEnglish', $body)) {
        SourcesService::setAutoloadNonEnglish($id, !empty($body['autoloadNonEnglish']));
    }
    if (array_key_exists('scrapeTimeoutSec', $body)) {
        SourcesService::setScrapeTimeout($id, (int) $body['scrapeTimeoutSec']);
    }
    if (array_key_exists('autoWaitSec', $body)) {
        SourcesService::setAutoWait($id, (int) $body['autoWaitSec']);
    }
    SourcesService::updateMeta(
        $id,
        isset($body['publicLabel']) ? (string) $body['publicLabel'] : null,
        isset($body['notes']) ? (string) $body['notes'] : null,
        isset($body['name']) ? (string) $body['name'] : null
    );
    json_response(['ok' => true, 'sources' => SourcesService::all()]);
});

$router->post('/api/admin/sources/{id}/test', function (array $p) {
    $actor = Auth::requireRole('admin', 'moderator');
    $body = json_body();
    $type = (($body['type'] ?? '') === 'tv') ? 'tv' : 'movie';
    $tmdbId = (int) ($body['tmdbId'] ?? 0);
    $season = max(1, (int) ($body['season'] ?? 1));
    $episode = max(1, (int) ($body['episode'] ?? 1));
    if ($tmdbId < 1) {
        json_response(['ok' => false, 'error' => 'tmdbId required'], 400);
    }
    $result = SourcesService::test((string) $p['id'], $type, $tmdbId, $season, $episode, (string) $actor['id']);
    json_response(['ok' => true] + $result);
});

$router->get('/api/admin/worker-relay', function () {
    Auth::requireRole('admin');
    $download = (string) ($_GET['download'] ?? '');
    if ($download === 'script' || $download === '1' || $download === 'view') {
        try {
            $script = WorkerRelayService::readScript();
        } catch (Throwable $e) {
            json_response(['ok' => false, 'success' => false, 'error' => $e->getMessage()], 500);
        }
        // Open as a plain text page (no download) so admins can Ctrl+A / paste into CF.
        header('Content-Type: text/plain; charset=utf-8');
        header('Content-Disposition: inline; filename="yoru-relay.js"');
        header('Cache-Control: no-store');
        header('X-Worker-Script-Path: ' . $script['path']);
        http_response_code(200);
        echo $script['content'];
        exit;
    }
    json_response(['ok' => true, 'success' => true, 'config' => WorkerRelayService::publicConfig()]);
});

$router->map(['PATCH', 'POST'], '/api/admin/worker-relay', function () {
    Auth::requireRole('admin');
    $body = json_body();
    if (($body['action'] ?? '') === 'test') {
        $config = WorkerRelayService::get();
        $url = trim((string) ($body['url'] ?? ''));
        $secret = trim((string) ($body['secret'] ?? ''));
        $id = trim((string) ($body['id'] ?? ''));
        if ($id !== '') {
            foreach ($config['workers'] as $w) {
                if ($w['id'] === $id) {
                    if ($url === '') {
                        $url = $w['url'];
                    }
                    if ($secret === '' || str_contains($secret, '…')) {
                        $secret = $w['secret'];
                    }
                    break;
                }
            }
        }
        $result = WorkerRelayService::test($url, $secret);
        json_response(['ok' => true, 'success' => true, 'result' => $result]);
    }

    if (($body['action'] ?? '') === 'stats') {
        $id = trim((string) ($body['id'] ?? ''));
        $force = !empty($body['force']);
        $stats = WorkerRelayService::analytics($id !== '' ? $id : null, $force);
        json_response(['ok' => true, 'success' => true, 'stats' => $stats]);
    }

    try {
        WorkerRelayService::update($body);
        $restart = WorkerRelayService::bounceCinepro();
        json_response([
            'ok' => true,
            'success' => true,
            'config' => WorkerRelayService::publicConfig(),
            'restart' => $restart,
        ]);
    } catch (Throwable $e) {
        json_response([
            'ok' => false,
            'success' => false,
            'error' => $e->getMessage(),
        ], 500);
    }
});

$router->get('/api/admin/stats', function () {
    Auth::requireRole('admin', 'moderator');
    $pdo = Database::pdo();
    $stats = [
        'users' => (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn(),
        'admins' => (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn(),
        'moderators' => (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'moderator'")->fetchColumn(),
        'favorites' => (int) $pdo->query('SELECT COUNT(*) FROM favorites')->fetchColumn(),
        'continue' => (int) $pdo->query('SELECT COUNT(*) FROM continue_watching')->fetchColumn(),
        'sourcesEnabled' => (int) $pdo->query('SELECT COUNT(*) FROM sources WHERE enabled = 1')->fetchColumn(),
    ];
    json_response(['ok' => true, 'stats' => $stats]);
});


$router->map(['POST'], '/api/party', function () {
    $body = WatchParty::readJsonBody();
    if (!empty(config('turnstile_secret_key')) && !Auth::verifyTurnstile($body['turnstileToken'] ?? null)) {
        json_response(['ok' => false, 'error' => 'Captcha verification failed.'], 400);
    }
    json_response(WatchParty::create($body), 200);
});
$router->get('/api/party/{code}', function (array $p) {
    json_response(WatchParty::get((string) $p['code']));
});
$router->map(['POST'], '/api/party/{code}', function (array $p) {
    $body = WatchParty::readJsonBody();
    json_response(WatchParty::update((string) $p['code'], $body));
});
$router->map(['POST'], '/api/party/{code}/join', function (array $p) {
    $body = WatchParty::readJsonBody();
    if (!empty(config('turnstile_secret_key')) && !Auth::verifyTurnstile($body['turnstileToken'] ?? null)) {
        json_response(['ok' => false, 'error' => 'Captcha verification failed.'], 400);
    }
    json_response(WatchParty::join((string) $p['code'], $body));
});
$router->map(['POST'], '/api/party/{code}/leave', function (array $p) {
    $body = WatchParty::readJsonBody();
    json_response(WatchParty::leave((string) $p['code'], $body));
});
$router->map(['POST'], '/api/party/{code}/close', function (array $p) {
    $body = WatchParty::readJsonBody();
    json_response(WatchParty::close((string) $p['code'], $body));
});

$router->get('/api/party/{code}/chat', function (array $p) {
    json_response(WatchParty::chatState((string) $p['code'], $_GET));
});
$router->map(['POST'], '/api/party/{code}/chat', function (array $p) {
    $body = WatchParty::readJsonBody();
    json_response(WatchParty::chatPost((string) $p['code'], $body));
});
$router->map(['POST'], '/api/party/{code}/chat/lock', function (array $p) {
    $body = WatchParty::readJsonBody();
    json_response(WatchParty::chatLock((string) $p['code'], $body));
});
$router->map(['POST'], '/api/party/{code}/chat/ban', function (array $p) {
    $body = WatchParty::readJsonBody();
    json_response(WatchParty::chatBan((string) $p['code'], $body));
});

$router->get('/movie/{slug}/{id}', function (array $p) use ($tmdb) {
    watch_page($tmdb, 'movie', (int) $p['id'], (string) $p['slug']);
});

$router->get('/tv/{slug}/{id}', function (array $p) use ($tmdb) {
    watch_page($tmdb, 'tv', (int) $p['id'], (string) $p['slug']);
});

$router->get('/api/person/{id}', function (array $p) use ($tmdb) {
    $id = (int) ($p['id'] ?? 0);
    $data = build_person_payload($tmdb, $id);
    if (!$data) {
        json_response(['ok' => false, 'error' => 'not_found'], 404);
    }
    json_response(['ok' => true, 'person' => $data]);
});

$router->get('/person/{slug}/{id}', function (array $p) use ($tmdb) {
    person_page($tmdb, (int) $p['id'], (string) $p['slug']);
});

$router->get('/api/search', function () use ($tmdb) {
    $q = trim((string) ($_GET['q'] ?? ''));
    if (mb_strlen($q) < 2) {
        json_response(['results' => []]);
    }
    $filters = newsite_parse_search_filters();
    // Pull more pages so genre/year filters still have matches
    $raw = [];
    $maxPages = 5;
    for ($p = 1; $p <= $maxPages; $p++) {
        $data = $tmdb->search($q, $p);
        foreach ($data['results'] ?? [] as $r) {
            $raw[] = $r;
        }
        if ((int) ($data['total_pages'] ?? 1) <= $p) break;
    }
    $raw = newsite_filter_search_results($raw, $filters);
    $movies = [];
    $shows = [];
    foreach ($raw as $r) {
        $type = ($r['media_type'] ?? '') === 'tv' ? 'tv' : 'movie';
        $title = title_of($r);
        $row = [
            'id' => $r['id'],
            'type' => $type,
            'title' => $title,
            'year' => year_of(date_of($r)),
            'poster' => img_url($r['poster_path'] ?? null, 'w185'),
            'url' => media_url($type, $r['id'], $title),
            'rating' => isset($r['vote_average']) ? round((float) $r['vote_average'], 1) : null,
            'genre_ids' => array_values(array_map('intval', $r['genre_ids'] ?? [])),
        ];
        if ($type === 'tv') $shows[] = $row;
        else $movies[] = $row;
    }
    $out = [];
    if ($filters['type'] === 'movie') {
        $out = array_slice($movies, 0, 12);
    } elseif ($filters['type'] === 'tv') {
        $out = array_slice($shows, 0, 12);
    } else {
        $mi = 0; $si = 0;
        while (count($out) < 12 && ($mi < count($movies) || $si < count($shows))) {
            if ($mi < count($movies)) $out[] = $movies[$mi++];
            if (count($out) >= 12) break;
            if ($si < count($shows)) $out[] = $shows[$si++];
        }
    }
    json_response(['results' => $out, 'filters' => $filters]);
});


$router->get('/api/media/batch', function () use ($tmdb) {
    // Lightweight title/poster hydrate for Continue Watching & similar rails.
    // ?ids=tv:94997,movie:123  (max 10)
    $raw = trim((string) ($_GET['ids'] ?? ''));
    if ($raw === '') {
        json_response(['ok' => true, 'lang' => class_exists('Locale') ? Locale::current() : 'en', 'items' => []]);
    }
    $parts = preg_split('/\s*,\s*/', $raw) ?: [];
    $items = [];
    $seen = [];
    foreach ($parts as $part) {
        if (count($items) >= 10) {
            break;
        }
        if (!preg_match('/^(tv|movie):(\d+)$/', strtolower((string) $part), $m)) {
            continue;
        }
        $type = $m[1];
        $id = (int) $m[2];
        $key = $type . ':' . $id;
        if (isset($seen[$key]) || $id < 1) {
            continue;
        }
        $seen[$key] = true;
        $item = $tmdb->get("{$type}/{$id}");
        if (!is_array($item)) {
            continue;
        }
        $items[] = [
            'type' => $type,
            'id' => $id,
            'title' => title_of($item),
            'poster' => img_url($item['poster_path'] ?? null, 'w500'),
            'backdrop' => img_url($item['backdrop_path'] ?? null, 'w780'),
            'year' => year_of(date_of($item)),
        ];
    }
    json_response([
        'ok' => true,
        'lang' => class_exists('Locale') ? Locale::current() : (string) config('lang', 'en'),
        'items' => $items,
    ]);
});



if (!function_exists('cf_resolve_youtube_preview_stream')) {
    function cf_resolve_youtube_preview_stream(string $videoId, bool $quick = false): ?string
    {
        $videoId = preg_replace('/[^a-zA-Z0-9_-]/', '', $videoId) ?? '';
        if ($videoId === '' || strlen($videoId) < 6 || strlen($videoId) > 20) {
            return null;
        }

        // 1) yt-dlp → clean MP4 (no YouTube player chrome)
        $ytDlp = trim((string) shell_exec('command -v yt-dlp 2>/dev/null'));
        if ($ytDlp !== '') {
            $watch = 'https://www.youtube.com/watch?v=' . $videoId;
            $wait = $quick ? 6 : 8;
            // android client unlocks many studio trailers that default web client marks unavailable
            $cmd = 'timeout ' . $wait . ' ' . escapeshellarg($ytDlp)
                . ' -f ' . escapeshellarg('best[height<=480][ext=mp4]/best[height<=480]/best[ext=mp4]/best')
                . ' --extractor-args ' . escapeshellarg('youtube:player_client=android')
                . ' -g --no-warnings --no-playlist '
                . escapeshellarg($watch) . ' 2>/dev/null';
            $lines = [];
            $code = 1;
            @exec($cmd, $lines, $code);
            if ($code === 0 && !empty($lines)) {
                foreach ($lines as $line) {
                    $line = trim((string) $line);
                    if ($line !== '' && preg_match('#^https?://#i', $line)) {
                        return $line;
                    }
                }
            }
        }

        // Piped disabled: often returns unusable proxy URLs for unavailable YouTube videos
        return null;
        if ($quick) {
            return null;
        }
        $instances = [
            'https://api.piped.private.coffee',
            'https://pipedapi.adminforge.de',
        ];
        foreach ($instances as $base) {
            $url = rtrim($base, '/') . '/streams/' . rawurlencode($videoId);
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_CONNECTTIMEOUT => 3,
                CURLOPT_TIMEOUT => 8,
                CURLOPT_HTTPHEADER => ['Accept: application/json', 'User-Agent: VuflixPreview/1.1'],
            ]);
            $body = curl_exec($ch);
            $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($http < 200 || $http >= 300 || !is_string($body) || $body === '') {
                continue;
            }
            $data = json_decode($body, true);
            if (!is_array($data)) {
                continue;
            }
            foreach (($data['videoStreams'] ?? []) as $s) {
                if (!is_array($s) || empty($s['url']) || !empty($s['videoOnly'])) {
                    continue;
                }
                $mime = (string) ($s['mimeType'] ?? '');
                if (strpos($mime, 'video/') === 0) {
                    return (string) $s['url'];
                }
            }
        }
        return null;
    }
}

$router->get('/api/tip/{type}/{id}', function (array $p) use ($tmdb) {
    $type = ($p['type'] ?? '') === 'tv' ? 'tv' : 'movie';
    $id = (int) ($p['id'] ?? 0);
    if ($id < 1) {
        json_response(['error' => 'invalid'], 400);
    }
    $item = $tmdb->details($type, $id);
    if (!$item) {
        json_response(['error' => 'not_found'], 404);
    }
    $title = title_of($item);
    $year = year_of(date_of($item));
    $rating = isset($item['vote_average']) ? round((float) $item['vote_average'], 1) : null;
    $votes = (int) ($item['vote_count'] ?? 0);
    $genres = array_values(array_filter(array_map(static fn ($g) => $g['name'] ?? '', $item['genres'] ?? [])));
    $countries = array_values(array_filter(array_map(static fn ($c) => $c['name'] ?? '', $item['production_countries'] ?? [])));

    $runtime = '';
    if ($type === 'movie' && !empty($item['runtime'])) {
        $h = intdiv((int) $item['runtime'], 60);
        $m = (int) $item['runtime'] % 60;
        $runtime = $h > 0 ? ($h . 'h ' . $m . 'min') : ($m . 'min');
    } elseif ($type === 'tv' && !empty($item['number_of_seasons'])) {
        $runtime = (int) $item['number_of_seasons'] . ' Seasons';
    }

    $cert = $type === 'tv' ? 'TV' : 'Movie';
    if ($type === 'movie') {
        foreach ($item['release_dates']['results'] ?? [] as $rd) {
            if (($rd['iso_3166_1'] ?? '') !== 'US') {
                continue;
            }
            foreach ($rd['release_dates'] ?? [] as $entry) {
                if (!empty($entry['certification'])) {
                    $cert = $entry['certification'];
                    break 2;
                }
            }
        }
    } else {
        foreach ($item['content_ratings']['results'] ?? [] as $cr) {
            if (($cr['iso_3166_1'] ?? '') === 'US' && !empty($cr['rating'])) {
                $cert = $cr['rating'];
                break;
            }
        }
    }

    $votesLabel = $votes >= 1000 ? (round($votes / 1000, 1) . 'k') : (string) $votes;

    $trailer = $tmdb->trailerKey($item);

    json_response([
        'id' => $id,
        'type' => $type,
        'title' => $title,
        'year' => $year,
        'rating' => $rating,
        'votes' => $votes,
        'votes_label' => $votesLabel,
        'cert' => $cert,
        'runtime' => $runtime,
        'country' => implode(', ', $countries),
        'genre' => implode(', ', $genres),
        'overview' => truncate((string) ($item['overview'] ?? ''), 220),
        'url' => media_url($type, $id, $title),
        'poster' => img_url($item['poster_path'] ?? null, 'w185'),
        'backdrop' => img_url($item['backdrop_path'] ?? $item['poster_path'] ?? null, 'w780'),
        'trailer' => $trailer,
    ]);
});


$router->get('/api/preview/{type}/{id}', function (array $p) use ($tmdb) {
    $type = ($p['type'] ?? '') === 'tv' ? 'tv' : 'movie';
    $id = (int) ($p['id'] ?? 0);
    if ($id < 1) {
        json_response(['error' => 'invalid'], 400);
    }

    $cacheDir = ('/var/www/chillflix-newsite/storage/cache/previews');
    if (!is_dir($cacheDir)) {
        @mkdir($cacheDir, 0775, true);
    }
    $cacheFile = $cacheDir . '/' . $type . '_' . $id . '.json';
    if (is_file($cacheFile)) {
        $cached = json_decode((string) file_get_contents($cacheFile), true);
        $age = time() - filemtime($cacheFile);
        if (is_array($cached) && !empty($cached['trailer'])) {
            if (!empty($cached['stream']) && $age < 1500) {
                json_response($cached);
            }
            if (empty($cached['stream']) && $age < 90) {
                json_response($cached);
            }
        }
    }

    $item = $tmdb->details($type, $id);
    if (!$item) {
        json_response(['error' => 'not_found'], 404);
    }

    $trailerKeys = array_slice($tmdb->trailerKeys($item), 0, 6);
    $trailer = $trailerKeys[0] ?? null;
    $stream = null;
    foreach ($trailerKeys as $i => $tryKey) {
        $resolved = cf_resolve_youtube_preview_stream($tryKey, $i > 0);
        if (is_string($resolved) && $resolved !== '') {
            $trailer = $tryKey;
            $stream = $resolved;
            break;
        }
    }

    $logoUrl = null;
    try {
        $logoLangs = class_exists('Locale') ? Locale::logoImageLanguages() : 'en,null';
        $imgs = $tmdb->get($type . '/' . $id . '/images', [
            'include_image_language' => $logoLangs,
        ]);
        $logoPath = $tmdb->pickPreferredLogo(
            is_array($imgs) ? ($imgs['logos'] ?? null) : null,
            class_exists('Locale') ? Locale::iso639() : 'en'
        );
        if (is_string($logoPath) && $logoPath !== '') {
            $logoUrl = img_url($logoPath, 'w500');
        }
    } catch (Throwable $e) {
        $logoUrl = null;
    }

    $payload = [
        'id' => $id,
        'type' => $type,
        'title' => title_of($item),
        'year' => year_of(date_of($item)),
        'rating' => isset($item['vote_average']) ? round((float) $item['vote_average'], 1) : null,
        'overview' => truncate((string) ($item['overview'] ?? ''), 140),
        'url' => media_url($type, $id, title_of($item)),
        'poster' => img_url($item['poster_path'] ?? null, 'w500'),
        'backdrop' => img_url($item['backdrop_path'] ?? $item['poster_path'] ?? null, 'w780'),
        'logo' => $logoUrl,
        'trailer' => $trailer,
        // Same-origin proxy — raw googlevideo URLs are IP-bound to the server
        'stream' => $stream ? url('/api/preview-media/' . $type . '/' . $id) : null,
        'has_stream' => (bool) $stream,
    ];
    // Keep raw upstream URL server-side for the proxy
    if ($stream) {
        @file_put_contents($cacheDir . '/' . $type . '_' . $id . '.upstream', $stream);
    }
    // Only cache successful stream lookups long-term; short-cache misses
    $ttlOk = !empty($payload['stream']);
    if ($ttlOk || !is_file($cacheFile) || (time() - filemtime($cacheFile)) > 120) {
        @file_put_contents($cacheFile, json_encode($payload));
    }
    json_response($payload);
});


$router->get('/api/preview-media/{type}/{id}', function (array $p) use ($tmdb) {
    $type = ($p['type'] ?? '') === 'tv' ? 'tv' : 'movie';
    $id = (int) ($p['id'] ?? 0);
    if ($id < 1) {
        http_response_code(400);
        exit;
    }

    // Drop any prior output so we can stream clean binary
    while (ob_get_level() > 0) {
        @ob_end_clean();
    }

    $cacheDir = '/var/www/chillflix-newsite/storage/cache/previews';
    if (!is_dir($cacheDir)) {
        @mkdir($cacheDir, 0775, true);
    }
    $upFile = $cacheDir . '/' . $type . '_' . $id . '.upstream';

    $item = $tmdb->details($type, $id);
    $trailerKeys = $item ? array_slice($tmdb->trailerKeys($item), 0, 6) : [];
    $trailer = $trailerKeys[0] ?? null;
    $stream = null;
    // Always resolve fresh — googlevideo URLs are IP/time bound; try alt keys if primary dead
    foreach ($trailerKeys as $i => $tryKey) {
        $resolved = cf_resolve_youtube_preview_stream($tryKey, $i > 0);
        if (is_string($resolved) && $resolved !== '') {
            $trailer = $tryKey;
            $stream = $resolved;
            @file_put_contents($upFile, $stream);
            break;
        }
    }
    if (!$stream && is_file($upFile)) {
        $stream = trim((string) file_get_contents($upFile));
    }
    if (!$stream || !preg_match('#^https?://#i', $stream)) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        exit('not found');
    }

    $ua = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36';
    $headers = [
        'User-Agent: ' . $ua,
        'Accept: */*',
        'Accept-Language: en-US,en;q=0.9',
        'Origin: https://www.youtube.com',
        'Referer: https://www.youtube.com/',
    ];
    $range = $_SERVER['HTTP_RANGE'] ?? '';
    if (is_string($range) && $range !== '') {
        $headers[] = 'Range: ' . $range;
    }

    $statusCode = 200;
    $ch = curl_init($stream);
    curl_setopt_array($ch, [
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => 0,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_HEADERFUNCTION => static function ($ch, $header) use (&$statusCode) {
            $len = strlen($header);
            if (preg_match('#^HTTP/\S+\s+(\d+)#', $header, $m)) {
                $statusCode = (int) $m[1];
                return $len;
            }
            $h = trim($header);
            if ($h === '' || stripos($h, 'Transfer-Encoding:') === 0 || stripos($h, 'HTTP/') === 0) {
                return $len;
            }
            foreach (['content-type', 'content-length', 'accept-ranges', 'content-range'] as $p) {
                if (stripos($h, $p . ':') === 0) {
                    header($h, true);
                    break;
                }
            }
            return $len;
        },
        CURLOPT_WRITEFUNCTION => static function ($ch, $data) {
            echo $data;
            return strlen($data);
        },
    ]);

    header('Cache-Control: private, max-age=30');
    header('X-Content-Type-Options: nosniff');
    header('Content-Type: video/mp4');

    // If upstream rejects stale URL, refresh once via yt-dlp and retry
    $ok = curl_exec($ch);
    $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if ((!$ok || $http >= 400 || $statusCode >= 400) && is_string($trailer) && $trailer !== '') {
        curl_close($ch);
        $stream2 = cf_resolve_youtube_preview_stream($trailer);
        if ($stream2 && $stream2 !== $stream) {
            @file_put_contents($upFile, $stream2);
            $statusCode = 200;
            $ch = curl_init($stream2);
            curl_setopt_array($ch, [
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_CONNECTTIMEOUT => 8,
                CURLOPT_TIMEOUT => 0,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_HEADERFUNCTION => static function ($ch, $header) use (&$statusCode) {
                    $len = strlen($header);
                    if (preg_match('#^HTTP/\S+\s+(\d+)#', $header, $m)) {
                        $statusCode = (int) $m[1];
                        return $len;
                    }
                    $h = trim($header);
                    if ($h === '' || stripos($h, 'Transfer-Encoding:') === 0 || stripos($h, 'HTTP/') === 0) {
                        return $len;
                    }
                    foreach (['content-type', 'content-length', 'accept-ranges', 'content-range'] as $p) {
                        if (stripos($h, $p . ':') === 0) {
                            header($h, true);
                            break;
                        }
                    }
                    return $len;
                },
                CURLOPT_WRITEFUNCTION => static function ($ch, $data) {
                    echo $data;
                    return strlen($data);
                },
            ]);
            $ok = curl_exec($ch);
            $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        }
    }

    if (is_resource($ch) || (is_object($ch) && $ch instanceof CurlHandle)) {
        $err = curl_error($ch);
        curl_close($ch);
    } else {
        $err = 'upstream failed';
    }
    if (!$ok && !headers_sent()) {
        http_response_code(502);
        header('Content-Type: text/plain; charset=utf-8');
        echo $err !== '' ? $err : 'upstream failed';
    }
    exit;
});

$router->get('/robots.txt', function () {
    header('Content-Type: text/plain; charset=utf-8');
    echo "User-agent: *\nAllow: /\nDisallow: /api/\nSitemap: " . url('/sitemap.xml') . "\n";
    exit;
});

$router->get('/sitemap.xml', function () use ($tmdb) {
    header('Content-Type: application/xml; charset=utf-8');
    $urls = [
        url('/'),
        url('/movies'),
        url('/tv-series'),
        url('/anime'),
        url('/top-imdb'),
        url('/contact'),
    ];
    foreach (array_slice($tmdb->trending('movie', 'week'), 0, 20) as $m) {
        $urls[] = media_url('movie', $m['id'], title_of($m));
    }
    foreach (array_slice($tmdb->trending('tv', 'week'), 0, 20) as $m) {
        $urls[] = media_url('tv', $m['id'], title_of($m));
    }
    echo '<?xml version="1.0" encoding="UTF-8"?>';
    echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
    foreach ($urls as $u) {
        echo '<url><loc>' . e($u) . '</loc><changefreq>daily</changefreq><priority>0.8</priority></url>';
    }
    echo '</urlset>';
    exit;
});

function home(Tmdb $tmdb): void
{
    $trendingMoviesDay = $tmdb->trending('movie', 'day');
    $trendingMoviesWeek = $tmdb->trending('movie', 'week');
    // Hero badges (#N Today / HOT / Newly launched) use daily trending + TMDB logos.
    $featured = $tmdb->enrichHeroSlides($trendingMoviesDay ?: $trendingMoviesWeek, 5);
    $top10Movies = array_slice($trendingMoviesWeek, 0, 10);
    $top10Tv = array_slice($tmdb->trending('tv', 'week'), 0, 10);
    $recommendedMovies = $tmdb->discover('movie', [
        'sort_by' => 'popularity.desc',
        'vote_count.gte' => 50,
        'vote_average.gte' => 6,
        'page' => 1,
    ])['results'] ?? [];
    $recommendedTv = $tmdb->discover('tv', [
        'sort_by' => 'popularity.desc',
        'vote_count.gte' => 50,
        'vote_average.gte' => 6,
        'page' => 1,
    ])['results'] ?? [];
    $latestMovies = $tmdb->discover('movie', [
        'sort_by' => 'primary_release_date.desc',
        'vote_count.gte' => 20,
        'page' => 1,
    ])['results'] ?? [];
    $latestTv = $tmdb->discover('tv', [
        'sort_by' => 'first_air_date.desc',
        'vote_count.gte' => 20,
        'page' => 1,
    ])['results'] ?? [];
    $mostCommentedDay = array_slice($tmdb->trending('all', 'day'), 0, 16);
    $mostCommentedWeek = array_slice($tmdb->trending('all', 'week'), 0, 16);
    // TMDB has no "month" trending window — approximate with popular titles from the last 30 days
    $monthFrom = date('Y-m-d', strtotime('-30 days'));
    $monthMovies = $tmdb->discover('movie', [
        'sort_by' => 'popularity.desc',
        'vote_count.gte' => 40,
        'primary_release_date.gte' => $monthFrom,
        'page' => 1,
    ])['results'] ?? [];
    $monthTv = $tmdb->discover('tv', [
        'sort_by' => 'popularity.desc',
        'vote_count.gte' => 40,
        'first_air_date.gte' => $monthFrom,
        'page' => 1,
    ])['results'] ?? [];
    foreach ($monthMovies as &$mm) {
        $mm['media_type'] = 'movie';
    }
    unset($mm);
    foreach ($monthTv as &$mt) {
        $mt['media_type'] = 'tv';
    }
    unset($mt);
    $mostCommentedMonth = array_merge($monthMovies, $monthTv);
    usort($mostCommentedMonth, static function ($a, $b) {
        return ((float) ($b['popularity'] ?? 0)) <=> ((float) ($a['popularity'] ?? 0));
    });
    $mostCommentedMonth = array_slice($mostCommentedMonth, 0, 16);
    $mostCommented = $mostCommentedDay;
    $recentlyUpdated = array_slice($mostCommentedWeek, 0, 12);
    $latestEpisodes = $tmdb->latestEpisodes(12);

    view('pages/home', [
        'featured' => $featured,
        'top10Movies' => $top10Movies,
        'top10Tv' => $top10Tv,
        'recommendedMovies' => array_slice($recommendedMovies, 0, 12),
        'recommendedTv' => array_slice($recommendedTv, 0, 12),
        'latestMovies' => array_slice($latestMovies, 0, 12),
        'latestTv' => array_slice($latestTv, 0, 12),
        'latestEpisodes' => $latestEpisodes,
        'mostCommented' => $mostCommented,
        'mostCommentedDay' => $mostCommentedDay,
        'mostCommentedWeek' => $mostCommentedWeek,
        'mostCommentedMonth' => $mostCommentedMonth,
        'recentlyUpdated' => $recentlyUpdated,
        'bodyClass' => 'home',
        'seo' => [
            'title' => 'Home - Explore Thousands of Movies & Series | ' . config('site_name'),
            'description' => 'Start exploring our vast library of movies and TV series. Find new releases, popular titles, and browse by genre on ' . config('site_name') . '.',
            'canonical' => url('/'),
            'image' => logo_url(),
            'schema' => 'home',
        ],
    ]);
}

function discover_page(Tmdb $tmdb, string $type, bool $filtersPage = false, array $options = []): void
{
    $page = max(1, (int) ($_GET['page'] ?? 1));
    $sort = (string) ($_GET['sort_by'] ?? 'popularity.desc');
    $year = trim((string) ($_GET['year'] ?? ''));
    $yearFrom = trim((string) ($_GET['year_from'] ?? ''));
    $yearTo = trim((string) ($_GET['year_to'] ?? ''));
    $genresIn = $_GET['with_genres'] ?? [];
    if (!is_array($genresIn)) {
        $genresIn = $genresIn !== '' && $genresIn !== null ? [(int) $genresIn] : [];
    }
    $genresIn = array_values(array_filter(array_map('intval', $genresIn)));
    $genre = $genresIn[0] ?? 0;

    $parseList = static function ($raw): array {
        if (is_array($raw)) {
            return array_values(array_filter(array_map(static fn ($v) => trim((string) $v), $raw), static fn ($v) => $v !== ''));
        }
        $s = trim((string) ($raw ?? ''));
        if ($s === '') {
            return [];
        }
        if (str_contains($s, '|')) {
            return array_values(array_filter(array_map('trim', explode('|', $s))));
        }
        if (str_contains($s, ',')) {
            return array_values(array_filter(array_map('trim', explode(',', $s))));
        }
        return [$s];
    };

    $countriesIn = isset($_GET['with_origin_country'])
        ? $parseList($_GET['with_origin_country'])
        : [];
    $countriesOut = isset($_GET['without_origin_country'])
        ? $parseList($_GET['without_origin_country'])
        : [];
    $providersIn = isset($_GET['with_watch_providers'])
        ? $parseList($_GET['with_watch_providers'])
        : [];
    $providersOut = isset($_GET['without_watch_providers'])
        ? $parseList($_GET['without_watch_providers'])
        : [];
    $networksIn = isset($_GET['with_networks'])
        ? $parseList($_GET['with_networks'])
        : [];
    $networksOut = isset($_GET['without_networks'])
        ? $parseList($_GET['without_networks'])
        : [];

    $genresOut = $_GET['without_genres'] ?? [];
    if (!is_array($genresOut)) {
        $genresOut = $genresOut !== '' && $genresOut !== null ? [(int) $genresOut] : [];
    }
    $genresOut = array_values(array_filter(array_map('intval', $genresOut)));
    // Avoid include+exclude clash on same id
    if ($genresIn && $genresOut) {
        $genresIn = array_values(array_diff($genresIn, $genresOut));
    }
    if ($providersIn && $providersOut) {
        $providersIn = array_values(array_diff($providersIn, $providersOut));
    }
    if ($networksIn && $networksOut) {
        $networksIn = array_values(array_diff($networksIn, $networksOut));
    }
    if ($countriesIn && $countriesOut) {
        $countriesIn = array_values(array_diff($countriesIn, $countriesOut));
    }

    $ratingFrom = trim((string) ($_GET['rating_from'] ?? ''));
    $ratingTo = trim((string) ($_GET['rating_to'] ?? ''));
    $ratingMinLegacy = trim((string) ($_GET['vote_average_gte'] ?? $_GET['vote_average.gte'] ?? $_GET['rating'] ?? ''));
    $ratingMaxLegacy = trim((string) ($_GET['vote_average_lte'] ?? $_GET['vote_average.lte'] ?? ''));
    if ($ratingFrom === '' && $ratingMinLegacy !== '') {
        $ratingFrom = $ratingMinLegacy;
    }
    if ($ratingTo === '' && $ratingMaxLegacy !== '') {
        $ratingTo = $ratingMaxLegacy;
    }
    if ($ratingFrom !== '' && !is_numeric($ratingFrom)) {
        $ratingFrom = '';
    }
    if ($ratingTo !== '' && !is_numeric($ratingTo)) {
        $ratingTo = '';
    }
    if ($ratingFrom !== '' && $ratingTo !== '' && (float) $ratingFrom > (float) $ratingTo) {
        [$ratingFrom, $ratingTo] = [$ratingTo, $ratingFrom];
    }
    $genreMode = (string) ($_GET['genre_mode'] ?? '');

    $animeMode = !empty($options['animeMode']);
    $pageLabel = isset($options['label']) ? (string) $options['label'] : '';
    $pagePath = isset($options['path']) ? (string) $options['path'] : '';
    $forceGenres = array_values(array_filter(array_map('intval', (array) ($options['forceGenres'] ?? []))));
    $forceOriginalLanguage = trim((string) ($options['forceOriginalLanguage'] ?? ''));
    $voteCountGte = isset($options['voteCountGte']) ? (int) $options['voteCountGte'] : 0;
    if ($animeMode && $forceGenres) {
        foreach ($forceGenres as $gid) {
            if (!in_array($gid, $genresIn, true)) {
                $genresIn[] = $gid;
            }
            $genresOut = array_values(array_diff($genresOut, [$gid]));
        }
        $genre = $genresIn[0] ?? $genre;
    }


    // Legacy single year → treat as from=to
    if ($yearFrom === '' && $yearTo === '' && $year !== '' && preg_match('/^\d{4}$/', $year)) {
        $yearFrom = $year;
        $yearTo = $year;
    }
    if ($yearFrom !== '' && !preg_match('/^\d{4}$/', $yearFrom)) {
        $yearFrom = '';
    }
    if ($yearTo !== '' && !preg_match('/^\d{4}$/', $yearTo)) {
        $yearTo = '';
    }
    if ($yearFrom !== '' && $yearTo !== '' && (int) $yearFrom > (int) $yearTo) {
        [$yearFrom, $yearTo] = [$yearTo, $yearFrom];
    }

    $params = [
        'page' => $page,
        'sort_by' => $sort,
        'include_adult' => 'false',
    ];
    if ($genresIn) {
        // TMDB: pipe = OR (any selected genre)
        $params['with_genres'] = implode('|', $genresIn);
    }
    if ($genresOut) {
        $params['without_genres'] = implode(',', $genresOut);
    }

    if ($yearFrom !== '' || $yearTo !== '') {
        $gte = ($yearFrom !== '' ? $yearFrom : '1900') . '-01-01';
        $lte = ($yearTo !== '' ? $yearTo : (string) ((int) date('Y') + 1)) . '-12-31';
        if ($type === 'tv') {
            $params['first_air_date.gte'] = $gte;
            $params['first_air_date.lte'] = $lte;
        } else {
            $params['primary_release_date.gte'] = $gte;
            $params['primary_release_date.lte'] = $lte;
        }
    }

    if ($countriesIn) {
        $params['with_origin_country'] = implode('|', $countriesIn);
    }
    // TMDB has no official without_origin_country — best-effort; ignored if unsupported
    if ($countriesOut) {
        $params['without_origin_country'] = implode('|', $countriesOut);
    }
    if ($ratingFrom !== '' && is_numeric($ratingFrom)) {
        $params['vote_average.gte'] = (float) $ratingFrom;
    }
    if ($ratingTo !== '' && is_numeric($ratingTo)) {
        $params['vote_average.lte'] = (float) $ratingTo;
    }
    if ($providersIn) {
        $params['with_watch_providers'] = implode('|', $providersIn);
        $params['watch_region'] = 'US';
    }
    if ($providersOut) {
        $params['without_watch_providers'] = implode('|', $providersOut);
        $params['watch_region'] = 'US';
    }
    if ($networksIn) {
        $params['with_networks'] = implode('|', $networksIn);
    }
    if ($networksOut) {
        $params['without_networks'] = implode('|', $networksOut);
    }
    if ($forceOriginalLanguage !== '') {
        $params['with_original_language'] = $forceOriginalLanguage;
    }
    if ($voteCountGte > 0) {
        $params['vote_count.gte'] = $voteCountGte;
    }

    $data = $tmdb->discover($type, $params);
    $sidebarParams = ['sort_by' => 'popularity.desc', 'page' => 1, 'include_adult' => 'false'];
    if ($forceGenres) {
        $sidebarParams['with_genres'] = implode('|', $forceGenres);
    }
    if ($forceOriginalLanguage !== '') {
        $sidebarParams['with_original_language'] = $forceOriginalLanguage;
    }
    if ($voteCountGte > 0) {
        $sidebarParams['vote_count.gte'] = $voteCountGte;
    }
    $sidebarItems = array_slice(
        $tmdb->discover($type, $sidebarParams)['results'] ?? [],
        0,
        8
    );
    $isMovie = $type === 'movie';
    $label = $pageLabel !== '' ? $pageLabel : ($isMovie ? t('discover.movies') : t('discover.tv_shows'));
    $canonicalPath = $pagePath !== '' ? $pagePath : ($isMovie ? '/movies' : '/tv-series');
    view('pages/discover', [
        'type' => $type,
        'animeMode' => $animeMode,
        'pageLabel' => $label,
        'pagePath' => $canonicalPath,
        'page' => $page,
        'sort' => $sort,
        'year' => $year,
        'yearFrom' => $yearFrom,
        'yearTo' => $yearTo,
        'ratingFrom' => $ratingFrom,
        'ratingTo' => $ratingTo,
        'genre' => $genre,
        'selectedGenres' => $genresIn,
        'excludedGenres' => $genresOut,
        'selectedCountries' => $countriesIn,
        'excludedCountries' => $countriesOut,
        'selectedProviders' => $providersIn,
        'excludedProviders' => $providersOut,
        'selectedNetworks' => $networksIn,
        'excludedNetworks' => $networksOut,
        'data' => $data,
        'filtersPage' => $filtersPage,
        'genres' => $isMovie ? config('genres_movie') : config('genres_tv'),
        'sidebarItems' => $sidebarItems,
        'bodyClass' => 'page-discover',
        'headerClass' => 'absolute',
        'seo' => [
            'title' => ($animeMode
                ? ($label . ' | Watch Japanese Anime Online Free - ' . config('site_name'))
                : ($label . ' | ' . str_replace('{type}', $isMovie ? t('discover.movies') : t('discover.tv_shows'), t('seo.watch_online_free')) . ' - ' . config('site_name'))),
            'description' => $animeMode
                ? ('Browse Japanese anime only — series and movies. Stream anime online free in HD on ' . config('site_name') . '.')
                : ($isMovie
                ? 'Explore and watch the latest movies online for free. Browse our extensive collection of films in high definition.'
                : 'Stream your favorite TV shows and series online for free. Catch up on the latest episodes in HD quality.'),
            'canonical' => url($canonicalPath . ($animeMode && $isMovie ? '?type=movie' : '')),
        ],
    ]);
}

function build_person_payload(Tmdb $tmdb, int $id): ?array
{
    if ($id < 1) {
        return null;
    }
    $person = $tmdb->person($id);
    if (!$person || empty($person['name'])) {
        return null;
    }

    $name = (string) $person['name'];
    $creditsCast = $person['combined_credits']['cast'] ?? [];
    $creditsCrew = $person['combined_credits']['crew'] ?? [];
    $merged = [];
    foreach (array_merge(is_array($creditsCast) ? $creditsCast : [], is_array($creditsCrew) ? $creditsCrew : []) as $credit) {
        if (!is_array($credit)) {
            continue;
        }
        $mediaType = (string) ($credit['media_type'] ?? '');
        if (!in_array($mediaType, ['movie', 'tv'], true)) {
            continue;
        }
        $cid = (int) ($credit['id'] ?? 0);
        if ($cid < 1) {
            continue;
        }
        $key = $mediaType . ':' . $cid;
        $pop = (float) ($credit['popularity'] ?? 0);
        $votes = (float) ($credit['vote_count'] ?? 0);
        $score = $pop * 10 + $votes;
        if (!isset($merged[$key]) || $score > (float) ($merged[$key]['_score'] ?? 0)) {
            $credit['_score'] = $score;
            $merged[$key] = $credit;
        }
    }
    usort($merged, static fn ($a, $b) => ((float) ($b['_score'] ?? 0)) <=> ((float) ($a['_score'] ?? 0)));
    $creditCount = count($merged);
    $knownForRaw = array_values(array_slice($merged, 0, 24));
    foreach ($knownForRaw as &$kf) {
        unset($kf['_score']);
    }
    unset($kf);

    $backdropPath = null;
    foreach ($knownForRaw as $kf) {
        if (!empty($kf['backdrop_path'])) {
            $backdropPath = $kf['backdrop_path'];
            break;
        }
    }
    if (!$backdropPath) {
        foreach ($knownForRaw as $kf) {
            if (!empty($kf['poster_path'])) {
                $backdropPath = $kf['poster_path'];
                break;
            }
        }
    }

    $birthdayRaw = (string) ($person['birthday'] ?? '');
    $deathdayRaw = (string) ($person['deathday'] ?? '');
    $birthday = $birthdayRaw !== '' ? date('M d, Y', strtotime($birthdayRaw)) : '';
    $deathday = $deathdayRaw !== '' ? date('M d, Y', strtotime($deathdayRaw)) : '';
    $age = null;
    if ($birthdayRaw !== '') {
        try {
            $born = new DateTimeImmutable($birthdayRaw);
            $end = $deathdayRaw !== '' ? new DateTimeImmutable($deathdayRaw) : new DateTimeImmutable('today');
            $age = (int) $born->diff($end)->y;
        } catch (Throwable) {
            $age = null;
        }
    }

    $imdbId = (string) ($person['external_ids']['imdb_id'] ?? '');
    $imdbUrl = $imdbId !== '' ? ('https://www.imdb.com/name/' . $imdbId) : '';
    $homepage = (string) ($person['homepage'] ?? '');
    if ($homepage !== '' && !preg_match('#^https?://#i', $homepage)) {
        $homepage = 'https://' . ltrim($homepage, '/');
    }

    $department = (string) ($person['known_for_department'] ?? '');
    $biography = trim((string) ($person['biography'] ?? ''));
    $biography = preg_replace('/\n*Description above from the Wikipedia article[\s\S]*$/iu', '', $biography) ?? $biography;
    $biography = preg_replace('/\n*From Wikipedia[\s\S]*$/iu', '', $biography) ?? $biography;
    $biography = trim($biography);
    $birthPlace = (string) ($person['place_of_birth'] ?? '');
    $profile = img_url($person['profile_path'] ?? null, 'w600_and_h900_bestv2');
    $backdrop = img_url($backdropPath, 'original');

    $knownFor = [];
    foreach (array_slice($knownForRaw, 0, 16) as $credit) {
        $mediaType = (($credit['media_type'] ?? '') === 'tv') ? 'tv' : 'movie';
        $title = (string) ($credit['title'] ?? $credit['name'] ?? 'Untitled');
        $cid = (int) ($credit['id'] ?? 0);
        $date = (string) ($credit['release_date'] ?? $credit['first_air_date'] ?? '');
        $year = $date !== '' ? substr($date, 0, 4) : '';
        $rating = isset($credit['vote_average']) ? round((float) $credit['vote_average'], 1) : null;
        $knownFor[] = [
            'id' => $cid,
            'type' => $mediaType,
            'title' => $title,
            'poster' => img_url($credit['poster_path'] ?? null, 'w342'),
            'year' => $year,
            'rating' => $rating,
            'href' => media_url($mediaType, $cid, $title),
        ];
    }

    return [
        'id' => $id,
        'name' => $name,
        'slug' => slugify($name),
        'url' => person_url($id, $name),
        'profile' => $profile,
        'backdrop' => $backdrop,
        'department' => $department,
        'birthday' => $birthday,
        'deathday' => $deathday,
        'age' => $age,
        'birthPlace' => $birthPlace,
        'biography' => $biography,
        'imdbUrl' => $imdbUrl,
        'homepage' => $homepage,
        'creditCount' => $creditCount,
        'knownFor' => $knownFor,
        'knownForRaw' => $knownForRaw,
        'person' => $person,
        'backdropPath' => $backdropPath,
    ];
}

function person_page(Tmdb $tmdb, int $id, string $slug): void
{
    $data = build_person_payload($tmdb, $id);
    if (!$data) {
        http_response_code(404);
        view('pages/404', [
            'seo' => [
                'title' => 'Not Found | ' . config('site_name'),
                'description' => 'Person not found.',
                'robots' => 'noindex, follow',
            ],
        ]);
        return;
    }

    $name = (string) $data['name'];
    $canonicalSlug = (string) $data['slug'];
    if ($slug !== $canonicalSlug) {
        redirect('/person/' . $canonicalSlug . '/' . $id, 301);
    }

    $biography = (string) $data['biography'];
    $desc = $biography !== ''
        ? truncate($biography, 160)
        : ($name . ($data['department'] !== '' ? (' — ' . $data['department']) : '') . ' on ' . config('site_name') . '.');

    view('pages/person', [
        'person' => $data['person'],
        'name' => $name,
        'profile' => $data['profile'],
        'backdrop' => $data['backdrop'],
        'department' => $data['department'],
        'birthday' => $data['birthday'],
        'deathday' => $data['deathday'],
        'birthPlace' => $data['birthPlace'],
        'biography' => $biography,
        'age' => $data['age'],
        'imdbUrl' => $data['imdbUrl'],
        'homepage' => $data['homepage'],
        'knownFor' => $data['knownForRaw'],
        'creditCount' => $data['creditCount'],
        'bodyClass' => 'page-person',
        'headerClass' => 'absolute',
        'seo' => [
            'title' => $name . ' | Cast & Crew — ' . config('site_name'),
            'description' => $desc,
            'canonical' => person_url($id, $name),
            'image' => img_url(($data['person']['profile_path'] ?? null) ?: $data['backdropPath'], 'w780'),
            'type' => 'profile',
            'keywords' => $name . ', actor, actress, cast, filmography, ' . $data['department'],
        ],
    ]);
}

function watch_page(Tmdb $tmdb, string $type, int $id, string $slug): void
{
    if (class_exists('ProxyGuard')) {
        ProxyGuard::ensureSession();
    }
    $item = $tmdb->details($type, $id);
    if (!$item) {
        http_response_code(404);
        view('pages/404', [
            'seo' => [
                'title' => 'Not Found | ' . config('site_name'),
                'description' => 'Title not found.',
                'robots' => 'noindex, follow',
            ],
        ]);
        return;
    }
    $title = title_of($item);
    $year = year_of(date_of($item));
    $overview = (string) ($item['overview'] ?? '');
    $poster = img_url($item['poster_path'] ?? null);
    $backdrop = img_url($item['backdrop_path'] ?? null, 'original');
    $trailer = $tmdb->trailerKey($item);
    $canonicalSlug = slugify($title);
    if ($slug !== $canonicalSlug) {
        redirect(($type === 'tv' ? '/tv/' : '/movie/') . $canonicalSlug . '/' . $id, 301);
    }

    $season = max(1, (int) ($_GET['s'] ?? 1));
    $episode = max(1, (int) ($_GET['e'] ?? 1));
    $episodes = [];
    if ($type === 'tv') {
        $seasonData = $tmdb->get("tv/{$id}/season/{$season}");
        $episodes = $seasonData['episodes'] ?? [];
    }

    $autoPlay = isset($_GET['play']) && (string) $_GET['play'] !== '0';
    $watchPath = ($type === 'tv' ? '/tv/' : '/movie/') . slugify($title) . '/' . $id;
    $watchUrl = media_url($type, $id, $title);
    if ($type === 'tv') {
        $watchUrl .= '?s=' . $season . '&e=' . $episode;
    }
    $nextEpisode = null;
    $prevEpisode = null;
    if ($type === 'tv' && $episodes) {
        foreach ($episodes as $i => $ep) {
            if ((int) ($ep['episode_number'] ?? 0) === $episode) {
                $p = $episodes[$i - 1] ?? null;
                if ($p) {
                    $pe = (int) $p['episode_number'];
                    $prevEpisode = [
                        'season' => $season,
                        'episode' => $pe,
                        'title' => (string) ($p['name'] ?? ('Episode ' . $pe)),
                        'url' => media_url('tv', $id, $title) . '?s=' . $season . '&e=' . $pe . '&play=1',
                    ];
                } elseif ($season > 1) {
                    $psData = $tmdb->get('tv/' . $id . '/season/' . ($season - 1));
                    $psEps = $psData['episodes'] ?? [];
                    $last = $psEps ? $psEps[count($psEps) - 1] : null;
                    if ($last) {
                        $pe = (int) ($last['episode_number'] ?? 1);
                        $prevEpisode = [
                            'season' => $season - 1,
                            'episode' => $pe,
                            'title' => (string) ($last['name'] ?? ('Episode ' . $pe)),
                            'url' => media_url('tv', $id, $title) . '?s=' . ($season - 1) . '&e=' . $pe . '&play=1',
                        ];
                    }
                }
                $n = $episodes[$i + 1] ?? null;
                if ($n) {
                    $ns = $season;
                    $ne = (int) $n['episode_number'];
                    $nextEpisode = [
                        'season' => $ns,
                        'episode' => $ne,
                        'title' => (string) ($n['name'] ?? ('Episode ' . $ne)),
                        'url' => media_url('tv', $id, $title) . '?s=' . $ns . '&e=' . $ne . '&play=1',
                    ];
                } else {
                    $nextSeason = $season + 1;
                    $nsData = $tmdb->get("tv/{$id}/season/{$nextSeason}");
                    $neEp = $nsData['episodes'][0] ?? null;
                    if ($neEp) {
                        $ne = (int) ($neEp['episode_number'] ?? 1);
                        $nextEpisode = [
                            'season' => $nextSeason,
                            'episode' => $ne,
                            'title' => (string) ($neEp['name'] ?? 'Episode 1'),
                            'url' => media_url('tv', $id, $title) . '?s=' . $nextSeason . '&e=' . $ne . '&play=1',
                        ];
                    }
                }
                break;
            }
        }
    }
    $imdbId = (string) (($item['external_ids']['imdb_id'] ?? '') ?: ($item['imdb_id'] ?? ''));
    $providerRows = [];
    try {
        $pack = PlayerSources::listProviders();
        $providerRows = is_array($pack['providers'] ?? null) ? $pack['providers'] : [];
    } catch (Throwable $e) {
        $providerRows = [];
    }
    $episodeCards = [];
    $seasonCards = [];
    if ($type === 'tv') {
        foreach (($item['seasons'] ?? []) as $sRow) {
            $sn = (int) ($sRow['season_number'] ?? 0);
            if ($sn < 1) {
                continue;
            }
            $seasonCards[] = [
                'season' => $sn,
                'name' => (string) ($sRow['name'] ?? ('Season ' . $sn)),
                'episodeCount' => (int) ($sRow['episode_count'] ?? 0),
            ];
        }
        foreach ($episodes as $epRow) {
            $en = (int) ($epRow['episode_number'] ?? 0);
            if ($en < 1) {
                continue;
            }
            $still = !empty($epRow['still_path']) ? img_url($epRow['still_path'], 'w300') : null;
            $episodeCards[] = [
                'season' => $season,
                'episode' => $en,
                'title' => (string) ($epRow['name'] ?? ('Episode ' . $en)),
                'overview' => truncate((string) ($epRow['overview'] ?? ''), 140),
                'runtime' => isset($epRow['runtime']) ? (int) $epRow['runtime'] : null,
                'still' => $still,
                'airDate' => (string) ($epRow['air_date'] ?? ''),
                'url' => media_url('tv', $id, $title) . '?s=' . $season . '&e=' . $en . '&play=1',
            ];
        }
    }
    $playerConfig = [
        'type' => $type,
        'id' => $id,
        'imdbId' => $imdbId !== '' ? $imdbId : null,
        'title' => $title,
        'year' => $year,
        'poster' => $poster,
        'backdrop' => img_url($item['backdrop_path'] ?? $item['poster_path'] ?? null, 'w1280'),
        'backUrl' => $watchUrl,
        'watchUrl' => $watchUrl,
        'season' => $season,
        'episode' => $episode,
        'next' => $nextEpisode,
        'prev' => $prevEpisode,
        'episodes' => $episodeCards,
        'seasons' => $seasonCards,
        'episodesApi' => url('/api/player/episodes'),
        'sourcesApi' => url('/api/player/sources'),
        'providersApi' => url('/api/player/providers'),
        'skipSegmentsApi' => url('/api/player/skip-segments'),
        'providers' => $providerRows,
        'subsApi' => url('/api/player/subtitles'),
        'captionApi' => url('/api/player/caption'),
        'partyApi' => url('/api/party'),
        'embed' => true,
        'autoplay' => true,
    ];

    view('pages/watch', [
        'type' => $type,
        'item' => $item,
        'title' => $title,
        'year' => $year,
        'overview' => $overview,
        'poster' => $poster,
        'backdrop' => $backdrop,
        'trailer' => $trailer,
        'season' => $season,
        'episode' => $episode,
        'episodes' => $episodes,
        'servers' => config('servers'),
        'autoPlay' => $autoPlay,
        'playerConfig' => $playerConfig,
        'nextEpisode' => $nextEpisode,
        'extraCss' => [
            asset('css/player.css') . '?v=20260816-theme1',
            asset('css/watch-player.css') . '?v=20260816-watch-footer1',
        ],
        'extraJs' => [
            'https://cdn.jsdelivr.net/npm/hls.js@1.6.16/dist/hls.min.js',
            asset('js/player.js') . '?v=20260827-race-patience1',
        ],
        'bodyClass' => 'page-watch',
        'headerClass' => 'absolute',
        'seo' => [
            'title' => $title . ($year ? " ($year)" : '') . ' | Watch Online - ' . config('site_name'),
            'description' => truncate($overview !== '' ? $overview : "Watch {$title} online free in HD on " . config('site_name') . '.', 160),
            'canonical' => media_url($type, $id, $title),
            'image' => img_url($item['backdrop_path'] ?? $item['poster_path'] ?? null, 'w780'),
            'type' => $type === 'tv' ? 'video.tv_show' : 'video.movie',
            'keywords' => $title . ', ' . $year . ', watch online, streaming, free ' . ($type === 'tv' ? 'tv series' : 'movie'),
            'schema' => 'watch',
        ],
    ]);
}

function player_page(Tmdb $tmdb, string $type, int $id, string $slug): void
{
    // redirect to watch page for inline playback
    $item = $tmdb->details($type, $id);
    $title = $item ? title_of($item) : $slug;
    $season = max(1, (int) ($_GET['s'] ?? 1));
    $episode = max(1, (int) ($_GET['e'] ?? 1));
    $target = ($type === 'tv' ? '/tv/' : '/movie/') . slugify($title) . '/' . $id . '?play=1';
    if ($type === 'tv') {
        $target = ($type === 'tv' ? '/tv/' : '/movie/') . slugify($title) . '/' . $id . '?s=' . $season . '&e=' . $episode . '&play=1';
    }
    redirect($target, 302);
    return;

    $item = $tmdb->details($type, $id);
    if (!$item) {
        http_response_code(404);
        view('pages/404', [
            'seo' => [
                'title' => 'Not Found | ' . config('site_name'),
                'description' => 'Title not found.',
                'robots' => 'noindex, follow',
            ],
        ]);
        return;
    }
    $title = title_of($item);
    $canonicalSlug = slugify($title);
    if ($slug !== $canonicalSlug) {
        $season = max(1, (int) ($_GET['s'] ?? 1));
        $episode = max(1, (int) ($_GET['e'] ?? 1));
        $target = '/player/' . ($type === 'tv' ? 'tv' : 'movie') . '/' . $canonicalSlug . '/' . $id;
        if ($type === 'tv') {
            $target .= '?s=' . $season . '&e=' . $episode;
        }
        redirect($target, 301);
    }

    $season = max(1, (int) ($_GET['s'] ?? 1));
    $episode = max(1, (int) ($_GET['e'] ?? 1));
    $backdrop = img_url($item['backdrop_path'] ?? $item['poster_path'] ?? null, 'w1280');
    $poster = img_url($item['poster_path'] ?? null, 'w500');
    $year = year_of(date_of($item));
    $backUrl = media_url($type, $id, $title);
    if ($type === 'tv') {
        $backUrl .= '?s=' . $season . '&e=' . $episode;
    }

    $next = null;
    if ($type === 'tv') {
        $seasonData = $tmdb->get("tv/{$id}/season/{$season}");
        $episodes = $seasonData['episodes'] ?? [];
        foreach ($episodes as $i => $ep) {
            if ((int) ($ep['episode_number'] ?? 0) === $episode) {
                $n = $episodes[$i + 1] ?? null;
                if ($n) {
                    $next = [
                        'season' => $season,
                        'episode' => (int) $n['episode_number'],
                        'title' => (string) ($n['name'] ?? ('Episode ' . $n['episode_number'])),
                        'url' => player_url('tv', $id, $title, $season, (int) $n['episode_number']),
                    ];
                } else {
                    // try next season ep 1
                    $nextSeason = $season + 1;
                    $ns = $tmdb->get("tv/{$id}/season/{$nextSeason}");
                    $ne = $ns['episodes'][0] ?? null;
                    if ($ne) {
                        $next = [
                            'season' => $nextSeason,
                            'episode' => (int) ($ne['episode_number'] ?? 1),
                            'title' => (string) ($ne['name'] ?? 'Episode 1'),
                            'url' => player_url('tv', $id, $title, $nextSeason, (int) ($ne['episode_number'] ?? 1)),
                        ];
                    }
                }
                break;
            }
        }
    }

    view('pages/player', [
        'layout' => 'player',
        'type' => $type,
        'item' => $item,
        'title' => $title,
        'year' => $year,
        'poster' => $poster,
        'backdrop' => $backdrop,
        'backUrl' => $backUrl,
        'season' => $season,
        'episode' => $episode,
        'nextEpisode' => $next,
        'bodyClass' => 'page-player',
        'seo' => [
            'title' => 'Watch ' . $title . ($year ? " ($year)" : '') . ' | ' . config('site_name'),
            'description' => truncate((string) ($item['overview'] ?? ''), 160),
            'canonical' => player_url($type, $id, $title, $season, $episode),
            'image' => $backdrop,
            'robots' => 'noindex, follow',
            'type' => $type === 'tv' ? 'video.tv_show' : 'video.movie',
        ],
    ]);
}


// --- Daily24 news portal ---
$router->get('/news', function () {
    $country = NewsFeed::country($_GET['country'] ?? 'GLOBAL');
    $category = NewsFeed::category($_GET['category'] ?? 'top');
    $q = trim((string) ($_GET['q'] ?? ''));
    $articles = NewsFeed::feed($country['code'], $category['id'], 36);
    if ($q !== '') {
        $needle = mb_strtolower($q);
        $articles = array_values(array_filter($articles, static function ($a) use ($needle) {
            $hay = mb_strtolower(($a['title'] ?? '') . ' ' . ($a['summary'] ?? '') . ' ' . ($a['sourceName'] ?? ''));
            return str_contains($hay, $needle);
        }));
    }
    view('pages/news-home', [
        'layout' => 'news',
        'articles' => $articles,
        'countryCode' => $country['code'],
        'categoryId' => $category['id'],
        'q' => $q,
        'seo' => [
            'title' => 'Daily24 News — ' . $country['native'] . ' | ' . config('site_name'),
            'description' => 'Global and local headlines with country language editions. Aggregated from public news feeds.',
            'canonical' => url('/news?country=' . rawurlencode($country['code'])),
            'lang' => $country['lang'],
        ],
    ]);
});

$router->get('/news/article/{id}', function (array $p) {
    $country = NewsFeed::country($_GET['country'] ?? 'GLOBAL');
    $article = NewsFeed::find((string) ($p['id'] ?? ''), $country['code']);
    if (!$article) {
        http_response_code(404);
        view('pages/404', [
            'seo' => [
                'title' => 'Article not found | ' . config('site_name'),
                'robots' => 'noindex, follow',
            ],
        ]);
        return;
    }
    view('pages/news-article', [
        'layout' => 'news',
        'article' => $article,
        'countryCode' => $country['code'],
        'categoryId' => 'top',
        'seo' => [
            'title' => ((string) $article['title']) . ' | Daily24',
            'description' => (string) ($article['summary'] ?: $article['title']),
            'canonical' => NewsFeed::articleUrl($article, $country['code']),
            'lang' => $country['lang'],
            'image' => $article['image'] ?? null,
        ],
    ]);
});

$router->get('/api/news/feed', function () {
    header('Cache-Control: public, max-age=120');
    $country = $_GET['country'] ?? 'GLOBAL';
    $category = $_GET['category'] ?? 'top';
    $limit = (int) ($_GET['limit'] ?? 36);
    json_response([
        'ok' => true,
        'articles' => NewsFeed::feed((string) $country, (string) $category, $limit),
    ]);
});

$router->get('/api/news/image', function () {
    $raw = (string) ($_GET['u'] ?? '');
    $url = filter_var($raw, FILTER_VALIDATE_URL);
    if (!$url || !preg_match('#^https?://#i', $url)) {
        http_response_code(400);
        exit('bad url');
    }
    $host = strtolower((string) (parse_url($url, PHP_URL_HOST) ?: ''));
    if ($host === '' || str_contains($host, 'localhost') || preg_match('/^(10\.|127\.|192\.168\.|169\.254\.)/', $host)) {
        http_response_code(400);
        exit('blocked host');
    }

    $cacheDir = dirname(__DIR__) . '/storage/cache/news/img';
    if (!is_dir($cacheDir)) {
        @mkdir($cacheDir, 0775, true);
    }
    $file = $cacheDir . '/' . sha1($url) . '.bin';
    $meta = $file . '.meta';

    if (is_file($file) && is_file($meta) && filemtime($file) + 86400 > time()) {
        $ctype = trim((string) file_get_contents($meta)) ?: 'image/jpeg';
        header('Content-Type: ' . $ctype);
        header('Cache-Control: public, max-age=86400');
        readfile($file);
        exit;
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; Daily24NewsBot/1.1)',
        CURLOPT_HTTPHEADER => ['Accept: image/avif,image/webp,image/*,*/*;q=0.8'],
    ]);
    $body = curl_exec($ch);
    $ctype = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($body === false || $code >= 400 || strlen($body) < 40) {
        http_response_code(502);
        exit('image fetch failed');
    }
    if ($ctype === '' || !str_starts_with(strtolower($ctype), 'image/')) {
        $ctype = 'image/jpeg';
    }
    $ctype = strtok($ctype, ';') ?: 'image/jpeg';
    @file_put_contents($file, $body);
    @file_put_contents($meta, $ctype);
    header('Content-Type: ' . $ctype);
    header('Cache-Control: public, max-age=86400');
    echo $body;
    exit;
});

