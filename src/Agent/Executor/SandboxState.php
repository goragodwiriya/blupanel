<?php

declare(strict_types=1);

namespace Phpcp\Agent\Executor;

/**
 * Sandbox mode's simulated state — kept as JSON files under the prefix
 *
 * Why a file instead of memory: the agent forks a child per connection, so
 * children never share memory — a restart command, then a page refresh, still
 * has to show the result.
 */
final class SandboxState
{
    public function __construct(private readonly string $prefix)
    {
    }

    public function prefix(): string
    {
        return $this->prefix;
    }

    private function stateDir(): string
    {
        return $this->prefix . '/state';
    }

    private function file(string $name): string
    {
        return $this->stateDir() . '/' . $name . '.json';
    }

    /** @return array<string,mixed> */
    public function read(string $name, array $default = []): array
    {
        $file = $this->file($name);
        if (!is_file($file)) {
            return $default;
        }

        $raw = @file_get_contents($file);
        if ($raw === false || $raw === '') {
            return $default;
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : $default;
    }

    /** @param array<string,mixed> $data */
    public function write(string $name, array $data): void
    {
        $dir = $this->stateDir();
        if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new \RuntimeException("Failed to create the sandbox state directory: {$dir}");
        }

        $file = $this->file($name);
        $temp = $file . '.tmp';
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($json === false || @file_put_contents($temp, $json, LOCK_EX) === false) {
            throw new \RuntimeException("Failed to save the sandbox state: {$file}");
        }

        @rename($temp, $file);
    }

    /** @return array<string,array<string,mixed>> */
    public function services(): array
    {
        $services = $this->read('services');

        return $services === [] ? self::defaultServices() : $services;
    }

    /** @param array<string,array<string,mixed>> $services */
    public function saveServices(array $services): void
    {
        $this->write('services', $services);
    }

    /**
     * The sandbox's default set of services — based on what PROMPT.md requires to
     * be present, plus the PHP versions actually found on the development machine
     *
     * @return array<string,array<string,mixed>>
     */
    public static function defaultServices(): array
    {
        $now = time();
        $day = 86400;

        return [
            'apache2' => self::service('Apache HTTP Server', 'active', $now - 12 * $day, 68_157_440, '2.4.58'),
            'nginx' => self::service('Nginx HTTP Server', 'inactive', null, 0, '1.24.0'),
            'php8.5-fpm' => self::service('PHP 8.5 FastCGI Process Manager', 'inactive', null, 0, '8.5.0'),
            'php8.4-fpm' => self::service('PHP 8.4 FastCGI Process Manager', 'active', $now - 12 * $day, 194_641_920, '8.4.23'),
            'php8.3-fpm' => self::service('PHP 8.3 FastCGI Process Manager', 'active', $now - 12 * $day, 132_120_576, '8.3.19'),
            'php7.4-fpm' => self::service('PHP 7.4 FastCGI Process Manager', 'active', $now - 3 * $day, 57_671_680, '7.4.33'),
            'mariadb' => self::service('MariaDB 10.11 database server', 'active', $now - 12 * $day, 524_288_000, '10.11.14'),
            'cron' => self::service('Regular background program processing daemon', 'active', $now - 30 * $day, 4_194_304, '3.0'),
        ];
    }

    /** @return array<string,mixed> */
    private static function service(
        string $description,
        string $active,
        ?int $since,
        int $memory,
        string $version,
    ): array {
        return [
            'description' => $description,
            'active' => $active,
            'sub' => $active === 'active' ? 'running' : 'dead',
            'enabled' => $active === 'active' ? 'enabled' : 'disabled',
            'since' => $since,
            'memory' => $memory,
            'pid' => $active === 'active' ? random_int(400, 9999) : 0,
            'version' => $version,
        ];
    }
}
