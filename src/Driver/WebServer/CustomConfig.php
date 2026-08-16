<?php

declare(strict_types=1);

namespace Phpcp\Driver\WebServer;

use Phpcp\Agent\Executor\Executor;
use Phpcp\Agent\ValidationError;
use Phpcp\Driver\Template;
use Phpcp\Support\Validator;

/**
 * A supplementary config file an admin writes by hand — entirely the
 * admin's own, the panel never touches it
 *
 * ## Why a vhost file can't just be edited directly
 *
 * The file an admin most wants to edit is exactly the file the panel
 * **overwrites in full** every time someone touches related data · allowing
 * a direct edit is a trap: it works right away, everything looks correct,
 * and one day that change silently vanishes when someone clicks to edit
 * something else — one of the hardest symptoms to trace, and a bug this
 * very project has already hit more than once.
 *
 * So this lives in its own admin-only directory, which a generated vhost
 * reads last — a value written here always wins over the default, and
 * nothing is ever lost when the panel writes a fresh vhost.
 *
 * ## The scope of authority here
 *
 * The content is genuinely raw web server config, so nothing filters
 * directives — a per-directive filter is easy to evade and gives false
 * confidence · what actually limits the power here is **context**: the
 * file is included inside `<VirtualHost>` / `server {}`, so a machine-level
 * directive (User, Listen, LoadModule, worker_processes) is already
 * rejected by the web server's own validator before it can ever take
 * effect · what's left is the `settings.manage` permission, which already means a server admin.
 *
 * **The file path never comes from user input** — assembled only from a
 * domain name that already passed `Validator::domain()`, a caller can only ever send "the file's content", never "where to write it".
 */
final class CustomConfig
{
    /** The root for files an admin writes by hand — lives under /etc/phpcp because it belongs to the panel, not the distro */
    public const ROOT = '/etc/phpcp/custom';

    /**
     * The services that can have a supplementary file, with the extension each one uses
     *
     * **A fixed allowlist**, the same approach as the capability registry —
     * a name not in this list is rejected, never assembled into a path and
     * hoped to work out.
     *
     * Split by service because their syntax is completely different — an
     * Apache file that leaks into what nginx reads makes the validator fail
     * for the whole machine, with the admin having changed nothing that day.
     *
     * @var array<string,string>
     */
    public const SERVICES = [
        'apache' => 'custom.conf',
        'nginx' => 'custom.conf',
        'postfix' => 'custom.cf',
        'dovecot' => 'custom.conf',
    ];

    /**
     * The services that have a starter file — a name here gets assembled into a template filename, so it must stay an allowlist
     *
     * **Not the same set as `SERVICES`**, because BIND9 keeps its file
     * somewhere else: it drops root the instant it starts (`named -u bind`),
     * left with only cap_net_bind_service and cap_sys_resource — no
     * cap_dac_read_search · `/etc/phpcp` is 750 root:phpcp, so it can never
     * even traverse into that directory, and `rndc reload` would fail with
     * permission denied every time (confirmed from /proc/<pid>/status of the actual running named).
     *
     * Opening up `/etc/phpcp` for bind to read isn't a trade worth making —
     * that directory holds `config.php`, which stores the panel's own
     * secret key · so BIND's file instead lives next to `named.conf.local`
     * ({@see \Phpcp\Driver\Dns\BindZoneManager::customConfigPath()}), a
     * directory BIND can already read and where the panel already writes other files.
     *
     * @var list<string>
     */
    public const SEEDS = ['apache', 'nginx', 'postfix', 'dovecot', 'bind'];

    /**
     * The one filename the screen is allowed to edit
     *
     * The directory is included with `*.conf`, so an admin can drop in
     * other files themselves over SSH without the panel getting involved —
     * but the screen edits only one file, so it never turns into a general
     * file manager that can write anything anywhere, which is a different
     * matter entirely with a different level of risk.
     */
    public const FILE = 'custom.conf';

