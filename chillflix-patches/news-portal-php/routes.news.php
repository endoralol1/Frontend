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
