<?php

declare(strict_types=1);

namespace Phpcp\Domain;

/**
 * Log sources the panel is willing to read — an allowlist of exact paths.
 *
 * Covers every kind PROMPT.md asks for: Access, Error, PHP, System, Login, Audit.
 *
 * Security: a caller can never name a path. All they pick is a *key* from this list,
 * which removes path traversal and arbitrary file reads at the source rather than
 * accepting a path and validating it afterwards — the shape people get wrong.
 *
 * **There are two sets of sources.** `all()` holds machine-level logs, which live at
 * fixed paths. Per-website logs live inside the owner's home, so their paths are
 * derived from {@see \Phpcp\Domain\Site} at call time instead of being written down
 * here. The principle is unchanged: the caller always sends a key (`site:<id>:<kind>`),
 * never a path.
 *
 * Labels and groups are English keys translated by the caller — see
 * {@see \Phpcp\Http\V2\LogsController::availableSources()}.
 */
final class LogCatalog
{
    /** Permission needed to read a website's logs. *Which* websites is a separate question. */
    public const SITE_PERMISSION = 'log.view';

    /**
     * @return array<string,array{label:string,path:string,permission:string,format:string,group:string}>
     */
    public static function all(): array
    {
        return [
            /*
             * **These two are not customer traffic.** Every vhost the panel writes sends
             * `CustomLog`/`ErrorLog` into the site owner's home (see `Site::accessLog()`),
             * so the machine-level files hold only requests that matched no vhost, plus
             * server-level messages. The label used to read plain "Access Log (Apache)",
             * which people read as whole-machine traffic and then concluded, wrongly,
             * that nobody was visiting their sites at all.
             */
            'access' => [
                'label' => 'Access Log (requests that reached no website)',
                'path' => '/var/log/apache2/access.log',
                'permission' => 'log.view',
                'format' => 'access',
                'group' => 'Web server',
            ],
            'error' => [
                'label' => 'Error Log (server level)',
                'path' => '/var/log/apache2/error.log',
                'permission' => 'log.view',
                'format' => 'apache',
                'group' => 'Web server',
            ],
            'php' => [
                'label' => 'PHP-FPM Log',
                'path' => '/var/log/php8.4-fpm.log',
                'permission' => 'log.view',
                'format' => 'syslog',
                'group' => 'PHP',
            ],
            'mysql' => [
                'label' => 'MariaDB Log',
                'path' => '/var/log/mysql/error.log',
                'permission' => 'log.view',
                'format' => 'syslog',
                'group' => 'Database',
            ],
            'system' => [
                'label' => 'System Log',
                'path' => '/var/log/syslog',
                'permission' => 'log.view',
                'format' => 'syslog',
                'group' => 'System',
            ],
            'auth' => [
                'label' => 'Login Log (SSH / sudo)',
                'path' => '/var/log/auth.log',
                'permission' => 'log.view',
                'format' => 'syslog',
                'group' => 'System',
            ],

            // The panel's own logs — readable, never writable.
            // No conflict with SelfProtection: that forbids *changes*, while this is a
            // read-only allowlist of named files that still has to clear a permission.
            //
            // `panel_log` tells LogTail to ask Paths for the real location instead of
            // using the constant below, because the portable layout keeps logs inside
            // the project directory rather than /var/log.
            'panel' => [
                'label' => 'Control Panel Log',
                'path' => '/var/log/phpcp/panel.log',
                'panel_log' => 'panel',
                'permission' => 'log.view',
                'format' => 'phpcp',
                'group' => 'Control Panel',
            ],
            'agent' => [
                'label' => 'Agent Log',
                'path' => '/var/log/phpcp/agent.log',
                'panel_log' => 'agent',
                'permission' => 'log.view',
                'format' => 'phpcp',
                'group' => 'Control Panel',
            ],
            'audit' => [
                'label' => 'Audit Log',
                'path' => '/var/log/phpcp/audit.log',
                'panel_log' => 'audit',
                'permission' => 'audit.view',
                'format' => 'json',
                'group' => 'Control Panel',
            ],
        ];
    }

    public static function has(string $key): bool
    {
        return array_key_exists($key, self::all());
    }

    /**
     * Logs each website owns — kind => label and format.
     *
     * `php` belongs to an **account × PHP version**, not to one website: pools have been
     * shared since migration 0006, so sibling sites of the same owner on the same PHP
     * version point at one file. The label says so outright rather than leaving someone
     * puzzled that site A's log contains site B's errors.
     *
     * @return array<string,array{label:string,format:string}>
     */
    public static function siteKinds(): array
    {
        return [
            'access' => ['label' => 'Access Log', 'format' => 'access'],
            'error' => ['label' => 'Error Log', 'format' => 'apache'],
            'php' => ['label' => 'PHP Error Log (whole account)', 'format' => 'syslog'],
        ];
    }

    /** Key for a per-site log — the only shape {@see self::parseSiteKey()} accepts. */
    public static function siteKey(int $siteId, string $kind): string
    {
        return 'site:'.$siteId.':'.$kind;
    }

    /**
     * Parse a per-site log key, or null when the key is not one.
     *
     * These can never collide with machine-level keys, which are constrained to
     * `^[a-z][a-z0-9_]+$` and so contain no `:` (guarded by
     * tests/security/ServerBoundaryTest.php).
     *
     * **Parsing does not mean the website exists or may be read.** This checks shape
     * only; identity and permission belong to the caller, which has the database.
     *
     * @return array{site_id:int,kind:string}|null
     */
    public static function parseSiteKey(string $key): ?array
    {
        if (preg_match('/^site:([1-9][0-9]{0,8}):([a-z]+)$/', $key, $matches) !== 1) {
            return null;
        }

        if (!array_key_exists($matches[2], self::siteKinds())) {
            return null;
        }

        return ['site_id' => (int) $matches[1], 'kind' => $matches[2]];
    }

    /** @return array{label:string,path:string,permission:string,format:string,group:string}|null */
    public static function get(string $key): ?array
    {
        return self::all()[$key] ?? null;
    }

    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys(self::all());
    }

    /**
     * Log sources this role may open.
     *
     * @return array<string,array{label:string,path:string,permission:string,format:string,group:string}>
     */
    public static function forRole(string $role): array
    {
        return array_filter(
            self::all(),
            static fn (array $source): bool => \Phpcp\Security\Permissions::roleHas($role, $source['permission']),
        );
    }

    /**
     * Severity of one log line — used to colour it in the viewer.
     * Judged from words that actually appear in each service's log format.
     */
    public static function levelOf(string $line): string
    {
        $lower = strtolower($line);

        return match (true) {
            str_contains($lower, '[error]') || str_contains($lower, 'error')
                || str_contains($lower, 'fatal') || str_contains($lower, 'critical')
                || str_contains($lower, 'denied') || str_contains($lower, ' 500 ') => 'error',

            str_contains($lower, '[warn]') || str_contains($lower, 'warning')
                || str_contains($lower, 'deprecated') || str_contains($lower, ' 404 ')
                || str_contains($lower, ' 403 ') => 'warn',

            str_contains($lower, '[notice]') || str_contains($lower, 'notice')
                || str_contains($lower, '[info]') => 'info',

            str_contains($lower, ' 200 ') || str_contains($lower, '"ok"')
                || str_contains($lower, 'started') => 'ok',

            default => '',
        };
    }

    /** @return list<string> levels offered as a filter in the UI */
    public static function levels(): array
    {
        return ['error', 'warn', 'info', 'ok'];
    }
}
