<?php
declare(strict_types=1);

/**
 * Append to app/routes.php (near other page routes).
 * Also require NewsFeed in bootstrap-services.php.
 */

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
    // When this snippet lives in routes.php, dirname differs — use newsite storage:
    $cacheDir = '/var/www/chillflix-newsite/storage/cache/news/img';
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
