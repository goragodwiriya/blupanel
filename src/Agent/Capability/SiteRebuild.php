<?php

declare(strict_types=1);

namespace Phpcp\Agent\Capability;

use Phpcp\Agent\Context;
use Phpcp\Agent\Executor\Executor;
use Phpcp\Driver\ConfigTransaction;
use Phpcp\Driver\WebServer\ApacheDriver;
use Phpcp\Driver\WebServer\NginxDriver;

/**
 * Regenerates every website's config files to match the current `webserver` setting
 *
 * **Necessary when switching web servers** — the existing vhost files are in the
 * old server's format; changing the value in config.php alone does nothing at
 * all until the files are rewritten (`etc/config.example.php` has said to run
 * `phpcp sites:rebuild` from the very start, but this command never actually
 * existed — the docs promised it with no code behind it).
 *
 * **Two things that make this more than just "write files in a loop":**
 *
 *   1. Files from the old server have to be cleaned up — moving from apache to
 *      nginx and leaving `/etc/apache2/sites-enabled/phpcp-*.conf` behind would
 *      mean Apache keeps serving the old sites in parallel, from config the
 *      panel no longer manages · only files starting with `phpcp-` are ever
 *      deleted, never anything an admin wrote themselves.
 *   2. Everything runs inside a single transaction — a half-finished state
 *      would mean some sites point at a server no longer receiving requests; if
 *      configtest fails, everything has to revert entirely.
 */
final class SiteRebuild extends SiteCapability
{
    /** Files the panel owns inside each server's directory */
    private const OWNED_PREFIX = 'phpcp-';

    /** @var array<string,string> the vhost directory for every server the system supports */
    private const VHOST_DIRS = [
        'apache' => '/etc/apache2/sites-enabled',
        'nginx' => '/etc/nginx/conf.d',
    ];

    /** @var list<string> http://localhost's files across every mode — used when disabling the feature */
    private const LOCALHOST_FILES = [
        ApacheDriver::LOCALHOST_FILE,
        NginxDriver::LOCALHOST_FILE,
    ];

    public static function name(): string
    {
        return 'site.rebuild';
    }

    /**
     * Not `site.edit`, even though the name starts with site — the same reason
     * `dns.reload` doesn't use `domain.manage`: this command overwrites
     * **every customer's** config files at once, and touches files shared
     * across the whole machine (`ports.conf` in nginx-proxy mode).
     *
     * Uses `settings.manage`, because this job follows directly from editing
     * the `webserver` value in the config file — and that's already a
     * superadmin-only permission.
     */
    public function permission(): string
    {
        return 'settings.manage';
    }

    public function isMutating(): bool
    {
        return true;
    }

    public function summary(): string
    {
        return 'Regenerate config files for every website';
    }

    public function validate(array $args): array
    {
        return [];
    }

    public function run(array $args, Executor $executor, Context $context): array
    {
        $repository = $this->repository($context);
        $provisioner = $this->provisioner($context);
        $webserver = $provisioner->webserver();

        $sites = $repository->listWithCounts();
        $transaction = new ConfigTransaction($executor);

        $stale = $this->staleFiles($executor, $webserver->name());

        // Disabling http://localhost has to genuinely remove the file, not
        // leave it lying around until someone happens to notice — this value
        // lives in the config file, and deleting the line is the only way an admin has to disable it
        if (self::localhostSite($context) === null) {
            foreach (self::LOCALHOST_FILES as $path) {
                if ($executor->exists($executor->path($path))) {
                    $stale[] = $path;
                }
            }
        }

        foreach ($stale as $path) {
            $transaction->delete($path);
        }

        $rebuilt = [];
        $last = null;

        foreach ($sites as $row) {
            $site = $repository->load((int) $row['id']);

            if ($site === null) {
                continue;
            }

            $provisioner->stageConfigs(
                $transaction,
                $site,
                $executor,
                $repository->pointerDocrootsOwnedBy($site->owner->userId, $site->phpVersion),
            );

            $rebuilt[] = $site->domain;
            $last = $site;
        }

        // The mode's global files must always be written, no matter how many
        // sites there are or which mode is active.
        //
        // This command is the reverse path of switching modes — whatever the
        // previous mode overwrote has to be written back here · when this was
        // only written for nginx-proxy, switching back to apache left
        // `ports.conf` still pointing at 127.0.0.1:8080, and nothing on the
        // whole machine listened on port 80 anymore. Modules must always be
        // ready before configtest — some global files (localhost's vhost) also
        // use module directives · this used to be called only while staging
        // each individual site, so a machine with no sites at all never enabled
        // the modules, and configtest failed entirely
        $webserver->ensureModules($executor);

        foreach ($webserver->globalFiles($executor) as $path => $contents) {
            $transaction->write($path, $contents, 0644);
        }

        $transaction->commit(static fn (): array => $webserver->testConfig($executor));

        // reload must always come after commit — a value that hasn't passed configtest must never be loaded
        if ($last !== null) {
            $provisioner->reload($executor, $last);
        } else {
            $webserver->reload($executor);
        }

        return [
            'webserver' => $webserver->name(),
            'rebuilt' => $rebuilt,
            'count' => count($rebuilt),
            'removed_stale' => array_values($stale),
            // A caller compares this against the config file it can read itself
            // — the agent reads config only once at boot, so editing the file
            // without restarting produces the old value's result with nothing to say so
            'localhost' => self::localhostSite($context)?->docroot ?? '',
            'message' => sprintf(
                'Regenerated config files for %d website(s) in %s\'s format%s',
                count($rebuilt),
                $webserver->name(),
                $stale === [] ? '' : sprintf(' · cleaned up %d file(s) from the old server', count($stale)),
            ),
        ];
    }

    /**
     * The panel's own files sitting in a server's directory that is **no longer in use**
     *
     * nginx-proxy mode uses both directories, so nothing is ever left behind — always returns an empty list.
     *
     * @return list<string>
     */
    private function staleFiles(Executor $executor, string $webserver): array
    {
        $keep = match ($webserver) {
            'apache' => ['apache'],
            'nginx' => ['nginx'],
            default => ['apache', 'nginx'],
        };

        $stale = [];

        foreach (self::VHOST_DIRS as $server => $dir) {
            if (in_array($server, $keep, true)) {
                continue;
            }

            $resolved = $executor->path($dir);

            if (!$executor->exists($resolved)) {
                continue;
            }

            foreach ($executor->listDirectory($resolved) as $entry) {
                $name = is_array($entry) ? ($entry['name'] ?? '') : $entry;

                if (is_string($name) && str_starts_with($name, self::OWNED_PREFIX) && str_ends_with($name, '.conf')) {
                    $stale[] = $dir . '/' . $name;
                }
            }
        }

        return $stale;
    }
}
