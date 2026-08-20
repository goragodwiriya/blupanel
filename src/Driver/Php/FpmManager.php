<?php

declare(strict_types=1);

namespace Phpcp\Driver\Php;

use Phpcp\Agent\ExecutionFailed;
use Phpcp\Agent\Executor\Executor;
use Phpcp\Domain\PhpSupport;
use Phpcp\Domain\ServiceCatalog;
use Phpcp\Domain\Site;
use Phpcp\Domain\PhpSettings;
use Phpcp\Driver\SafeBlock;
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
        $php = $site->php();

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
            'MAX_CHILDREN' => $php->maxChildren,
            'REQUEST_TIMEOUT' => $php->requestTerminateTimeout() . 's',
            'PHP_TUNABLES' => self::tunableBlock($php),
        ]);
    }

    /**
     * The admin-settable directives, as lines of a pool file
     *
     * Built here rather than as one placeholder per directive, because
     * `date.timezone` has to be **absent** when it isn't set — a template with a
     * `{{TIMEZONE}}` hole would have to write an empty value instead, and an
     * empty `date.timezone` makes every date call in every customer's site emit
     * a warning.
     *
     * Every value goes through `Template::assertValue()` on the way in, exactly
     * like nginx's server_name does — a `SafeBlock` built by hand is the one
     * place in this codebase where a newline could reach a config file without
     * anything checking, and "PhpSettings validated it already" is the kind of
     * assumption that stops being true the day someone adds a field.
     */
    public static function tunableBlock(PhpSettings $php): SafeBlock
    {
        $lines = [];

        foreach ($php->iniDirectives() as $ini => $value) {
            $lines[] = sprintf(
                '%s[%s] = %s',
                PhpSettings::isFlag($ini) ? 'php_admin_flag' : 'php_admin_value',
                Template::assertValue('php ini directive', $ini),
                Template::assertValue($ini, $value),
            );
        }

        return new SafeBlock(implode("\n", $lines));
    }

    /**
     * The PHP versions genuinely installed on the machine
     *
     * @return list<string>
     */
    public function installedVersions(Executor $executor): array
    {
        /*
         * **Reads the directory, rather than checking a list of names**
         *
         * This used to walk `ServiceCatalog::PHP_VERSIONS` and ask whether each
         * one existed, which meant a version genuinely installed on the machine
         * was invisible to the panel until somebody added its number to that
         * constant · the day PHP 8.6 reached the repository, an admin could
         * `apt install php8.6-fpm`, watch systemd start it, and still be told by
         * the panel that it was not there · scanning is also simply the truth:
         * a version with an `fpm` directory under `/etc/php` is one php-fpm can
         * actually serve, and nothing else is
         */
        $found = [];

        foreach (glob($executor->path('/etc/php') . '/*/fpm', GLOB_ONLYDIR) ?: [] as $dir) {
            $version = basename(dirname($dir));

            if (PhpSupport::isValid($version)) {
                $found[] = $version;
            }
        }

        return PhpSupport::sortNewestFirst($found);
    }

    /**
     * Versions this machine's apt repositories could install
     *
     * Asked of apt rather than kept as a list, for the same reason as above and
     * one more: the answer differs per machine. A Debian box with the sury
     * repository added offers a completely different set from an Ubuntu box
     * without the PPA, and a list compiled into the panel would show an admin a
     * version they cannot actually install, or hide one they can.
     *
     * Returns an empty list on any failure — a machine with no apt (or one
     * whose repositories are briefly unreachable) can still see and use what is
     * already installed, which is the part that matters. The caller falls back
     * to {@see PhpSupport::known()} for display.
     *
     * @return list<string>
     */
    public function availableVersions(Executor $executor): array
    {
        $binary = $executor->path('/usr/bin/apt-cache');

        if (!$executor->exists($binary)) {
            return [];
        }

        /*
         * `--names-only` with an anchored pattern, so this matches package
         * *names* and never a description that happens to mention php-fpm ·
         * `apt-cache` reads the local package index only, so this makes no
         * network request and cannot hang on an unreachable mirror
         */
        $result = $executor->exec(
            [$binary, 'search', '--names-only', '^php[0-9]+\.[0-9]+-fpm$'],
            timeout: 20,
        );

        if (!$result->ok()) {
            return [];
        }

        $found = [];

        foreach (preg_split('/\R/', $result->stdout) ?: [] as $line) {
            if (preg_match('/^php(\d\.\d{1,2})-fpm\s/', trim($line), $m) === 1) {
                $found[] = $m[1];
            }
        }

        return PhpSupport::sortNewestFirst(array_values(array_unique($found)));
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
