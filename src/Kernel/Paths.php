<?php

declare (strict_types = 1);

namespace Phpcp\Kernel;

/**
 * Every location in the system, gathered in one place
 *
 * Supports 2 layouts:
 *   system   — a real install per ARCHITECTURE §13 (/etc/phpcp, /var/lib/phpcp, ...)
 *   portable — everything lives under the project directory; used for development
 *              and testing without installing and without root
 *
 * Why portable has to exist: a developer's machine (the one this system was designed
 * on, for instance) needs to run the panel and exercise every page without touching
 * /etc and without sudo — see ARCHITECTURE §6.
 */
final class Paths
{
    public const LAYOUT_SYSTEM = 'system';
    public const LAYOUT_PORTABLE = 'portable';

    /** Default website storage location — changeable with the sites.dir setting */
    public const DEFAULT_SITES_DIR = '/srv/phpcp/sites';

    /**
     * Hosting users' home directory — changeable with the sites.users_dir setting
     *
     * As of migration 0006, website files no longer live at `<sites.dir>/<domain>`
     * but under the owner's home, because uid and disk quota are bound to the user,
     * not the site. `sitesDir()` still exists to read files under the older layout
     * that haven't been migrated.
     *
     * **Defaults to `/home`**, the location every Unix admin expects to find user
     * homes, and the same one cPanel/DirectAdmin/Plesk use. It used to be
     * `/srv/phpcp/users`, which was buried and matched nobody's expectations —
     * neither a migrating customer's nor an admin's who ssh'd into the machine.
     */
    public const DEFAULT_USERS_DIR = '/home';

    /**
     * The website storage location actually in use
     *
     * Static because Site is a value object constructed from dozens of places across
     * the system (capabilities, controllers, the CLI, seeders) — threading a Config
     * through every one of them would change Site's signature everywhere for no
     * added security at all. The same pattern already used by
     * SelfProtection::protectAlso().
     */
    private static string $sitesDir = self::DEFAULT_SITES_DIR;

    /** The users' home directory actually in use — static for the same reason as $sitesDir */
    private static string $usersDir = self::DEFAULT_USERS_DIR;

    /**
     * The default file layout under a user's home — see {@see \Phpcp\Domain\SiteLayout}
     *
     * Kept as a raw string, not the enum, because `Paths` sits in the Kernel layer,
     * which must not depend on Domain — the enum is what interprets the value and
     * decides where an unrecognised one falls.
     */
    private static string $siteLayout = '';

    private function __construct(
        public readonly string $layout,
        public readonly string $root,
        public readonly string $etc,
        public readonly string $data,
        public readonly string $log,
        public readonly string $run,
        public readonly string $sandbox,
        public readonly string $sites,
    ) {
    }

    /**
     * @param string $layout
     * @param string $root
     */
    public static function forLayout(string $layout, string $root): self
    {
        if ($layout === self::LAYOUT_SYSTEM) {
            return new self(
                layout: self::LAYOUT_SYSTEM,
                root: $root,
                etc: '/etc/phpcp',
                data: '/var/lib/phpcp',
                log: '/var/log/phpcp',
                run: '/run/phpcp',
                sandbox: '/opt/phpcp-sandbox',
                sites: self::sitesDir(),
            );
        }

        $var = $root.'/var';

        return new self(
            layout: self::LAYOUT_PORTABLE,
            root: $root,
            etc: $root.'/etc',
            data: $var.'/lib',
            log: $var.'/log',
            run: $var.'/run',
            sandbox: $var.'/sandbox',
            sites: $var.'/sites',
        );
    }

    /**
     * Guesses the layout from the machine's state — /etc/phpcp/config.php existing
     * means a system install has already happened
     */
    public static function detect(string $root): self
    {
        return is_file('/etc/phpcp/config.php')
            ? self::forLayout(self::LAYOUT_SYSTEM, $root)
            : self::forLayout(self::LAYOUT_PORTABLE, $root);
    }

    /** The website storage location actually in use — a logical path; sandbox mode adds the prefix later */
    public static function sitesDir(): string
    {
        return self::$sitesDir;
    }

    /** The users' home directory actually in use — a logical path; sandbox mode adds the prefix later */
    public static function usersDir(): string
    {
        return self::$usersDir;
    }

    /** Changes the users' home directory — called only from Config::load(), same rule as useSitesDir() */
    public static function useUsersDir(string $dir): void
    {
        self::$usersDir = self::assertHostingDir($dir, 'sites.users_dir') ?? self::DEFAULT_USERS_DIR;
    }

    /** The default file layout — empty means unset, letting SiteLayout choose */
    public static function siteLayout(): string
    {
        return self::$siteLayout;
    }

    /** Called only from Config::load() — not validated here; the enum decides */
    public static function useSiteLayout(string $value): void
    {
        self::$siteLayout = trim($value);
    }

