<?php

declare(strict_types=1);

namespace Phpcp\Kernel;

/**
 * ตารางเส้นทางแบบคงที่ — ARCHITECTURE §3.2
 *
 * ประกาศเป็น array ในโค้ด ไม่ scan directory ไม่ใช้ attribute reflection
 * ทำให้ opcache แคชได้ทั้งไฟล์ และทำให้ "ทุกเส้นทางที่มีในระบบ" อ่านจบได้ในหน้าจอเดียว
 * ซึ่งสำคัญกับการตรวจสอบว่า permission ถูกผูกครบทุกเส้นทางหรือยัง
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
     * จับคู่คำขอกับเส้นทาง
     *
     * @return array{route:Route,params:array<string,string>}|null
     */
    public function match(string $method, string $path): ?array
    {
        // HEAD ใช้ตารางเดียวกับ GET
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

    /** true = มี path นี้อยู่แต่คนละ method (ตอบ 405 แทน 404) */
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
     * แปลง /sites/{id} เป็น regex
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

                // จำกัดให้ตรงกับหนึ่งส่วนของ path เท่านั้น ไม่ให้ข้าม / ได้
                return '([^/]+)';
            },
            $path,
        ) ?? $path;

        return ['#^' . $regex . '$#u', $keys];
    }

    /** สร้าง URL จากชื่อเส้นทาง — ใช้ในเทมเพลตแทนการพิมพ์ path ตรง ๆ */
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
