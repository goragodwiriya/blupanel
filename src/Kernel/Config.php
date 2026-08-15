<?php

declare (strict_types = 1);

namespace Phpcp\Kernel;

/**
 * System settings, read once at bootstrap
 *
 * Config file search order:
 *   1. the PHPCP_CONFIG environment variable
 *   2. /etc/phpcp/config.php            (system install)
 *   3. <root>/etc/config.php            (portable)
 *   None found → falls back to portable + sandbox defaults, so a fresh clone runs immediately
 */
final class Config
{
    /** @var list<string> config files that exist but couldn't be read — see locate() */
    private static array $unreadable = [];

    /** @param array<string,mixed> $values */
    private function __construct(
        private readonly array $values,
        public readonly Paths $paths,
        public readonly Mode $mode,
        public readonly ?string $sourceFile,
    ) {
    }

    /**
     * @param string $root
     */
    public static function load(string $root): self
    {
        $file = self::locate($root);
        $values = [];

        if ($file !== null) {
            $loaded = require $file;
            if (!is_array($loaded)) {
                throw new \RuntimeException("Config file must return an array: {$file}");
            }
            $values = $loaded;
        }

        $values = array_replace_recursive(self::defaults(), $values);

        // Must happen before Paths is built — both Paths and Site read this value from the same place
        Paths::useSitesDir((string) (($values['sites'] ?? [])['dir'] ?? ''));
        Paths::useUsersDir((string) (($values['sites'] ?? [])['users_dir'] ?? ''));
        // Careful: `sites.layout` (a website's file layout) is unrelated to the
        // top-level `layout`, which is the **install's** layout (system/portable) —
        // similar names, nothing to do with each other
        Paths::useSiteLayout((string) (($values['sites'] ?? [])['layout'] ?? ''));

        $layout = (string) ($values['layout'] ?? '');
        $paths = $layout !== ''
            ? Paths::forLayout($layout, $root)
            : Paths::detect($root);

        // Overriding is only ever allowed in development — production must come from the file alone
        $modeRaw = (string) ($values['mode'] ?? Mode::Sandbox->value);
        $mode = Mode::tryFrom($modeRaw) ?? throw new \RuntimeException("Invalid mode: {$modeRaw} (valid values: production, sandbox, dryrun)");

        return new self($values, $paths, $mode, $file);
    }

    /**
     * @param string $root
     * @return mixed
     */
    private static function locate(string $root): ?string
    {
        $candidates = array_filter([
            getenv('PHPCP_CONFIG') ?: null,
            '/etc/phpcp/config.php',
            $root.'/etc/config.php'
        ]);

        foreach ($candidates as $candidate) {
            if (is_file($candidate) && is_readable($candidate)) {
                return $candidate;
            }

            // The file exists but can't be read = a permissions problem, not "not
            // installed yet" — remembered so an admin can be told.
            //
            // Staying silent here would fall back to the sandbox defaults with no
            // database, and every screen would look "empty but fine" even though the
            // real config exists and is complete (happened for real when install.sh
            // forgot to chown config.php to root:phpcp).
            if (is_file($candidate)) {
                self::$unreadable[] = $candidate;
            }
        }

        return null;
    }

    /**
     * Config files that genuinely exist but this process can't read — empty when
     * there's no permission problem
     *
     * @return list<string>
     */
    public static function unreadableCandidates(): array
    {
        return self::$unreadable;
    }

