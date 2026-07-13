<?php
declare(strict_types=1);

namespace App;

final class Router
{
    private array $routes = [];

    public function get(string $pattern, callable $handler): void
    {
        $this->routes[] = ['GET', $pattern, $handler];
    }

    public function post(string $pattern, callable $handler): void
    {
        $this->routes[] = ['POST', $pattern, $handler];
    }

    public function dispatch(string $method, string $uri): void
    {
        $path = '/' . trim((string) parse_url($uri, PHP_URL_PATH), '/');
        $base = '/' . trim((string) parse_url((string) (getenv('APP_BASE') ?: ''), PHP_URL_PATH), '/');
        if ($base !== '/' && ($path === $base || str_starts_with($path, $base . '/'))) {
            $path = substr($path, strlen($base)) ?: '/';
        }
        if ($path !== '/') {
            $path = rtrim($path, '/');
        }
        foreach ($this->routes as [$routeMethod, $pattern, $handler]) {
            if ($routeMethod !== $method) {
                continue;
            }
            $regex = preg_replace('#\{([a-zA-Z_][a-zA-Z0-9_]*)\}#', '(?P<$1>[^/]+)', $pattern);
            if (preg_match('#^' . $regex . '$#', $path, $matches)) {
                $params = array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);
                $handler(...array_values($params));
                return;
            }
        }
        http_response_code(404);
        render('errors/404', ['title' => 'Page not found']);
    }
}
