<?php

declare(strict_types=1);

namespace Phpcp\Domain;

use Phpcp\Agent\ValidationError;
use Phpcp\Kernel\Db;

/**
 * Settings editable from the web page — stored in the `settings` table
 *
 * Deliberately separate from `/etc/phpcp/config.php`; the two serve different jobs:
 *
 *   config.php  Values the panel needs before it can even work at all (port, secret
 *               key, layout). The admin edits the file directly, and it requires a
 *               service restart — the web tier can never write to it.
 *
 *   settings    Values that can change while the panel is running, without affecting
 *               boot (notifications, mail). Editable from the web page, takes effect
 *               immediately.
 *
 * Why these aren't merged: letting the web page write config.php would mean letting
 * the web tier write a file it reads at boot — a single vulnerability in the settings
 * page would turn into immediate code execution, since config.php is a PHP file that
 * gets included.
 */
final class SettingsRepository
{
    /**
     * Keys allowed to be stored, along with each value's type
     *
     * A hardcoded allowlist, like the capability registry — a key not on this list is
     * rejected, not silently stored, preventing a modified form from injecting
     * arbitrary values into the database.
     *
     * @var array<string,string>
     */
    private const KEYS = [
        /*
         * **The management page's own** certificate — stores only the domain name
         * it's bound to (empty = self-signed)
         *
         * **Deliberately not editable through the general settings form**, same as
         * `webserver.*` — changing this value requires copying the file, verifying
         * the key pair, passing it through Apache's own validator, and scheduling a
         * rollback, all of which only `panel.cert_set` can do · writing it directly
         * would let the value change without the file changing to match, and the
         * screen would then report something that doesn't reflect reality.
         */
        'panel.cert_domain' => 'string',

        // Telegram notifications
        'notify.telegram.enabled' => 'bool',
        'notify.telegram.token' => 'secret',
        'notify.telegram.chat_id' => 'string',

        // Which events should notify — not everything, or people stop reading
        'notify.events.security' => 'bool',
        'notify.events.ssl' => 'bool',
        'notify.events.service' => 'bool',
        'notify.events.backup' => 'bool',
        'notify.events.login' => 'bool',
        'notify.events.quota' => 'bool',
        'notify.events.alert' => 'bool',

        // Email notifications — uses the existing Postfix · the sender shares
        // `mail.from` with outbound mail
        'notify.email.enabled' => 'bool',
        'notify.email.to' => 'string',

        // Webhook notifications — connects into Slack/Discord/whatever ticket system the admin already uses
        'notify.webhook.enabled' => 'bool',
        'notify.webhook.url' => 'string',
        // Used to HMAC-sign the payload so the destination can verify it really came from this machine
        'notify.webhook.secret' => 'secret',

        // Outbound mail
        'mail.enabled' => 'bool',
        'mail.mode' => 'string',        // 'local' | 'relay'
        'mail.from' => 'string',
        'mail.relay_host' => 'string',
        'mail.relay_port' => 'int',
        'mail.relay_user' => 'string',
        'mail.relay_password' => 'secret',
        'mail.relay_tls' => 'bool',

        // Hosting mail (PLAN-MAIL) — the hostname it announces itself with when
        // talking to other servers, and that name's certificate · empty = infer from
        // mail.from and fall back to the distro's own certificate for now
        'mail.hostname' => 'string',
        'mail.tls_cert' => 'string',
        'mail.tls_key' => 'string',

        // The web server that hosts customer sites — moved out of config.php so it
        // can be changed from the screen · empty = use the value in config.php
        // (machines set up before this existed)
        'webserver.mode' => 'string',       // 'apache' | 'nginx' | 'nginx-proxy'
        'webserver.static_by_nginx' => 'bool',

        // DNS — must be settable from the screen, not something the admin has to edit a file for
        'dns.enabled' => 'bool',
        'dns.nameservers' => 'string',      // comma-separated, e.g. ns1.example.com,ns2.example.com
        'dns.soa_email' => 'string',

        /*
         * The default file layout for an account that hasn't chosen one itself — 'phpcp' | 'cpanel'
         *
         * Safe to make editable from the web page, unlike `sites.users_dir`, which
         * still has to live in config.php: this value isn't read at boot to build the
         * panel's own paths, and it only affects **accounts created after this
         * point** · an account that already has sites has to be migrated
         * individually, a command that touches real files and therefore has to be a
         * deliberate click.
         */
        'sites.layout' => 'string',

        /*
         * Folders outside the users' homes that a website's DocumentRoot may point
         * into — Domain Pointer (`sites.pointer_roots`)
         *
         * **Had to become settable from the screen**, for the same reason
         * `dns.enabled` did: it lived only in `config.php`, which on a real install
         * is `/etc/phpcp/config.php` — root-owned, 0640, and not reachable from the
         * panel at all · an admin who keeps their projects somewhere other than
         * `/home` therefore could not turn the feature on without an SSH session and
         * an editor, and the two fields that depend on it stayed hidden on every
         * form with nothing on screen saying why.
         *
         * Safe to store here, unlike `sites.users_dir`: it is not read at boot to
         * build the panel's own paths, and it changes nothing that already exists —
         * it only widens what a **new** docroot is allowed to be, and every value is
         * still re-checked against this list at the moment a site is created
         * ({@see \Phpcp\Support\Validator::resolvePointerDocroot}).
         *
         * Comma- or newline-separated absolute paths · empty = the feature is off,
         * which stays the default on every machine.
         */
        'sites.pointer_roots' => 'string',

        /*
         * Login brute-force protection for the panel itself — enforced through fail2ban
         *
         * **Not editable through the general settings form, same as `webserver.*`** —
         * changing these has to write a jail file through fail2ban's own validator and
         * reload it. Writing straight into the table would let the value drift from
         * the file on disk, and the screen would then report protection that doesn't
         * actually exist. Only `security.panel_jail_set` may write these.
         */
        /*
         * Master switch — does the panel use fail2ban at all?
         *
         * Off deletes every jail the panel manages. **It does not stop the fail2ban
         * service itself** — the SSH jail ships with the distro, not with the panel,
         * so stopping the service would drop SSH brute-force protection too without
         * the admin ever asking for that. The screen offers the command to stop the
         * service themselves, with that warning attached, instead.
         */
        'security.fail2ban.enabled' => 'bool',

        'security.panel_jail.enabled' => 'bool',
        // off | notify | ban — see Fail2banManager::modes()
        'security.panel_jail.mode' => 'string',
        'security.panel_jail.max_retry' => 'int',
        'security.panel_jail.find_seconds' => 'int',
        'security.panel_jail.ban_seconds' => 'int',
        'security.panel_jail.ignore_ips' => 'string',

        /*
         * Addresses never to ban, no matter the jail — one machine-wide list
         *
         * For customers where many people share one outbound IP (a school, an
         * office), where a single ban would cut the whole organisation off from
         * every site on the machine.
         *
         * Not editable through the general settings form, same reason as
         * `security.panel_jail.*` — the value has to travel together with the jail
         * file rewritten through fail2ban's validator.
         */
        'security.never_ban_ips' => 'string',

        /*
         * **The panel's own** PHP values — see {@see PhpSettings} for the field list
         *
         * Not editable through the general settings form, same as `webserver.*`:
         * these have to travel together with the pool file and the Apache
         * `LimitRequestBody` that were written from them, through
         * `panel.php_set` · writing them straight into the table would leave the
         * screen reporting a 512M upload limit while the file on disk still says
         * 32M, and nothing anywhere would say which one is true.
         */
        'panel.php.memory_limit_mb' => 'int',
        'panel.php.upload_max_mb' => 'int',
        'panel.php.post_max_mb' => 'int',
        'panel.php.max_execution_time' => 'int',
        'panel.php.max_input_time' => 'int',
        'panel.php.max_input_vars' => 'int',
        'panel.php.max_file_uploads' => 'int',
        'panel.php.session_lifetime' => 'int',
        'panel.php.display_errors' => 'bool',
        'panel.php.allow_url_fopen' => 'bool',
        'panel.php.timezone' => 'string',
        'panel.php.max_children' => 'int',
    ];

