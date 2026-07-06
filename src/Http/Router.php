<?php

declare(strict_types=1);

namespace Mailika\Http;

use Closure;

final class Router
{
    /**
     * @var array<string, array<string, callable(Request): Response>>
     */
    private array $routes = [];

    /**
     * @param callable(Request): Response $handler
     */
    public function get(string $path, callable $handler): void
    {
        $this->add('GET', $path, $handler);
    }

    /**
     * @param callable(Request): Response $handler
     */
    public function post(string $path, callable $handler): void
    {
        $this->add('POST', $path, $handler);
    }

    public function dispatch(Request $request): Response
    {
        $method = $request->method === 'HEAD' ? 'GET' : $request->method;
        $handler = $this->routes[$method][$request->path] ?? null;

        if ($handler === null) {
            return new Response('Not found', 404);
        }

        return $handler($request);
    }

    /**
     * @param callable(Request): Response $handler
     */
    private function add(string $method, string $path, callable $handler): void
    {
        $this->routes[$method][$path] = $handler instanceof Closure ? $handler : $handler(...);
    }
}
