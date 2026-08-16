<?php

declare (strict_types = 1);

namespace Phpcp\Agent\Capability;

use Phpcp\Agent\Capability;
use Phpcp\Agent\Context;
use Phpcp\Agent\PermissionDenied;
use Phpcp\Agent\ValidationError;
use Phpcp\Agent\Capability\ServiceProbe;
use Phpcp\Domain\ServiceCatalog;
use Phpcp\Domain\Site;
use Phpcp\Domain\SiteRepository;
use Phpcp\Driver\Php\FpmManager;
use Phpcp\Driver\SiteProvisioner;
use Phpcp\Domain\SettingsRepository;
use Phpcp\Driver\Template;
use Phpcp\Driver\WebServer\ApacheDriver;
use Phpcp\Driver\WebServer\LocalhostSite;
use Phpcp\Driver\WebServer\NginxDriver;
use Phpcp\Driver\WebServer\NginxProxyDriver;
use Phpcp\Driver\WebServer\WebServerDriver;
use Phpcp\Security\Permissions;
use Phpcp\Support\Validator;

/**
 * The shared base for capabilities that work with websites
 *
 * Keeps assembling drivers and loading websites in one place, so every capability always sees the same file paths and PHP version.
 */
abstract class SiteCapability implements Capability
{
    /**
     * @param Context $context
     */
    protected function provisioner(Context $context): SiteProvisioner
    {
        return self::provisionerFor($context);
    }

    /**
     * The same thing as provisioner(), but callable from outside this hierarchy
     *
     * Some `customer.*` capabilities have to rewrite a vhost/pool (e.g. when
     * an account's file layout changes), but inherit from
     * `CustomerCapability`, so they can't reach the protected method above ·
     * call this instead of assembling a driver by hand again — assembling it
     * separately means sooner or later the two places disagree about which
     * web server or shared_owner is in use.
     */
    public static function provisionerFor(Context $context): SiteProvisioner
    {
        $templates = new Template($context->config->paths->templates());

        return new SiteProvisioner(
            self::webServer($context, $templates),
            new FpmManager($templates),
            $context->config->sharedOwner(),
        );
    }

    /**
     * Picks the web server driver to match the setting
     *
     * **The database wins over `config.php`** — this value has to be
     * changeable from the screen, not left to an admin `sed`-ing the file by
     * hand · `config.php` is still the default for a machine set up before
     * this existed, and that never picked anything from the screen (the
     * table's value is an empty string).
     *
     * An unrecognized value falls back to Apache instead of throwing — if a
     * typo made the system refuse to run at all, the admin couldn't even get
     * into the web page to fix the value, effectively locking themselves out
     * of the system.
     */
    protected static function webServer(Context $context, Template $templates): WebServerDriver
    {
        $localhost = self::localhostSite($context);

        return match (self::webServerMode($context)) {
            'nginx' => new NginxDriver($templates, $localhost),
            // nginx in front + Apache behind — the only mode where .htaccess works on nginx
            'nginx-proxy' => new NginxProxyDriver(
                $templates,
                (new SettingsRepository($context->db))->bool('webserver.static_by_nginx'),
                $localhost,
            ),
            default => new ApacheDriver($templates, $localhost),
        };
    }

    /**
     * The dev machine's http://localhost — null when `sites.localhost_docroot` isn't set
     *
     * Read only from the config file, never the settings table, because it's
     * a property of the **machine**, not the user — and there should never
     * be a button on a web page that, when clicked, serves any folder on the
     * machine at all.
     */
    protected static function localhostSite(Context $context): ?LocalhostSite
    {
        $docroot = $context->config->localhostDocroot();

        return $docroot === ''
            ? null
            : new LocalhostSite($docroot, $context->config->localhostPhp());
    }

    /** The mode genuinely in use — the settings table wins, falling back to config.php */
    public static function webServerMode(Context $context): string
    {
        $stored = trim((new SettingsRepository($context->db))->get('webserver.mode'));

        return $stored !== '' ? $stored : $context->config->string('webserver', 'apache');
    }

    /**
     * @param Context $context
     */
    protected function repository(Context $context): SiteRepository
    {
        return new SiteRepository($context->db);
    }

