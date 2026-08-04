<?php
// Patch fragment for app/routes.php — progressive player sources
// Replace the existing /api/player/sources route with:

$router->get('/api/player/providers', function () {
    $result = PlayerSources::listProviders();
    json_response($result, 200);
});

$router->get('/api/player/sources', function () {
    $type = ($_GET['type'] ?? '') === 'tv' ? 'tv' : 'movie';
    $tmdbId = (int) ($_GET['tmdbId'] ?? 0);
    $season = max(1, (int) ($_GET['season'] ?? $_GET['s'] ?? 1));
    $episode = max(1, (int) ($_GET['episode'] ?? $_GET['e'] ?? 1));
    $only = isset($_GET['provider']) ? strtolower(trim((string) $_GET['provider'])) : '';
    $result = PlayerSources::fetch($type, $tmdbId, $season, $episode, $only !== '' ? $only : null);
    json_response($result, !empty($result['ok']) ? 200 : 502);
});