    /** @return array<string,mixed> */
    public static function defaults(): array
    {
        return [
            'mode' => Mode::Sandbox->value,
            'layout' => '',
            'panel' => [
                'port' => 8443,
                'base_url' => '',
                // The __Host- prefix only works paired with Secure, so dev over http needs to turn it off
                'cookie_secure' => false,
                'session_ttl' => 28800, // 8 hours
                'session_idle' => 1800, // 30 minutes
                'session_rotate' => 900, // rotates the id every 15 minutes
                'ip_allowlist' => [], // empty = unrestricted
                'trusted_proxies' => []
            ],
            'agent' => [
                'socket' => '', // empty = use the value from Paths
                'timeout' => 30,
                // uids allowed to connect to the socket, always checked with SO_PEERCRED
                // (root and the agent's own uid are implicitly on the list — see Server::allowedUids())
                'allowed_uids' => [],
                // Usernames for the web tier — turned into uids when checked, since uids differ by machine
                'allowed_users' => ['phpcp-web']
            ],
            'sandbox' => [
                'prefix' => '' // empty = use the value from Paths
            ],
            'sites' => [
                'dir' => Paths::DEFAULT_SITES_DIR,
                // Hosting users' home — website files live at <users_dir>/<user>/domains/<domain>/
                'users_dir' => Paths::DEFAULT_USERS_DIR,
                // The default file layout for an account that hasn't chosen one — 'phpcp' or 'cpanel'
                // See Phpcp\Domain\SiteLayout — changeable from the settings page, which overrides this
                'layout' => '',
                'shared_owner' => false, // see the explanation at sharedOwner()
                'pointer_roots' => [] // folders outside sites.dir that a docroot is allowed to point into
            ],
            'dns' => [
                // Off by default on purpose — turned on only after confirming this
                // machine has BIND9 genuinely ready (PLAN-V2 phase E3); see the
                // explanation at dnsEnabled()
                'enabled' => false,
                'zone_dir' => '/etc/bind/zones',
                'named_conf_local' => '/etc/bind/named.conf.local',
                // At least one is required before a zone can be created at all —
                // BIND9 rejects a zone with no NS record by the nature of the protocol
                'nameservers' => [],
                // The DNS admin's email in SOA format (a dot instead of @), e.g. hostmaster.example.com
                'soa_email' => '',
            ],
            'security' => [
                'secret_key' => '', // base64, 32 bytes — used to encrypt the TOTP secret
                'require_2fa_roles' => ['superadmin', 'sysadmin'],
                'max_login_attempts' => 5,
                'lockout_seconds' => 900,
                'password_min_length' => 12
            ],
            'log' => [
                'level' => 'info' // debug | info | warn | error
            ]
        ];
    }

    /** Reads a value with dot notation, e.g. get('panel.port') */
    public function get(string $key, mixed $default = null): mixed
    {
        $value = $this->values;
        foreach (explode('.', $key) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }

        return $value;
    }

    /**
     * @param string $key
     * @param int $default
     */
    public function int(string $key, int $default = 0): int
    {
        $value = $this->get($key, $default);

        return is_numeric($value) ? (int) $value : $default;
    }

    /**
     * @param string $key
     * @param bool $default
     */
    public function bool(string $key, bool $default = false): bool
    {
        $value = $this->get($key, $default);

        return is_bool($value) ? $value : (bool) $value;
    }

    /**
     * @param string $key
     * @param string $default
     */
    public function string(string $key, string $default = ''): string
    {
        $value = $this->get($key, $default);

        return is_scalar($value) ? (string) $value : $default;
    }

    /** @return list<string> */
    public function list(string $key): array
    {
        $value = $this->get($key, []);

        return is_array($value) ? array_values(array_map(strval(...), $value)) : [];
    }

    /**
     * @return mixed
     */
    public function agentSocket(): string
    {
        $configured = $this->string('agent.socket');

        return $configured !== '' ? $configured : $this->paths->agentSocket();
    }

    /**
     * @return mixed
     */
    public function sandboxPrefix(): string
    {
        $configured = $this->string('sandbox.prefix');

        return $configured !== '' ? $configured : $this->paths->sandbox;
    }

    /**
     * Accepts that the filesystem can't record file ownership, so per-site permission separation is skipped
     *
     * Only meant to be turned on when website storage sits on NTFS/exFAT/FAT, which
     * can't hold uid/gid. The agent verifies this for real before every action — if
     * the filesystem does support ownership, it refuses the command immediately, so
     * this value can never silently reach real production unnoticed.
     */
    public function sharedOwner(): bool
    {
        return $this->bool('sites.shared_owner');
    }

    /**
     * Has BIND9 actually been wired up — always off by default (PLAN-V2 phase E3)
     *
     * Same pattern as `sharedOwner()`: an infrastructure-level decision that has to
     * be turned on deliberately, after confirming by hand that this machine has
     * BIND9 genuinely running with correct `dns.nameservers` — not something that
     * should switch on automatically just because the bind9 package happens to be
     * installed (every machine that went through `install.sh` has the package, but
     * that doesn't mean it's safe to let the panel overwrite named.conf.local). Off
     * means `dns.zone_write` is a no-op that says plainly it hasn't been wired up yet.
     */
    /**
     * Values the admin set from the screen — override config.php
     *
     * Uses the same pattern as `Paths::useSitesDir()`: a static setter called once
     * when the database becomes ready. Necessary because `Config` is always built
     * before `Db` (Db needs a path from Config), so the settings table can't be read
     * at construction time.
     *
     * @var array<string,mixed>
     */
    private static array $stored = [];

