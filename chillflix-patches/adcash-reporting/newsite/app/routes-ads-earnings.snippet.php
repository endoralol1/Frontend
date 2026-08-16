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
    }
    AdCashReporting::saveApiToken($token);
    json_response([
        'ok' => true,
        'token_masked' => AdCashReporting::maskedToken(),
        'configured' => AdCashReporting::hasToken(),
    ]);
});
