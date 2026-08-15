<?php

declare(strict_types=1);

namespace Phpcp\Kernel;

/**
 * A static route table — ARCHITECTURE §3.2
 *
 * Declared as an array in code, not scanned from a directory or built from attribute
 * reflection — that lets opcache cache the whole file, and lets "every route the
 * system has" be read start to finish on one screen, which matters for checking that
 * every route has a permission attached.
 */
final class Router
{
    /** @var list<Route> */
    private array $routes = [];

    /** @var array<string,array{route:Route,regex:string,keys:list<string>}> */
    private array $compiled = [];

    public function add(Route $route): void
    {
        $this->routes[] = $route;

        [$regex, $keys] = self::compile($route->path);
        $this->compiled[$route->method . ' ' . $route->path] = [
            'route' => $route,
            'regex' => $regex,
            'keys' => $keys,
        ];
    }

    /** @return list<Route> */
    public function routes(): array
    {
        return $this->routes;
    }

    public function findByName(string $name): ?Route
    {
        foreach ($this->routes as $route) {
            if ($route->name === $name) {
                return $route;
            }
        }

        return null;
    }

    /**
     * Matches a request against the route table
     *
     * @return array{route:Route,params:array<string,string>}|null
     */
    public function match(string $method, string $path): ?array
    {
        // HEAD shares GET's table
        $method = $method === 'HEAD' ? 'GET' : $method;

        foreach ($this->compiled as $entry) {
            if ($entry['route']->method !== $method) {
                continue;
            }

            if (preg_match($entry['regex'], $path, $matches) !== 1) {
                continue;
            }

            $params = [];
            foreach ($entry['keys'] as $index => $key) {
                $params[$key] = $matches[$index + 1] ?? '';
            }

            return ['route' => $entry['route'], 'params' => $params];
        }

        return null;
    }

    /** true = this path exists under a different method (respond 405 instead of 404) */
    public function pathExists(string $path): bool
    {
        foreach ($this->compiled as $entry) {
            if (preg_match($entry['regex'], $path) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Turns /sites/{id} into a regex
     *
     * @return array{0:string,1:list<string>}
     */
    private static function compile(string $path): array
    {
        $keys = [];

        $regex = preg_replace_callback(
            '/\{([a-z_]+)\}/',
            static function (array $m) use (&$keys): string {
                $keys[] = $m[1];

                // Constrained to match one path segment only — never crosses a /
                return '([^/]+)';
            },
            $path,
        ) ?? $path;

        return ['#^' . $regex . '$#u', $keys];
    }

    /** Builds a URL from a route name — used in templates instead of writing the path out directly */
    public function url(string $name, array $params = []): string
    {
        $route = $this->findByName($name);
        if ($route === null) {
            return '/';
        }

        $path = $route->path;
        foreach ($params as $key => $value) {
            $path = str_replace('{' . $key . '}', rawurlencode((string) $value), $path);
        }

        return $path;
    }
}