    /** Defaults used until a value has been set */
    private const DEFAULTS = [
        /*
         * Off by default, and let `security.scan` say when it should be turned on.
         *
         * Turning it on for free on an update means a machine already in production
         * suddenly starts banning at the firewall, with the owner not even knowing
         * this feature exists — an admin who mistypes a password would be locked out
         * of the control panel despite never having opted in. It has to be a
         * deliberate click.
         *
         * The default values aim for "stops someone guessing, not someone who
         * forgot their own password": 10 wrong attempts in 10 minutes, then a
         * half-hour ban — a human mistyping barely gets close, while an automated
         * guesser reaches it within seconds.
         */
        'security.fail2ban.enabled' => '1',
        'security.panel_jail.enabled' => '0',
        // Default is "notify", not "ban" — the admin should see what the system
        // catches before handing it the power to cut someone off the machine
        'security.panel_jail.mode' => 'notify',
        'security.panel_jail.max_retry' => '10',
        'security.panel_jail.find_seconds' => '600',
        'security.panel_jail.ban_seconds' => '1800',
        'security.panel_jail.ignore_ips' => '',
        'security.never_ban_ips' => '',

        'notify.telegram.enabled' => '0',
        'notify.telegram.token' => '',
        'notify.telegram.chat_id' => '',
        'notify.events.security' => '1',
        'notify.events.ssl' => '1',
        'notify.events.service' => '1',
        'notify.events.backup' => '0',
        'notify.events.login' => '1',
        'notify.events.quota' => '1',
        'notify.events.alert' => '1',
        'notify.email.enabled' => '0',
        'notify.email.to' => '',
        'notify.webhook.enabled' => '0',
        'notify.webhook.url' => '',
        'notify.webhook.secret' => '',
        'mail.enabled' => '0',
        'mail.mode' => 'local',
        'mail.from' => '',
        'mail.relay_host' => '',
        'mail.relay_port' => '587',
        'mail.relay_user' => '',
        'mail.relay_password' => '',
        'mail.relay_tls' => '1',
        'mail.hostname' => '',
        'mail.tls_cert' => '',
        'mail.tls_key' => '',

        // Empty = never chosen from the screen yet, fall back to the value in config.php
        'webserver.mode' => '',
        // Let nginx serve static files itself by default — that's the whole reason nginx is there
        'webserver.static_by_nginx' => '1',
        'dns.enabled' => '0',
        'dns.nameservers' => '',
        'dns.soa_email' => '',
    ];

