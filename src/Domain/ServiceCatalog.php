<?php

declare (strict_types = 1);

namespace Phpcp\Domain;

use Phpcp\Agent\SelfProtection;

/**
 * The list of services the panel is allowed to manage — a hardcoded allowlist
 *
 * This is the same list a capability uses to validate arguments and the UI uses to
 * render the Services page. Adding a new service means editing this one place, and
 * every edit always passes through SelfProtection — so the panel's own services can
 * never end up in this list, even if someone types the name in.
 */
final class ServiceCatalog
{
    public const KIND_WEBSERVER = 'webserver';
    public const KIND_PHP = 'php';
    public const KIND_DATABASE = 'database';
    public const KIND_SCHEDULER = 'scheduler';
    public const KIND_DNS = 'dns';
    public const KIND_MAIL = 'mail';
    public const KIND_ACCESS = 'access';

    /**
     * PHP versions the **Services page** lists a unit for, newest first
     *
     * Narrower than it looks, and deliberately so. This is no longer the
     * panel's idea of which versions exist — installed versions are scanned
     * from the machine ({@see \Phpcp\Driver\Php\FpmManager::installedVersions()})
     * and installable ones come from apt, so a release this list has never
     * heard of still shows up, still gets chosen for a website, and still gets
     * started and stopped from the Services page (which reads the units of the
     * versions actually present, via `phpVersionFromUnit`).
     *
     * What it remains is the seed for {@see all()}: the units worth listing on
     * a machine before anything has been discovered. Nothing breaks by leaving
     * it alone as new versions appear.
     *
     * Whether a version is still getting security fixes is **not** here — that
     * is a date, and dates live in {@see PhpSupport}. Two hand-written lists
     * used to answer that question and they disagreed with each other.
     */
    public const PHP_VERSIONS = ['8.5', '8.4', '8.3', '8.2', '8.1', '8.0', '7.4'];

    /**
     * The apt packages that make a PHP version usable for hosting
     *
     * **One definition, used by both the installer and the panel.** `install.sh`
     * used to carry its own copy of this list; the two drifted the first time
     * one of them was edited, and the symptom was a version installed from the
     * panel that was missing an extension every site set up by the installer had.
     *
     * `fpm` and `cli` are the two that make it a working hosting runtime; the
     * rest are what a normal PHP application (WordPress and everything shaped
     * like it) fails to start without. Kept to what is genuinely needed —
     * every extra package is disk, attack surface, and another thing to update.
     *
     * @return list<string>
     */
    public static function phpPackages(string $version): array
    {
        $suffixes = [
            'cli', 'fpm', 'mysql', 'sqlite3', 'mbstring', 'curl',
            'zip', 'xml', 'gd', 'intl', 'opcache', 'bcmath',
        ];

        return array_map(static fn (string $suffix): string => 'php' . $version . '-' . $suffix, $suffixes);
    }

