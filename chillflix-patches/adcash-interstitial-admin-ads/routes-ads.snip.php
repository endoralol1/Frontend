<?php
// Snippet — merge into app/routes.php and bootstrap-services.php

// bootstrap-services.php:
// require_once __DIR__ . '/Services/SiteAds.php';

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
    ]);
    json_response(['ok' => true, 'ads' => $ads]);
});
