<?php

declare(strict_types=1);

namespace Phpcp\Agent;

/**
 * Stops the control panel from managing itself — ARCHITECTURE §5.3
 *
 * Why: the panel runs on its own stack, separate from the system it administers
 * (§5.2). Allowed to stop its own services, a user could lock themselves out with
 * no way back.
 *
 * Checked at tier 2, not the UI — even hitting the API directly, or a UI bug that
 * renders the button anyway, the command still gets rejected here.
 */
final class SelfProtection
{
    /** The panel's own systemd units (no need for .service) */
    private const UNITS = [
        'phpcp-agentd',
        'phpcp-web',
        'phpcp-fpm',
        // The scheduler and its jobs — if this could be stopped from the web UI,
        // the auto-recovery mechanism would go silent while the admin still saw an
        // entirely normal-looking screen, which is the most dangerous state of all
        'phpcp-scheduler',
        'phpcp-scheduler.timer',
    ];

    /** The panel's own directories */
    private const PATHS = [
        '/etc/phpcp',
        '/var/lib/phpcp',
        '/usr/share/phpcp',
        '/var/log/phpcp',
        '/run/phpcp',
    ];

    /** System users that must never be touched */
    private const USERS = [
        'phpcp-web',
        'root',
        'daemon',
        'bin',
        'sys',
        'www-data',
    ];

    /*
     * **No exceptions** — there used to be an `allowAlso()` that poked a hole
     * opening `/var/lib/phpcp/backups` up to the file manager (commit dc4425e),
     * because backup files piled up inside the panel's own space · that exception
     * nearly became a path to `panel.db` via `..`, and needed a permanent test
     * watching that it stayed narrow enough.
     *
     * Ever since backup files moved to the customer's own `<home>/backup`
     * (PLAN-BACKUP-V2 §4.1), nothing belonging to a user is left under the
     * panel's own directory anymore · so that hole was filled back in.
     * **Protection is back to being an unconditional rule**, the only shape that
     * is actually easy to verify · if something ever needs opening up again, move
     * that thing out of the panel's space instead of poking a new hole.
     */

    /** @var list<string> extra paths registered at bootstrap (e.g. the portable layout) */
    private static array $extraPaths = [];

    /** Registers an additional panel path — used when the layout is portable, where the path isn't under /etc */
    public static function protectAlso(string ...$paths): void
    {
        foreach ($paths as $path) {
            $normalized = rtrim($path, '/');
            if ($normalized !== '' && !in_array($normalized, self::$extraPaths, true)) {
                self::$extraPaths[] = $normalized;
            }
        }
    }

    /** @return list<string> */
    public static function protectedPaths(): array
    {
        return array_values(array_unique([...self::PATHS, ...self::$extraPaths]));
    }

    /** @return list<string> */
    public static function protectedUnits(): array
    {
        return self::UNITS;
    }

    public static function isProtectedUnit(string $unit): bool
    {
        $name = str_ends_with($unit, '.service') ? substr($unit, 0, -8) : $unit;

        return in_array($name, self::UNITS, true);
    }

    public static function assertUnit(string $unit): void
    {
        if (self::isProtectedUnit($unit)) {
            throw new ProtectedResource(
                'Cannot manage the control panel\'s own service — use the `phpcp self:restart` command on the machine instead'
            );
        }
    }

    public static function isProtectedPath(string $path): bool
    {
        if ($path === '') {
            return false;
        }

        // Must compare after resolving symlinks too, so /tmp/x -> /etc/phpcp gets caught as well
        $resolved = realpath($path);
        $candidates = $resolved === false ? [$path] : [$path, $resolved];

        foreach ($candidates as $candidate) {
            $candidate = rtrim($candidate, '/');

            foreach (self::protectedPaths() as $protected) {
                if ($candidate === $protected || str_starts_with($candidate . '/', $protected . '/')) {
                    return true;
                }
            }
        }

        return false;
    }

    public static function assertPath(string $path): void
    {
        if (self::isProtectedPath($path)) {
            throw new ProtectedResource('This path belongs to the control panel and cannot be modified');
        }
    }

    public static function isProtectedUser(string $user): bool
    {
        return in_array($user, self::USERS, true);
    }

    public static function assertUser(string $user): void
    {
        if (self::isProtectedUser($user)) {
            throw new ProtectedResource("Managing this system user is not allowed: {$user}");
        }
    }

    /**
     * Filters the panel's own services out of a list before it's sent for display
     * — so the panel's own services never appear on the Services page at all, not
     * just have their button hidden
     *
     * @param list<string> $units
     * @return list<string>
     */
    public static function filterUnits(array $units): array
    {
        return array_values(array_filter(
            $units,
            static fn (string $unit): bool => !self::isProtectedUnit($unit),
        ));
    }
}