    /** Loads a website by id, with its aliases — throws if not found */
    protected function loadSite(Context $context, int $siteId): Site
    {
        $site = $this->repository($context)->load($siteId);

        if ($site === null) {
            throw new ValidationError("Website {$siteId} was not found");
        }

        return $site;
    }

    /**
     * A website admin can only touch their own site
     *
     * Checked again at this layer even though the web tier already checked,
     * because the agent must never trust the caller — a certificate is
     * bound to a domain, and skipping this check would mean requesting
     * someone else's domain's certificate immediately.
     */
    protected function assertSiteAccess(Context $context, int $siteId): void
    {
        $actor = $context->actor;

        if ($actor->userId === 0 || Permissions::seesAllSites($actor->role)) {
            return;
        }

        $owned = (int) $context->db->value(
            'SELECT count(*) FROM sites WHERE id = :id AND owner_user_id = :user',
            ['id' => $siteId, 'user' => $actor->userId],
            0,
        );

        if ($owned === 0) {
            throw new PermissionDenied('You do not have permission over the specified website');
        }
    }

    /** Checks the PHP version is one the system recognizes */
    protected static function assertPhpVersion(string $version): string
    {
        $version = Validator::phpVersion($version);

        if (!in_array($version, ServiceCatalog::PHP_VERSIONS, true)) {
            throw new ValidationError("PHP version {$version} is not supported");
        }

        return $version;
    }

    /**
     * Checks whether this is a local developer machine / test environment
     *
     * Two ways to trigger it:
     *   - if config has log.force_hosts_update_for_test_domains = true,
     *     always edits /etc/hosts for a .test domain, even with BIND/named running
     *   - if no DNS server (named/bind9) is running → treated as a local environment
     *
     * A real server never needs this setting turned on — it never touches hosts, since DNS already handles it.
     *
     * @param Context $context
     */
    protected function isLocalEnvironment(\Phpcp\Agent\Executor\Executor $executor, Context $context): bool
    {
        // If set to force-edit hosts for .test domains
        if ($context->config->bool('log.force_hosts_update_for_test_domains', false)) {
            return true;
        }

        // Or if no DNS server is running at all (neither named nor bind9)
        $namedStatus = ServiceProbe::read($executor, 'named');
        $bind9Status = ServiceProbe::read($executor, 'bind9');
        return !($namedStatus['running'] || $bind9Status['running']);
    }

    /**
     * Adds or removes an entry in /etc/hosts for a .test subdomain or root domain
     *
     * Reads/writes /etc/hosts directly through native PHP, not through the executor, because:
     *   - agentd already runs as root, so it can write directly
     *   - /etc/hosts isn't a web service config file, so it must never be
     *     remapped by SandboxExecutor, which would write into
     *     prefix/etc/hosts instead of the real file
     */
    protected function updateHostsFile(\Phpcp\Agent\Executor\Executor $executor, string $domain, bool $add): void
    {
        if (!str_ends_with($domain, '.test')) {
            return;
        }

        $hostsPath = '/etc/hosts';

        // Read directly with native PHP — not through the executor, to avoid the sandbox's path remap
        $content = @file_get_contents($hostsPath);
        if ($content === false) {
            return;
        }

        $lines = explode("\n", $content);
        $newLines = [];
        $found = false;
        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed === '' || str_starts_with($trimmed, '#')) {
                $newLines[] = $line;
                continue;
            }
            $parts = preg_split('/\s+/', $trimmed);
            if (count($parts) >= 2 && $parts[0] === '127.0.0.1' && strtolower($parts[1]) === strtolower($domain)) {
                $found = true;
                if ($add) {
                    $newLines[] = $line;
                }
            } else {
                $newLines[] = $line;
            }
        }

        if ($add && !$found) {
            $newLines[] = "127.0.0.1\t" . $domain;
        }

        $newContent = implode("\n", $newLines);
        $newContent = rtrim($newContent) . "\n";

        // Written atomically through a temp file then renamed, to avoid a half-written file
        // Only works when the process is root (agentd)
        $tmp = $hostsPath . '.tmp.' . bin2hex(random_bytes(4));
        if (@file_put_contents($tmp, $newContent, LOCK_EX) === false) {
            return;
        }
        @chmod($tmp, 0644);
        if (!@rename($tmp, $hostsPath)) {
            @unlink($tmp);
        }
    }
}