    /**
     * Keys the general settings form can edit — **excludes `webserver.*`**
     *
     * Changing the web server value requires rewriting every vhost file on the
     * machine and restarting services in the correct order · if PATCH /settings were
     * allowed to write this value directly, the machine could end up with "the
     * setting says nginx but the files on disk are still Apache's," a state the admin
     * has no way to tell apart from the truth — this must go through
     * `webserver.apply` only.
     *
     * @return array<string,string>
     */
    public static function webEditableKeys(): array
    {
        return array_filter(
            self::keys(),
            static fn (string $key): bool => !str_starts_with($key, 'webserver.')
                // A jail's values must always travel with the file fail2ban has
                // validated. Writing straight into the table lets the value drift
                // from the file on disk, and the screen would then report protection
                // that doesn't actually exist — it has to go through
                // `security.panel_jail_set`
                && !str_starts_with($key, 'security.panel_jail.')
                && !str_starts_with($key, 'security.fail2ban.')
                && $key !== 'security.never_ban_ips'
                && $key !== 'panel.cert_domain'
                // The panel's own PHP values have to travel with the pool file
                // and the Apache limit written from them — `panel.php_set` only
                && !str_starts_with($key, 'panel.php.'),
            ARRAY_FILTER_USE_KEY,
        );
    }

    public function __construct(private readonly Db $db)
    {
    }

    /** @return array<string,string> every value, including defaults for keys never set */
    public function all(): array
    {
        $values = self::defaults();

        foreach ($this->db->all('SELECT key, value FROM settings') as $row) {
            $key = (string) $row['key'];

            // An unrecognized key is skipped, not passed through to the screen — this
            // guards against a retired old value, or a value stuffed into the
            // database some other way, surfacing on the web page.
            if (isset(self::KEYS[$key])) {
                $values[$key] = (string) $row['value'];
            }
        }

        return $values;
    }

    public function get(string $key, string $default = ''): string
    {
        if (!isset(self::KEYS[$key])) {
            throw new ValidationError("Unknown setting {$key}");
        }

        $row = $this->db->first('SELECT value FROM settings WHERE key = :k', ['k' => $key]);

        return $row === null ? (self::defaults()[$key] ?? $default) : (string) $row['value'];
    }

    public function bool(string $key): bool
    {
        return $this->get($key) === '1';
    }

    public function int(string $key): int
    {
        return (int) $this->get($key);
    }

    /**
     * Save multiple values at once
     *
     * @param array<string,string> $values
     */
    public function save(array $values): void
    {
        foreach ($values as $key => $value) {
            if (!isset(self::KEYS[$key])) {
                throw new ValidationError("Unknown setting {$key}");
            }

            $this->db->run(
                'INSERT INTO settings (key, value, updated_at) VALUES (:k, :v, :t)
                 ON CONFLICT(key) DO UPDATE SET value = :v, updated_at = :t',
                ['k' => $key, 'v' => (string) $value, 't' => time()],
            );
        }
    }

    /** A key holding a secret — its real value must never be sent back to display on screen */
    public static function isSecret(string $key): bool
    {
        return (self::KEYS[$key] ?? '') === 'secret';
    }

    /** @return array<string,string> */
    public static function keys(): array
    {
        return self::KEYS;
    }

    /**
     * The default value of every key that has never been set
     *
     * Exposed so tests can check that the defaults hold up against constraints
     * defined elsewhere in the system — a wrong default is a value every machine
     * gets without anyone choosing it.
     *
     * @return array<string,string>
     */
    public static function defaults(): array
    {
        /*
         * The panel's own PHP defaults are not literals here — they are
         * {@see PhpSettings::panelDefaults()}, which is the same value pinned to
         * the literals in `templates/panel/panel-pool.conf.tpl` · a second copy
         * in this file would be the copy that eventually disagrees, and the
         * screen would then show a number no process is running
         */
        return self::DEFAULTS + PhpSettings::panelDefaults()->toSettings();
    }

    /**
     * Mask secret values before sending them to the screen
     *
     * Only sends "does a value exist," never the value itself — a bot token leaked
     * through HTML would mean anyone could send messages as the system, and it would
     * sit in the browser's cache and the proxy's history for a long time afterward.
     *
     * @param array<string,string> $values
     * @return array<string,string>
     */
    public static function mask(array $values): array
    {
        foreach ($values as $key => $value) {
            if (self::isSecret($key)) {
                $values[$key] = $value === '' ? '' : '********';
            }
        }

        return $values;
    }
}
