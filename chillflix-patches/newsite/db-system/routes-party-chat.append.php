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

$router->get('/person/{slug}/{id}', function (array $p) use ($tmdb) {
    person_page($tmdb, (int) $p['id'], (string) $p['slug']);
