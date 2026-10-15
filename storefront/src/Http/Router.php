<?php

declare(strict_types=1);

namespace Storefront\Http;

/**
 * Maps a method and path to a handler: `/product/{slug}` captures `slug`.
 *
 * A path that exists under another method is a 405, not a 404.
 */
final class Router
{
    /** @var list<array{method: string, regex: string, names: list<string>, handler: callable}> */
    private array $routes = [];

    public function get(string $pattern, callable $handler): void
    {
        $this->add('GET', $pattern, $handler);
    }

    public function post(string $pattern, callable $handler): void
    {
        $this->add('POST', $pattern, $handler);
    }

    public function add(string $method, string $pattern, callable $handler): void
    {
        $names = [];
        $regex = preg_replace_callback('/\{([a-z_]+)\}/', function (array $match) use (&$names): string {
            $names[] = $match[1];

            return '([^/]+)';
        }, $pattern);

        $this->routes[] = [
            'method' => $method,
            'regex' => '#^'.$regex.'$#',
            'names' => $names,
            'handler' => $handler,
        ];
    }

    /**
     * @return array{handler: callable, params: array<string, string>}|int a match, or 404 / 405
     */
    public function match(string $method, string $path): array|int
    {
        $pathMatched = false;

        foreach ($this->routes as $route) {
            if (preg_match($route['regex'], $path, $matches) !== 1) {
                continue;
            }

            $pathMatched = true;

            if ($route['method'] !== $method && ! ($method === 'HEAD' && $route['method'] === 'GET')) {
                continue;
            }

            $params = [];

            foreach ($route['names'] as $index => $name) {
                $params[$name] = rawurldecode($matches[$index + 1]);
            }

            return ['handler' => $route['handler'], 'params' => $params];
        }

        return $pathMatched ? 405 : 404;
    }
}
