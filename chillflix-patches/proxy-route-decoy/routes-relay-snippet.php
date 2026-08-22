

// Old proxy paths — decoy image pointing scrapers/curious visitors to vuflix.co
$router->get('/api/player/lang-proxy', function () {
    ProxyDecoy::serve();
});
$router->get('/api/player/media-proxy', function () {
    ProxyDecoy::serve();
});

// Current stream relays (rotated off media-proxy / lang-proxy)
$router->get('/api/player/a-relay', function () {
    $ua = strtolower((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));
    if ($ua !== '' && preg_match('/curl|wget|python-requests|python-urllib|scrapy|libwww-perl|go-http-client|httpie|postman/i', $ua)) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'error' => 'Forbidden']);
        exit;
    }
    $token = trim((string) ($_GET['t'] ?? ''));
    $sig = trim((string) ($_GET['s'] ?? ''));
    if (!class_exists('StreamLangProxy')) {
        json_response(['ok' => false, 'error' => 'Lang proxy unavailable'], 404);
    }
    StreamLangProxy::handle($token, $sig);
});

$router->get('/api/player/v-relay', function () {
    $ua = strtolower((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));
    if ($ua !== '' && preg_match('/curl|wget|python-requests|python-urllib|scrapy|libwww-perl|go-http-client|httpie|postman/i', $ua)) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'error' => 'Forbidden']);
        exit;
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
