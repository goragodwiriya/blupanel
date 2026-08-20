<?php

declare(strict_types=1);

namespace Phpcp\Agent\Capability;

use Phpcp\Agent\Capability;
use Phpcp\Agent\Context;
use Phpcp\Agent\Executor\Executor;
use Phpcp\Agent\ValidationError;
use Phpcp\Domain\PhpSupport;
use Phpcp\Domain\ServiceCatalog;
use Phpcp\Driver\Php\FpmManager;
use Phpcp\Driver\Php\PhpPackageJob;
use Phpcp\Driver\Template;
use Phpcp\Support\Validator;

/**
 * Installs one PHP version's hosting packages through apt
 *
 * ## Why this starts a job instead of doing the work
 *
 * `apt-get install php8.4-*` takes one to five minutes on a normal machine.
 * The agent's socket timeout is thirty seconds, so a synchronous call cannot
 * finish — it would fail with a transport error while apt kept running in the
 * background, leaving the admin with an error message and a half-installed
 * version and no way to tell which.
 *
 * So the work is handed to systemd (`systemd-run`), which returns immediately,
 * and {@see PhpJobStatus} reports on it afterwards. That reuses the process
 * supervisor the panel already depends on everywhere rather than growing a
 * queue and a worker of its own — and it means an admin who closes the browser
 * does not cancel the install.
 *
 * ## The command line is built, never accepted
 *
 * The version is checked against a strict shape and must genuinely be offered
 * by apt, and the package names are then generated from
 * {@see ServiceCatalog::phpPackages()}. Nothing the caller sends reaches argv
 * as a word of its own, so there is no "extra package" to slip in.
 */
final class PhpInstall implements Capability
{
    public static function name(): string
    {
        return 'php.install';
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
        return 'Install a PHP version';
    }

    public function validate(array $args): array
    {
        return ['version' => Validator::phpVersion(Validator::requireString($args, 'version', 8))];
    }

    public function run(array $args, Executor $executor, Context $context): array
    {
        $version = $args['version'];
        $fpm = new FpmManager(new Template($context->config->paths->templates()));

        if ($fpm->isVersionInstalled($executor, $version)) {
            return [
                'version' => $version,
                'started' => false,
                'message' => sprintf('PHP %s is already installed', $version),
            ];
        }

        /*
         * **Must be offered by apt, not merely well-formed**
         *
         * Without this, a typo starts a job that spends a minute reaching the
         * package manager only to fail with "unable to locate package" in a log
         * the admin has to go and find · asking the local index costs
         * milliseconds and turns that into an answer on the screen
         */
        if (!in_array($version, $fpm->availableVersions($executor), true)) {
            throw new ValidationError(sprintf(
                'PHP %s is not offered by this machine\'s apt repositories — add the repository that provides it first '
                . '(ppa:ondrej/php on Ubuntu, packages.sury.org on Debian)',
                $version,
            ));
        }

        $job = new PhpPackageJob();

        return $job->start(
            $executor,
            $version,
            'install',
            [...['install', '-y', '--no-install-recommends'], ...ServiceCatalog::phpPackages($version)],
        ) + [
            'version' => $version,
            'supported' => PhpSupport::isSupported($version),
        ];
    }
}
