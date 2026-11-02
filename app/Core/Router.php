<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Minimal router with support for route parameters ({id}).
 * Uses callbacks or [Controller::class, 'method'] pairs.
 */
final class Router
{
    /** @var array<string, array<string, array{handler:mixed, params:string[]}>> */
    private array $routes = ['GET' => [], 'POST' => []];

    public function get(string $pattern, mixed $handler): void
    {
        $this->add('GET', $pattern, $handler);
    }

    public function post(string $pattern, mixed $handler): void
    {
        $this->add('POST', $pattern, $handler);
    }

    private function add(string $method, string $pattern, mixed $handler): void
    {
        // {id} -> regex group, remember the parameter names
        preg_match_all('/\{(\w+)\}/', $pattern, $m);
        $params = $m[1];
        $regex = '#^' . preg_replace('/\{\w+\}/', '([^/]+)', rtrim($pattern, '/')) . '/?$#';
        $this->routes[$method][$regex] = ['handler' => $handler, 'params' => $params];
    }

    public function dispatch(string $method, string $uri): void
    {
        $uri = rtrim($uri, '/');
        if ($uri === '') {
            $uri = '/';
        }

        foreach ($this->routes[$method] ?? [] as $regex => $route) {
            if (preg_match($regex, $uri, $matches)) {
                array_shift($matches);
                $args = array_combine($route['params'], $matches) ?: [];
                $this->invoke($route['handler'], $args);
                return;
            }
        }

        http_response_code(404);
        View::render('errors/404', [], null);
    }

    private function invoke(mixed $handler, array $args): void
    {
        if (is_array($handler)) {
            [$class, $action] = $handler;
            (new $class())->$action(...array_values($args));
            return;
        }
        $handler(...array_values($args));
    }
}
