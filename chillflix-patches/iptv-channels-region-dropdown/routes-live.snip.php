    $pack = HuhuLiveTv::catalog();
    $channels = $pack['ok'] ? array_slice($pack['channels'] ?? [], 0, 60) : [];
    $categories = $pack['ok'] ? ($pack['categories'] ?? []) : [];
    $liveAssetV = '20260815-iptv2';
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

