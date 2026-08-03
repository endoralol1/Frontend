<?php
declare(strict_types=1);

final class Router
{
    /** @var array<int, array{methods: string[], pattern: string, handler: callable}> */
    private array $routes = [];

    public function get(string $pattern, callable $handler): void
    {
        $this->map(['GET'], $pattern, $handler);
    }

    public function post(string $pattern, callable $handler): void
    {
        $this->map(['POST'], $pattern, $handler);
    }

    public function map(array $methods, string $pattern, callable $handler): void
    {
        $this->routes[] = [
            'methods' => array_map('strtoupper', $methods),
            'pattern' => $pattern,
            'handler' => $handler,
        ];
    }

    public function dispatch(string $method, string $uri): void
    {
        $method = strtoupper($method);
        if ($method === 'HEAD') {
            $method = 'GET';
        }
        $path = '/' . trim(parse_url($uri, PHP_URL_PATH) ?: '/', '/');
        if ($path !== '/') {
            $path = rtrim($path, '/');
        }

        // Strip /public if served oddly
        if (str_starts_with($path, '/public')) {
            $path = substr($path, 7) ?: '/';
        }

        foreach ($this->routes as $route) {
            if (!in_array(strtoupper($method), $route['methods'], true)) {
                continue;
            }
            $regex = '@^' . preg_replace('@\{([a-zA-Z_]+)\}@', '(?P<$1>[^/]+)', $route['pattern']) . '$@';
            if (!preg_match($regex, $path, $m)) {
                continue;
            }
            $params = array_filter($m, 'is_string', ARRAY_FILTER_USE_KEY);
            ($route['handler'])($params);
            return;
        }

        http_response_code(404);
        view('pages/404', [
            'seo' => [
                'title' => 'Page Not Found | ' . config('site_name'),
                'description' => 'The page you are looking for does not exist.',
                'robots' => 'noindex, follow',
            ],
        ]);
    }
}
