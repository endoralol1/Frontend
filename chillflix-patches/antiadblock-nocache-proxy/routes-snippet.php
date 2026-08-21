<?php
// Add near other ads routes in app/routes.php (before /admin/ads is fine).

$router->get('/n/{name}.js', function (array $params = []) {
    SiteAds::serveAntiAdblockNocache((string) ($params['name'] ?? ''));
});
