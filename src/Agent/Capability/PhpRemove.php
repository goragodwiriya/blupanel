<?php

declare(strict_types=1);

namespace Phpcp\Agent\Capability;

use Phpcp\Agent\Capability;
use Phpcp\Agent\Context;
use Phpcp\Agent\Executor\Executor;
use Phpcp\Agent\ValidationError;
use Phpcp\Domain\SiteRepository;
use Phpcp\Driver\Php\FpmManager;
use Phpcp\Driver\Php\PhpPackageJob;
use Phpcp\Driver\Template;
use Phpcp\Support\Validator;

/**
 * Removes one PHP version's packages
 *
 * ## Four refusals, and every one of them has a website behind it
 *
 * Removing a PHP version is the most destructive thing on the PHP page: it
 * takes every site on that version offline instantly and there is no undo
 * short of reinstalling and waiting. So this refuses, before starting
 * anything:
 *
 *   1. **A version any website still uses.** Those sites would start answering
 *      502 the moment the pool's socket disappeared, with nothing on their own
 *      pages to explain why.
 *   2. **The version the panel itself runs on.** The panel would be killed
 *      mid-request, leaving no screen on which to report what happened, and no
 *      way back in except a terminal.
 *   3. **The last version left.** A machine with no PHP cannot serve any site
 *      that might be created next, and the panel would be offering a list of
 *      versions with nothing in it.
 *   4. **A version that is not installed.** Nothing to do, and apt would fail
 *      with a message about the package rather than about the version.
 *
 * The list of blocked reasons is also sent with each row by {@see PhpList}, so
 * the button is not even offered — but that is a courtesy to the screen. This
 * is the gate, and it re-reads the site table itself rather than trusting
 * anything sent with the request.
 */
final class PhpRemove implements Capability
{
    public static function name(): string
    {
        return 'php.remove';
    }

    public function permission(): string
    {
        return 'php.manage';
    }

    public function isMutating(): bool
    {
        return true;
    }

    public function summary(): string
    {
        return 'Remove a PHP version';
    }

    public function validate(array $args): array
    {
        return ['version' => Validator::phpVersion(Validator::requireString($args, 'version', 8))];
    }

    public function run(array $args, Executor $executor, Context $context): array
    {
        $version = $args['version'];
        $fpm = new FpmManager(new Template($context->config->paths->templates()));
        $installed = $fpm->installedVersions($executor);

        if (!in_array($version, $installed, true)) {
            throw new ValidationError(sprintf('PHP %s is not installed on this machine', $version));
        }

        if ($version === PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION) {
            throw new ValidationError(sprintf(
                'PHP %s is the version the control panel itself runs on — removing it would stop the panel',
                $version,
            ));
        }

        if (count($installed) === 1) {
            throw new ValidationError(
                'This is the only PHP version on the machine — install another one before removing this',
            );
        }

        $inUse = (new SiteRepository($context->db))->countByPhpVersion()[$version] ?? 0;

        if ($inUse > 0) {
            throw new ValidationError(sprintf(
                '%d website(s) still use PHP %s — move them to another version first',
                $inUse,
                $version,
            ));
        }

        $job = new PhpPackageJob();

        /*
         * The names come from dpkg, not from the list the panel would have
         * installed · an admin may have added extensions by hand, and leaving
         * those behind keeps `/etc/php/<version>` on disk, which means the panel
         * goes on listing the version as installed after a removal it reported
         * as successful
         */
        $packages = $job->installedPackages($executor, $version);

        if ($packages === []) {
            throw new ValidationError(sprintf(
                'No PHP %s packages were found through dpkg, even though its configuration directory exists — '
                . 'remove it from a terminal and check what is left behind',
                $version,
            ));
        }

        /*
         * `purge`, not `remove` — `remove` leaves the config files behind, so
         * the version's directory under `/etc/php` survives, and the panel
         * (which scans that directory) goes on listing a version it has just
         * reported as removed.
         *
         * `--autoremove` is safe *here specifically* because of the refusals
         * above: another PHP version is always still installed, so the shared
         * packages every version depends on (`php-common` and friends) stay
         * required and are left alone. Only packages genuinely orphaned by
         * this removal go with it.
         */
        return $job->start(
            $executor,
            $version,
            'remove',
            [...['purge', '-y', '--autoremove'], ...$packages],
        ) + [
            'version' => $version,
            'packages' => $packages,
        ];
    }
}
