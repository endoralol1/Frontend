<?php
declare(strict_types=1);

function config(?string $key = null, mixed $default = null): mixed
{
    static $cfg;
    $cfg ??= require __DIR__ . '/config.php';
    if ($key !== null && isset($GLOBALS['__cf_config_override']) && is_array($GLOBALS['__cf_config_override'])
        && array_key_exists($key, $GLOBALS['__cf_config_override'])) {
        return $GLOBALS['__cf_config_override'][$key];
    }
    if ($key === null) {
        return $cfg;
    }
    return $cfg[$key] ?? $default;
}

function json_body(): array
{
    $raw = file_get_contents('php://input') ?: '';
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function base_url(): string
{
    static $url;
    if ($url !== null) {
        return $url;
    }
    $configured = rtrim((string) config('base_url', ''), '/');
    if ($configured !== '') {
        return $url = $configured;
    }
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || ((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
        || ((int) ($_SERVER['SERVER_PORT'] ?? 80) === 443);
    $scheme = $https ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $script = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? ''));
    $script = rtrim($script, '/');
    if (str_ends_with($script, '/public')) {
        $script = substr($script, 0, -7);
    }
    return $url = rtrim($scheme . '://' . $host . $script, '/');
}

function asset(string $path): string
{
    return base_url() . '/assets/' . ltrim($path, '/');
}

function url(string $path = ''): string
{
    $path = '/' . ltrim($path, '/');
    return base_url() . ($path === '/' ? '' : $path);
}

function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function slugify(string $text): string
{
    $text = strtolower(trim($text));
    $text = preg_replace('/[^a-z0-9]+/', '-', $text) ?? '';
    return trim($text, '-') ?: 'item';
}

function media_url(string $type, int|string $id, string $title): string
{
    $prefix = $type === 'tv' ? 'tv' : 'movie';
    return url($prefix . '/' . slugify($title) . '/' . $id);
}

function person_url(int|string $id, string $name): string
{
    return url('person/' . slugify($name) . '/' . $id);
}


function player_url(string $type, int|string $id, string $title, int $season = 1, int $episode = 1): string
{
    $prefix = $type === 'tv' ? 'tv' : 'movie';
    $path = '/player/' . $prefix . '/' . slugify($title) . '/' . $id;
    if ($type === 'tv') {
        $path .= '?s=' . max(1, $season) . '&e=' . max(1, $episode);
    }
    return url($path);
}

function img_url(?string $path, string $size = 'w600_and_h900_bestv2'): string
{
    if (!$path) {
        return asset('img/logo.webp');
    }
    if (str_starts_with($path, 'http')) {
        return $path;
    }
    return rtrim((string) config('tmdb_img'), '/') . '/' . $size . $path;
}

function year_of(?string $date): string
{
    return $date ? substr($date, 0, 4) : '';
}

function title_of(array $item): string
{
    return (string) ($item['title'] ?? $item['name'] ?? 'Untitled');
}

function date_of(array $item): string
{
    return (string) ($item['release_date'] ?? $item['first_air_date'] ?? '');
}

function truncate(string $text, int $len = 160): string
{
    $text = trim(preg_replace('/\s+/', ' ', $text) ?? '');
    if (mb_strlen($text) <= $len) {
        return $text;
    }
    return rtrim(mb_substr($text, 0, $len - 1)) . '…';
}

function current_path(): string
{
    $uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    $base = parse_url(base_url(), PHP_URL_PATH) ?: '';
    if ($base && str_starts_with($uri, $base)) {
        $uri = substr($uri, strlen($base)) ?: '/';
    }
    return '/' . trim($uri, '/');
}

function is_active(string $path): bool
{
    $current = current_path();
    $path = '/' . trim($path, '/');
    if ($path === '/home' || $path === '/') {
        return $current === '/' || $current === '/home';
    }
    return $current === $path || str_starts_with($current, $path . '/');
}

function view(string $viewName, array $vars = []): void
{
    // Floating transparent header on home + browse/list pages
    // NOTE: param must not be called $name — extract($vars) would skip page $name (e.g. person pages).
    if (!isset($vars['headerClass'])) {
        $floating = ['pages/home', 'pages/discover', 'pages/search', 'pages/top-imdb', 'pages/favorites', 'pages/watch', 'pages/games', 'pages/live', 'pages/anime', 'pages/person'];
        $vars['headerClass'] = in_array($viewName, $floating, true) ? 'absolute' : 'relative';
    }
    extract($vars, EXTR_SKIP);
    $file = __DIR__ . '/Views/' . str_replace('.', '/', $viewName) . '.php';
    if (!is_file($file)) {
        http_response_code(500);
        echo 'View not found: ' . e($viewName);
        exit;
    }
    $content = $file;
    $seo = $seo ?? [];
    $layoutName = $layout ?? 'main';
    $layoutFile = __DIR__ . '/Views/layouts/' . preg_replace('/[^a-z0-9_-]/i', '', (string) $layoutName) . '.php';
    if (!is_file($layoutFile)) {
        $layoutFile = __DIR__ . '/Views/layouts/main.php';
    }
    require $layoutFile;
}

function json_response(mixed $data, int $code = 200): never
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function redirect(string $path, int $code = 302): never
{
    header('Location: ' . (str_starts_with($path, 'http') ? $path : url($path)), true, $code);
    exit;
}
