<?php

declare(strict_types=1);

namespace Phpcp\Driver\Php;

use Phpcp\Agent\ExecutionFailed;
use Phpcp\Agent\Executor\Executor;
use Phpcp\Domain\ServiceCatalog;
use Phpcp\Domain\Site;
use Phpcp\Driver\Template;
use Phpcp\Support\Validator;

/**
 * Manages FPM pools — ARCHITECTURE §11
 *
 * **One pool per (user × PHP version)** since migration 0006 — it used to
 * be one pool per site, so a customer with 5 sites on PHP 8.4 consumed 5
 * pools without actually separating anything that needed separating, since
 * all 5 sites already belong to the same person.
 *
 * A pool runs as the owner's uid, has open_basedir restricted to the
 * owner's own home, and has every shell function disabled — this is the
 * mechanism that stops a compromised site from reading **another
 * customer's** files or escalating to root · sites belonging to the same
 * customer can read each other's files deliberately.
 */
final class FpmManager
{
    public function __construct(private readonly Template $templates)
    {
    }

    /**
     * The pool file belonging to this site's owner, for the PHP version this site uses
     *
     * A single file covers every site the owner has on the same version —
     * so its content refers to **the user's own home**, never any one
     * site's folder · writing it to reference a single site would mean a
     * site created later overwrites the previous site's open_basedir, breaking both.
     */
    /**
     * @param list<string> $extraPaths folders outside the home that also need to be in open_basedir
     *                                 (a Domain Pointer of any site using this pool)
     */
    public function renderPool(
        Site $site,
        string $webserverUser,
        Executor $executor,
        array $extraPaths = [],
    ): string {
        $owner = $site->owner;
        $version = $site->phpVersion;

        // A single pool covers several sites, so open_basedir has to be
        // **the union** of the home and the Domain Pointer of every site
        // using this pool · writing just the home would mean a site whose
        // docroot points outside it can't open its own files at all, and gets a 500 the instant it's deployed
        $allowed = [$executor->path($owner->home())];

        foreach ($extraPaths as $path) {
            $mapped = $executor->path($path);

            if (!in_array($mapped, $allowed, true)) {
                $allowed[] = $mapped;
            }
        }

        $allowed[] = '/usr/share/php';
        $allowed[] = '/tmp';

        // Paths inside the pool file must be mapped to match the current mode, exactly like in a vhost
        return $this->templates->render('fpm/pool.conf.tpl', [
            'POOL_NAME' => $owner->poolName($version),
            'ACCOUNT_USER' => $owner->username,
            'PHP_VERSION' => $version,
            'FPM_SOCKET' => $executor->path($owner->fpmSocket($version)),
            'WEBSERVER_USER' => $webserverUser,
            'HOME' => $executor->path($owner->home()),
            'OPEN_BASEDIR' => implode(':', $allowed),
            'TMP_DIR' => $executor->path($owner->tmpDir()),
            'SLOW_LOG' => $executor->path($owner->phpSlowLog($version)),
            'PHP_ERROR_LOG' => $executor->path($owner->phpErrorLog($version)),
            'MAX_CHILDREN' => $site->maxChildren,
            'MEMORY_LIMIT' => $site->memoryLimitMb . 'M',
            'UPLOAD_LIMIT' => $site->uploadLimitMb . 'M',
        ]);
    }

    /**
     * The PHP versions genuinely installed on the machine
     *
     * @return list<string>
     */
    public function installedVersions(Executor $executor): array
    {
        $found = [];

        foreach (ServiceCatalog::PHP_VERSIONS as $version) {
            if ($executor->exists($executor->path('/etc/php/' . $version . '/fpm'))) {
                $found[] = $version;
            }
        }

        return $found;
    }

    public function isVersionInstalled(Executor $executor, string $version): bool
    {
        return in_array(Validator::phpVersion($version), $this->installedVersions($executor), true);
    }

    /**
     * Validates the pool file with php-fpm itself before a reload is triggered
     *
     * php-fpm -t reads that version's entire main config, including all of
     * pool.d, so it catches both bad syntax and a duplicate pool name.
     *
     * @return array{0:bool,1:string}
     */
    public function testConfig(Executor $executor, string $version): array
    {
        $version = Validator::phpVersion($version);
        $binary = '/usr/sbin/php-fpm' . $version;

        if (!$executor->exists($binary)) {
            // No binary to validate with = cannot be validated, not "validated and passed"
            // Returns true because the web server's own configtest is already the primary gate,
            // but the message says so plainly, so it's clear this layer of validation was skipped
            return [true, "Skipped pool validation: {$binary} was not found on this machine"];
        }

        // -y must always be given, never left for php-fpm to fall back to
        // its compiled-in config — otherwise in sandbox mode this would end
        // up validating the machine's real config while the file just
        // written sits under a prefix — validating the wrong file and
        // reporting success
        $config = $executor->path('/etc/php/' . $version . '/fpm/php-fpm.conf');

        if (!$executor->exists($config)) {
            return [true, "Skipped pool validation: {$config} was not found"];
        }

        $result = $executor->exec([$binary, '-t', '-y', $config], timeout: 20);
        $output = trim($result->stderr) !== '' ? $result->stderr : $result->stdout;

        return [$result->ok(), $output];
    }

    /**
     * A failed reload must always be loud
     *
     * This used to be let through without checking the exit code — the
     * result was the panel reporting "website created successfully" even
     * though FPM had never actually created the new pool's socket, so the
     * site answered 503 to every request, with no way for the user to know
     * they had to go trigger a reload themselves.
     *
     * By this point the config file has already been written and already
     * passed validation, so the message says plainly that the config
     * itself wasn't lost — the service just hasn't picked it up yet.
     */
    public function reload(Executor $executor, string $version): void
    {
        $unit = ServiceCatalog::fpmUnit(Validator::phpVersion($version));
        $result = $executor->exec([$executor->path('/usr/bin/systemctl'), 'reload', $unit], timeout: 30);

        if (!$result->ok()) {
            throw new ExecutionFailed(sprintf(
                "The configuration was written successfully, but reloading %s failed — the website will not work until the reload succeeds\n\n%s",
                $unit,
                trim($result->stderr ?: $result->stdout),
            ));
        }
    }

    /**
     * The extensions currently enabled for that version, read from the conf.d directory
     *
     * @return list<string>
     */
    public function extensions(Executor $executor, string $version): array
    {
        $version = Validator::phpVersion($version);
        $dir = $executor->path('/etc/php/' . $version . '/fpm/conf.d');

        if (!$executor->exists($dir)) {
            return [];
        }

        $found = [];
        foreach (glob($dir . '/*.ini') ?: [] as $file) {
            // Filename shape: 20-mbstring.ini
            if (preg_match('/^\d+-([a-z0-9_]+)\.ini$/i', basename($file), $m) === 1) {
                $found[] = strtolower($m[1]);
            }
        }

        sort($found);

        return array_values(array_unique($found));
    }
}