    /** Bigger than this isn't a config value anymore — guards against stuffing data into a file that's read on every reload */
    private const MAX_BYTES = 65536;

    /**
     * One website's directory, matching whichever web server is in use
     *
     * Split by server type because the syntax is completely different —
     * after switching from Apache to nginx, the other side's file must
     * never be read, or configtest fails for the whole machine with the admin having changed nothing that day.
     */
    /**
     * The directory for a machine-wide service (mail) — no domain attached
     */
    public static function serviceDirectory(string $service): string
    {
        if (!isset(self::SERVICES[$service])) {
            throw new ValidationError('This service has no supplementary file: ' . $service);
        }

        return self::ROOT . '/' . $service;
    }

    /**
     * A single website's directory — **a domain is always required**
     *
     * Deliberately kept separate from `serviceDirectory()`, rather than
     * made an optional parameter · an empty domain leaking in would
     * silently overwrite the service-level file every site shares, instead
     * of writing that one site's own file — a mistake nothing would ever
     * complain about until someone wonders why every site suddenly changed.
     */
    public static function siteDirectory(string $service, string $domain): string
    {
        return self::serviceDirectory($service) . '/' . Validator::domain($domain);
    }

    public static function servicePath(string $service): string
    {
        return self::serviceDirectory($service) . '/' . self::SERVICES[$service];
    }

    public static function sitePath(string $service, string $domain): string
    {
        return self::siteDirectory($service, $domain) . '/' . self::SERVICES[$service];
    }

    /** The file's current content — never written yet = empty value, not an error */
    public function read(Executor $executor, string $service, string $domain = ''): string
    {
        $path = $executor->path(
            $domain === '' ? self::servicePath($service) : self::sitePath($service, $domain),
        );

        if (!$executor->exists($path)) {
            return '';
        }

        try {
            return $executor->readFile($path);
        } catch (\Throwable) {
            return '';
        }
    }

    /**
     * Validates the content before it's written
     *
     * Only checks two things that are about "the file", not "the config
     * value": size and null bytes · syntax correctness is the web server's
     * own validator's job, which is more accurate than anything written
     * here could be, and it's the exact same tool used at real load time.
     */
    public static function assertContent(string $content): string
    {
        if (strlen($content) > self::MAX_BYTES) {
            throw new ValidationError(sprintf(
                'Config file exceeds %d KB — a config this long is usually a sign it belongs somewhere else',
                (int) (self::MAX_BYTES / 1024),
            ));
        }

        // A null byte causes a file to be read incompletely with no error — rejected at the source
        if (str_contains($content, "\0")) {
            throw new ValidationError('Config file contains a null byte');
        }

        // Always ends with a newline — some nginx directives need it, and it makes a diff easier to read
        $content = rtrim($content, "\r\n");

        return $content === '' ? '' : $content . "\n";
    }

    /**
     * The starter content for a file that's never been written yet
     *
     * **The explanation lives in the file itself, not on the screen** — an
     * admin already expects to find it there (every distro's own `.conf`
     * file ships with explanatory comments) · and it stays with the file no
     * matter whether it's opened from the web page or `cat`'d over SSH,
     * unlike on-screen text that vanishes the moment the window is closed.
     *
     * Every example in the file is commented out — a starter file that
     * gets saved immediately must not change the site's behavior even slightly.
     */
    public function seed(Template $templates, string $service, string $domain = ''): string
    {
        $file = 'custom/' . (in_array($service, self::SEEDS, true) ? $service : 'apache') . '.conf.tpl';

        try {
            // NOTE: 'เครื่องนี้' ("this machine") kept as-is — templates/custom/*.conf.tpl
            // are still fully Thai (out of the current src/ sweep's scope);
            // translating only this half would produce a mixed-language generated file.
            return $templates->render($file, ['DOMAIN' => $domain !== '' ? $domain : 'เครื่องนี้']);
        } catch (\Throwable) {
            // No template still allows editing the file, just without an explanation — not a reason to break the whole page
            return '';
        }
    }
}
