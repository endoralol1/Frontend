<?php
declare(strict_types=1);

/** @var Router $router */
/** @var Tmdb $tmdb */

$router->get('/', fn () => home($tmdb));
$router->get('/home', fn () => home($tmdb));

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
            'title' => 'My Favorites - Your Personal Movie & Series Collection | ' . config('site_name'),
            'description' => 'Access and manage your personal list of favorite movies and TV series on ' . config('site_name') . '.',
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

$router->get('/search', function () use ($tmdb) {
    $q = trim((string) ($_GET['keyword'] ?? $_GET['q'] ?? ''));
    $page = max(1, (int) ($_GET['page'] ?? 1));
    $data = $q !== '' ? $tmdb->search($q, $page) : ['results' => [], 'total_results' => 0];
    $results = array_values(array_filter($data['results'] ?? [], static function ($r) {
        return in_array($r['media_type'] ?? '', ['movie', 'tv'], true);
    }));
    view('pages/search', [
        'q' => $q,
        'page' => $page,
        'results' => $results,
        'total' => (int) ($data['total_results'] ?? count($results)),
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

$router->get('/movie/{slug}/{id}', function (array $p) use ($tmdb) {
    watch_page($tmdb, 'movie', (int) $p['id'], (string) $p['slug']);
});

$router->get('/tv/{slug}/{id}', function (array $p) use ($tmdb) {
    watch_page($tmdb, 'tv', (int) $p['id'], (string) $p['slug']);
});

$router->get('/api/search', function () use ($tmdb) {
    $q = trim((string) ($_GET['q'] ?? ''));
    if (mb_strlen($q) < 2) {
        json_response(['results' => []]);
    }
    $data = $tmdb->search($q, 1);
    $movies = [];
    $shows = [];
    foreach ($data['results'] ?? [] as $r) {
        $type = $r['media_type'] ?? '';
        if (!in_array($type, ['movie', 'tv'], true)) {
            continue;
        }
        $title = title_of($r);
        $row = [
            'id' => $r['id'],
            'type' => $type,
            'title' => $title,
            'year' => year_of(date_of($r)),
            'poster' => img_url($r['poster_path'] ?? null, 'w185'),
            'url' => media_url($type, $r['id'], $title),
            'rating' => isset($r['vote_average']) ? round((float) $r['vote_average'], 1) : null,
        ];
        if ($type === 'tv') {
            $shows[] = $row;
        } else {
            $movies[] = $row;
        }
    }
    // Interleave movies & TV so live suggestions always show both when available.
    $out = [];
    $mi = 0;
    $si = 0;
    while (count($out) < 12 && ($mi < count($movies) || $si < count($shows))) {
        if ($mi < count($movies)) {
            $out[] = $movies[$mi++];
        }
        if (count($out) >= 12) {
            break;
        }
        if ($si < count($shows)) {
            $out[] = $shows[$si++];
        }
    }
    json_response(['results' => $out]);
});

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
    ]);
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
        url('/top-imdb'),
        url('/favorites'),
        url('/contact'),
        url('/request'),
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
    $trendingMoviesWeek = $tmdb->trending('movie', 'week');
    $featured = array_slice($trendingMoviesWeek, 0, 5);
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
    $mostCommented = array_slice($tmdb->trending('all', 'day'), 0, 10);
    $recentlyUpdated = array_slice($tmdb->trending('all', 'week'), 0, 12);

    view('pages/home', [
        'featured' => $featured,
        'top10Movies' => $top10Movies,
        'top10Tv' => $top10Tv,
        'recommendedMovies' => array_slice($recommendedMovies, 0, 14),
        'recommendedTv' => array_slice($recommendedTv, 0, 14),
        'latestMovies' => array_slice($latestMovies, 0, 14),
        'latestTv' => array_slice($latestTv, 0, 14),
        'mostCommented' => $mostCommented,
        'recentlyUpdated' => $recentlyUpdated,
        'seo' => [
            'title' => 'Home - Explore Thousands of Movies & Series | ' . config('site_name'),
            'description' => 'Start exploring our vast library of movies and TV series. Find new releases, popular titles, and browse by genre on ' . config('site_name') . '.',
            'canonical' => url('/home'),
            'image' => asset('img/logo.webp'),
            'schema' => 'home',
        ],
    ]);
}

function discover_page(Tmdb $tmdb, string $type, bool $filtersPage = false): void
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

    $data = $tmdb->discover($type, $params);
    $sidebarItems = array_slice(
        $tmdb->discover($type, ['sort_by' => 'popularity.desc', 'page' => 1])['results'] ?? [],
        0,
        8
    );
    $isMovie = $type === 'movie';
    $label = $isMovie ? 'Movies' : 'TV Shows';
    view('pages/discover', [
        'type' => $type,
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
        'bodyClass' => 'page-watch',
        'headerClass' => 'absolute',
        'seo' => [
            'title' => $label . ' | Watch ' . ($isMovie ? 'Movies' : 'TV Series') . ' Online Free - ' . config('site_name'),
            'description' => $isMovie
                ? 'Explore and watch the latest movies online for free. Browse our extensive collection of films in high definition.'
                : 'Stream your favorite TV shows and series online for free. Catch up on the latest episodes in HD quality.',
            'canonical' => url($isMovie ? '/movies' : '/tv-series'),
        ],
    ]);
}

function watch_page(Tmdb $tmdb, string $type, int $id, string $slug): void
{
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