    /**
     * @return array<string,array{label:string,kind:string,critical:bool}>
     */
    public static function all(): array
    {
        $catalog = [
            'apache2' => ['label' => 'Apache', 'kind' => self::KIND_WEBSERVER, 'critical' => true],
            'nginx' => ['label' => 'Nginx', 'kind' => self::KIND_WEBSERVER, 'critical' => true],
            'named' => ['label' => 'BIND9 DNS', 'kind' => self::KIND_DNS, 'critical' => false],
            'bind9' => ['label' => 'BIND9 DNS', 'kind' => self::KIND_DNS, 'critical' => false]
        ];

        foreach (self::PHP_VERSIONS as $version) {
            $catalog[self::fpmUnit($version)] = [
                'label' => 'PHP-FPM '.$version,
                'kind' => self::KIND_PHP,
                'critical' => true
            ];
        }

        $catalog['mariadb'] = ['label' => 'MariaDB', 'kind' => self::KIND_DATABASE, 'critical' => true];
        $catalog['mysql'] = ['label' => 'MySQL', 'kind' => self::KIND_DATABASE, 'critical' => true];
        $catalog['cron'] = ['label' => 'Cron', 'kind' => self::KIND_SCHEDULER, 'critical' => false];

        /*
         * SSH — missing from this list forever, despite being the service an admin
         * most needs visibility into
         *
         * Every customer's SFTP runs on this (see SftpAccessManager). While it
         * didn't appear on the Services page, the admin had no way to know from the
         * web page what state SSH was in at all, and no way to start/restart it —
         * they'd have to go find the machine or ssh in themselves, which is exactly
         * impossible in the case where SSH itself is the thing that's broken.
         *
         * `critical` = true because this is the only remaining way into the
         * machine when the panel itself is down · `sshd` for RHEL, `ssh` for
         * Debian/Ubuntu — a machine only has one of the two, and the other reports
         * as not_installed and gets filtered out by the screen on its own (same as
         * named/bind9).
         */
        $catalog['ssh'] = ['label' => 'SSH / SFTP', 'kind' => self::KIND_ACCESS, 'critical' => true];
        $catalog['sshd'] = ['label' => 'SSH / SFTP', 'kind' => self::KIND_ACCESS, 'critical' => true];

        /*
         * Mail (PLAN-MAIL) — these three daemons were added in Phases M1–M3 but
         * never appeared on the Services page, so the admin couldn't see they
         * existed, couldn't tell which ones were running, and couldn't restart them
         * from the web page.
         *
         * **`critical` is deliberately not the same across all three:**
         *   postfix  real on every machine — it's also the outbound path for the
         *            panel's own notification emails, not just hosting mail ·
         *            it going down means every notification silently fails to send
         *   dovecot  ships alongside postfix on every machine, but only matters on
         *            a machine that has hosting mail turned on
         *   rspamd   mail still sends normally when this is down, it just loses
         *            DKIM signing (`milter_default_action = accept` — see
         *            hosting.cf.tpl)
         *
         * So the latter two never wake anyone up at night on a machine that isn't
         * doing hosting mail — their status is still visible and they can still be
         * controlled from the Services page just the same.
         */
        $catalog['postfix'] = ['label' => 'Postfix (SMTP)', 'kind' => self::KIND_MAIL, 'critical' => true];
        $catalog['dovecot'] = ['label' => 'Dovecot (IMAP/POP3)', 'kind' => self::KIND_MAIL, 'critical' => false];
        $catalog['rspamd'] = ['label' => 'rspamd (spam/DKIM)', 'kind' => self::KIND_MAIL, 'critical' => false];

        // Safety net: even if someone accidentally adds a panel unit to the list above, it gets stripped out here
        return array_filter(
            $catalog,
            static fn(string $unit): bool => !SelfProtection::isProtectedUnit($unit),
            ARRAY_FILTER_USE_KEY,
        );
    }

    /** @return list<string> */
    public static function units(): array
    {
        return array_keys(self::all());
    }

    /**
     * @param string $unit
     */
    public static function isAllowed(string $unit): bool
    {
        return array_key_exists($unit, self::all());
    }

    /**
     * @param string $unit
     */
    public static function label(string $unit): string
    {
        return self::all()[$unit]['label'] ?? $unit;
    }

    /**
     * @param string $unit
     */
    public static function kind(string $unit): string
    {
        return self::all()[$unit]['kind'] ?? 'other';
    }

    /** true = this service going down takes sites down with it, must warn harder than usual */
    public static function isCritical(string $unit): bool
    {
        return self::all()[$unit]['critical'] ?? false;
    }

    /**
     * @param string $phpVersion
     */
    public static function fpmUnit(string $phpVersion): string
    {
        return 'php'.$phpVersion.'-fpm';
    }

    /** Converts php8.4-fpm → 8.4, returns null if this isn't a PHP-FPM unit */
    public static function phpVersionFromUnit(string $unit): ?string
    {
        return preg_match('/^php(\d\.\d{1,2})-fpm$/', $unit, $m) === 1 ? $m[1] : null;
    }

    /**
     * The set of services shown on the dashboard
     *
     * The original set per PROMPT.md was only apache2/nginx/php-fpm/mariadb/cron —
     * written before the mail phase (PLAN-MAIL) added postfix/dovecot/rspamd into
     * all(), so they never got pulled in here either, even though postfix is also
     * the outbound path for the panel's own notification emails and therefore
     * belongs on the front page.
     */
    public static function dashboardUnits(): array
    {
        return ['apache2', 'nginx', 'php8.4-fpm', 'mariadb', 'cron', 'postfix', 'dovecot', 'rspamd'];
    }
}
