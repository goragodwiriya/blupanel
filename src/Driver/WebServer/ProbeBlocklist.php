<?php

declare(strict_types=1);

namespace Phpcp\Driver\WebServer;

/**
 * Paths no genuine visitor ever requests — deny them outright, no need to wait for
 * anyone to get banned.
 *
 * ### Why block by path, not ban by IP
 *
 * Some customers are schools where the whole school shares one outbound IP. Banning
 * an IP because one student's infected machine started scanning automatically means
 * the whole school can't reach its own site — and because fail2ban commands the
 * firewall, which knows nothing about vhosts, they can't reach any other customer's
 * site on the same machine either. **When an IP doesn't stand for one person, banning
 * the IP isn't the right tool**, and no amount of threshold tuning fixes that.
 *
 * Not one path on this list is ever requested by a real browser, so denying them:
 *
 *   · needs no identification of the caller at all — a school's NAT is irrelevant
 *   · costs not one extra byte of memory — it's a match the web server already
 *     performs, unlike fail2ban, which measured around 55MB and grows with every jail
 *   · blocks from the very first request, unlike a ban which lets attempts 1..N-1
 *     through first
 *
 * ### The bar for what belongs on this list
 *
 * **There must be zero legitimate use**, not just "mostly unused". `xmlrpc.php` is
 * left off despite being a favourite target, because WordPress mobile apps and
 * Jetpack call it for real. An admin who wants it closed can write that themselves
 * in their own config file, which is read last.
 *
 * Common archive extensions (`.zip`, `.tar.gz`) are left off too — plenty of sites
 * hand out real downloads. Blocking those brings a phone call from the customer,
 * which is worse than leaving it be.
 */
final class ProbeBlocklist
{
    /**
     * Dependency directories whose PHP files must never execute
     *
     * `/vendor/phpunit/phpunit/src/Util/PHP/eval-stdin.php` is the single most
     * frequently probed path in PHP hosting — an instant RCE if a customer deploys
     * with `vendor/` sitting under the docroot, which happens often. The older rule
     * only blocked `vendor/bin/`, so it never covered this.
     *
     * **Only `.php` files are blocked, not the whole directory** — some themes
     * genuinely reference css/js from `vendor/`, and closing the entire folder would
     * break the page. Blocking just `.php` closes the code-execution path without
     * touching what the page actually uses.
     */
    public const CODE_DIRS = ['vendor', 'node_modules', 'bower_components'];

    /**
     * Extensions that mean backend data leaked out, not something meant to be served
     *
     * `.sql` is a full database dump. The rest are what editors and `cp file
     * file.bak` leave behind, served as plain text with credentials inside
     * (`.php.bak` is never sent to FPM, since the extension isn't `.php`).
     */
    public const LEAK_EXTENSIONS = ['sql', 'sql.gz', 'sql.bz2', 'bak', 'old', 'orig', 'save', 'swp', 'swo', 'rej'];

    /** Single paths that should never be reachable from outside */
    public const EXACT_PATHS = ['server-status', 'server-info'];

    /**
     * Rules for Apache — used by both plain Apache mode and the nginx front tier
     *
     * Uses `LocationMatch` (matched against the URL) rather than `DirectoryMatch`
     * (matched against the file path), because what needs blocking is **the
     * request** — the real file path changes with each site's docroot, but the URLs
     * scanners probe are the same on every machine in the world.
     */
    public static function apache(): string
    {
        $dirs = implode('|', self::CODE_DIRS);
        $extensions = implode('|', array_map(
            static fn (string $ext): string => str_replace('.', '\.', $ext),
            self::LEAK_EXTENSIONS,
        ));
        $exact = implode('|', self::EXACT_PATHS);

        return <<<CONF
            # PHP files inside a dependency directory — /vendor/phpunit/.../eval-stdin.php is an RCE
            <LocationMatch "(?i)^/({$dirs})/.*\.php$">
                Require all denied
            </LocationMatch>

            # Backend data that leaked out — database dumps and files editors left behind
            <LocationMatch "(?i)\.({$extensions})$">
                Require all denied
            </LocationMatch>

            <LocationMatch "(?i)^/({$exact})$">
                Require all denied
            </LocationMatch>
            CONF;
    }

    /**
     * Rules for nginx — must come **before** the block that serves static files
     *
     * nginx picks a regex location in the order they're written; the first match
     * wins. In the nginx front-tier mode, the list of extensions served directly
     * includes `gz` — if this rule sat after that block, `backup.sql.gz` would go
     * straight out without ever passing through Apache, where the rule above lives.
     */
    public static function nginx(): string
    {
        $dirs = implode('|', self::CODE_DIRS);
        $extensions = implode('|', array_map(
            static fn (string $ext): string => str_replace('.', '\.', $ext),
            self::LEAK_EXTENSIONS,
        ));
        $exact = implode('|', self::EXACT_PATHS);

        return <<<CONF
            # PHP files inside a dependency directory — /vendor/phpunit/.../eval-stdin.php is an RCE
            location ~* ^/({$dirs})/.*\.php$ {
                deny all;
            }

            # Backend data that leaked out — must come before the static block that serves .gz directly
            location ~* \.({$extensions})$ {
                deny all;
            }

            location ~* ^/({$exact})$ {
                deny all;
            }
            CONF;
    }
}