    /**
     * @param array<string,string> $values values from the settings table
     */
    public static function useStoredSettings(array $values): void
    {
        self::$stored = $values;

        // A file layout the admin chose from the screen overrides config.php — has
        // to be pushed into Paths here too, because `UserAccount` reads it from
        // there and doesn't hold a Config reference
        if (array_key_exists('sites.layout', $values)) {
            Paths::useSiteLayout((string) $values['sites.layout']);
        }
    }

    /**
     * Is DNS turned on — **the screen's value always wins over config.php**
     *
     * An admin who turns it on from the settings page must see the effect
     * immediately, not have to ssh in and edit a file to match.
     */
    public function dnsEnabled(): bool
    {
        if (array_key_exists('dns.enabled', self::$stored)) {
            return self::$stored['dns.enabled'] === '1';
        }

        return $this->bool('dns.enabled');
    }

    public function dnsZoneDir(): string
    {
        return rtrim($this->string('dns.zone_dir', '/etc/bind/zones'), '/');
    }

    public function dnsNamedConfLocal(): string
    {
        return $this->string('dns.named_conf_local', '/etc/bind/named.conf.local');
    }

    /** @return list<string> */
    public function dnsNameservers(): array
    {
        $stored = trim((string) (self::$stored['dns.nameservers'] ?? ''));

        if ($stored !== '') {
            return array_values(array_filter(array_map('trim', explode(',', $stored))));
        }

        return $this->list('dns.nameservers');
    }

    public function dnsSoaEmail(): string
    {
        $stored = trim((string) (self::$stored['dns.soa_email'] ?? ''));

        return $stored !== '' ? $stored : $this->string('dns.soa_email');
    }

    /**
     * Every location a website's docroot is allowed to point into
     *
     * Kept restricted, because a freely customisable docroot is the same as being
     * able to serve /etc or /root out to the internet with one click.
     *
     * @return list<string>
     */
    public function docrootRoots(): array
    {
        /*
         * **Only what the admin has named — nothing added automatically.**
         *
         * This used to always prepend `Paths::sitesDir()` as the first entry, which
         * turned on Domain Pointer on every machine without anyone asking for it —
         * a "parent folder" field appeared in the site-creation page of every real
         * server, pointing at `sites.dir`, storage for a layout that hasn't even
         * been used since migration 0006.
         *
         * Domain Pointer means letting a vhost serve files from outside a user's
         * home — loosening that boundary has to come from the admin's own decision,
         * not something that comes bundled in. An empty list = this feature is
         * entirely off, and the screen doesn't show that field at all.
         *
         * A development machine keeping its project elsewhere can still set
         * `sites.pointer_roots` itself, as before.
         */
        $roots = [];

        foreach ($this->list('sites.pointer_roots') as $root) {
            $root = rtrim(trim($root), '/');
            if ($root !== '' && str_starts_with($root, '/') && !in_array('..', explode('/', $root), true)) {
                $roots[] = $root;
            }
        }

        return array_values(array_unique($roots));
    }

    /**
     * The folder served at http://localhost — empty = feature off
     *
     * A development-machine feature. Off by default, since serving a folder holding
     * every project through the web isn't something a real production machine should do.
     *
     * Must be an absolute path with no `..` — this value becomes a DocumentRoot directly.
     */
    public function localhostDocroot(): string
    {
        $value = rtrim(trim($this->string('sites.localhost_docroot')), '/');

        if ($value === '' || !str_starts_with($value, '/') || in_array('..', explode('/', $value), true)) {
            return '';
        }

        return $value;
    }

    /**
     * localhost's PHP version — empty in the config means whatever version the panel is running
     *
     * Defaults to the current process's own version, since the distro's standard
     * pool that ships alongside it is that same version — a correct guess with
     * nobody having to type anything in.
     */
    public function localhostPhp(): string
    {
        $value = trim($this->string('sites.localhost_php'));

        return preg_match('/^\d+\.\d+$/', $value) === 1
            ? $value
            : PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
    }

    /**
     * The key used to encrypt sensitive data in the DB (the TOTP secret)
     * No key = the system is not allowed to continue, since it would store the secret as plaintext
     */
    public function secretKey(): string
    {
        $raw = $this->string('security.secret_key');
        if ($raw === '') {
            throw new \RuntimeException(
                'security.secret_key has not been set in the config — run `phpcp key:generate` first'
            );
        }

        $key = base64_decode($raw, true);
        if ($key === false || strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            throw new \RuntimeException('security.secret_key must be base64-encoded 32-byte data');
        }

        return $key;
    }

    /**
     * @return mixed
     */
    public function hasSecretKey(): bool
    {
        return $this->string('security.secret_key') !== '';
    }
}
