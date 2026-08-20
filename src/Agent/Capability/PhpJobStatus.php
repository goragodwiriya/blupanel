<?php

declare(strict_types=1);

namespace Phpcp\Agent\Capability;

use Phpcp\Agent\Capability;
use Phpcp\Agent\Context;
use Phpcp\Agent\Executor\Executor;
use Phpcp\Driver\Php\PhpPackageJob;
use Phpcp\Support\Validator;

/**
 * How an install or removal started by {@see PhpInstall} / {@see PhpRemove} is getting on
 *
 * The screen polls this while a job runs. Read-only and cheap — two
 * `systemctl`/`journalctl` reads — because it is called every few seconds for
 * as long as apt takes.
 *
 * **`php.manage`, not `php.view`.** The job's output is an apt transcript from
 * a command run as root: repository URLs, package versions, and any error text
 * the machine produced. That belongs to whoever is allowed to run the job, not
 * to everybody who can see which PHP versions exist.
 */
final class PhpJobStatus implements Capability
{
    public static function name(): string
    {
        return 'php.job_status';
    }

    public function permission(): string
    {
        return 'php.manage';
    }

    public function isMutating(): bool
    {
        return false;
    }

    public function summary(): string
    {
        return 'Read the progress of a PHP install or removal';
    }

    public function validate(array $args): array
    {
        $version = trim((string) ($args['version'] ?? ''));

        // Optional · empty means "whichever job is on this machine", which is
        // what the page actually asks — see PhpPackageJob::findVersion() for
        // why the screen must not be the thing that remembers which version
        return ['version' => $version === '' ? '' : Validator::phpVersion($version)];
    }

    public function run(array $args, Executor $executor, Context $context): array
    {
        return (new PhpPackageJob())->status($executor, $args['version']);
    }
}
