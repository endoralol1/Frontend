<?php
// Snippet applied to app/routes.php — block scraper UAs on player proxies.

$router->get('/api/player/lang-proxy', function () {
    $ua = strtolower((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));
    if ($ua !== '' && preg_match('/curl|wget|python-requests|python-urllib|scrapy|libwww-perl|go-http-client|httpie|postman/i', $ua)) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'error' => 'Forbidden']);
        exit;
    }
    // ... existing token/sig + StreamLangProxy::handle
});

$router->get('/api/player/media-proxy', function () {
    $ua = strtolower((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));
    if ($ua !== '' && preg_match('/curl|wget|python-requests|python-urllib|scrapy|libwww-perl|go-http-client|httpie|postman/i', $ua)) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'error' => 'Forbidden']);
        exit;
    }
    // ... existing VidmolySources/StremifySources::proxyMedia
});
