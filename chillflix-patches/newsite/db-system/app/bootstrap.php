<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap-services.php';

$tmdb = new Tmdb();
$router = new Router();

require __DIR__ . '/routes.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$uri = $_SERVER['REQUEST_URI'] ?? '/';
$basePath = rtrim((string) (parse_url(base_url(), PHP_URL_PATH) ?: ''), '/');
$requestPath = parse_url($uri, PHP_URL_PATH) ?: '/';
if ($basePath !== '' && str_starts_with($requestPath, $basePath)) {
    $stripped = substr($requestPath, strlen($basePath)) ?: '/';
    $qs = parse_url($uri, PHP_URL_QUERY);
    $uri = $stripped . ($qs ? ('?' . $qs) : '');
}
$router->dispatch($method, $uri);