    /**
     * A path headed into a vhost, an FPM pool, or open_basedir has to be clean from the source
     *
     * `..` and relative paths are rejected right here, not left to surface at the
     * destination where nothing checks for them anymore.
     *
     * @return string|null null = not set, the caller should use its default
     */
    private static function assertHostingDir(string $dir, string $setting): ?string
    {
        $dir = rtrim(trim($dir), '/');

        if ($dir === '') {
            return null;
        }

        if (!str_starts_with($dir, '/') || str_contains($dir, "\0")) {
            throw new \RuntimeException("{$setting} must be an absolute path: {$dir}");
        }

        if (in_array('..', explode('/', $dir), true)) {
            throw new \RuntimeException("{$setting} must not contain ..: {$dir}");
        }

        return $dir;
    }

    /**
     * Changes the website storage location — called only from Config::load()
     *
     * This value is used to build vhost paths, FPM pool paths, and open_basedir, so
     * `..` and relative paths must be rejected right here, not left to surface at
     * the destination.
     */
    public static function useSitesDir(string $dir): void
    {
        self::$sitesDir = self::assertHostingDir($dir, 'sites.dir') ?? self::DEFAULT_SITES_DIR;
    }

    /**
     * @return mixed
     */
    public function configFile(): string
    {
        return $this->etc.'/config.php';
    }

    /**
     * @return mixed
     */
    public function database(): string
    {
        return $this->data.'/panel.db';
    }

    /**
     * @return mixed
     */
    public function agentSocket(): string
    {
        return $this->run.'/agent.sock';
    }

    /**
     * @return mixed
     */
    public function agentPidFile(): string
    {
        return $this->run.'/agentd.pid';
    }

    /**
     * @param string $name
     * @return mixed
     */
    public function logFile(string $name): string
    {
        return $this->log.'/'.$name.'.log';
    }

    /**
     * A panel helper binary that other programs on the machine can call — `bin/<name>`
     *
     * **Not `/usr/local/bin`** — the installer only symlinks `phpcp` itself there; the
     * rest stay inside the install directory. Get the path wrong and the caller fails
     * silently (fail2ban calling an action that can't find the file just logs it to
     * its own log and moves on, so the admin has no way to know a notification was
     * never sent at all).
     */
    public function binary(string $name): string
    {
        return $this->root.'/bin/'.$name;
    }

    /**
     * @return mixed
     */
    public function migrations(): string
    {
        return $this->root.'/db/migrations';
    }

    /**
     * @return mixed
     */
    public function templates(): string
    {
        return $this->root.'/templates';
    }

    /**
     * The SPA's (Now.js) file location
     *
     * Lives under `public/assets/` **not `public/app/`**, even though the screen's
     * URL is `/app/*` — because the SPA's URL must not match a real directory on
     * disk, or Apache's `mod_dir` would handle it first and `FallbackResource` would
     * never run (it skips any URL pointing at a file or directory that actually
     * exists). The result would be `/app/` returning Apache's own 404, with PHP
     * never seeing that request at all. See `SpaController` alongside this.
     */
    public function spa(): string
    {
        return $this->root.'/public/assets/spa';
    }

    /**
     * The phpMyAdmin copy the installer wires up under the panel's own web root
     *
     * A symlink to the system's /usr/share/phpmyadmin, not a separately downloaded
     * copy, so it gets security updates through the distro's own apt (see install.sh §5.1).
     */
    public function phpMyAdmin(): string
    {
        return $this->root.'/public/phpmyadmin';
    }

    /** Is phpMyAdmin actually usable — decides whether to show the button to open it */
    public function hasPhpMyAdmin(): bool
    {
        return is_file($this->phpMyAdmin().'/index.php');
    }

    /**
     * Resting place for backup files pulled in from an offsite destination — **no
     * longer permanent storage**
     *
     * A customer's backup files live at their own `<home>/backup` (PLAN-BACKUP-V2
     * item B1). What's left here is for the brief window while `backup.import` has
     * pulled a file down but doesn't yet know which home it belongs to, until
     * `backup.json` inside it has been read. After that the file is moved into the
     * home immediately, so this stays empty under normal conditions.
     */
    public function backups(): string
    {
        return $this->data.'/backups';
    }

    /**
     * Directories that must exist before the system can run, with the permissions each needs
     *
     * @return array<string,int> path => mode
     */
    public function required(): array
    {
        return [
            $this->etc => 0750,
            $this->data => 0750,
            $this->backups() => 0750,
            $this->log => 0750,
            $this->run => 0750
        ];
    }

    public function ensureDirectories(): void
    {
        foreach ($this->required() as $dir => $mode) {
            if (!is_dir($dir) && !@mkdir($dir, $mode, true) && !is_dir($dir)) {
                throw new \RuntimeException("Failed to create directory: {$dir}");
            }
        }
    }
}
