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

$router->get('/admin/sources', function () {
    $user = Auth::user();
    if (!$user || !Auth::isStaff($user)) {
