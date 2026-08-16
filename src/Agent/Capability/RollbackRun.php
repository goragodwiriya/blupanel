<?php

declare(strict_types=1);

namespace Phpcp\Agent\Capability;

use Phpcp\Agent\Capability;
use Phpcp\Agent\Context;
use Phpcp\Agent\Executor\Executor;
use Phpcp\Driver\RollbackGuard;

/**
 * Rolls back items that ran out of confirmation time
 *
 * Called from two paths: the panel's own cron every minute, and whenever the web
 * page loads and finds a pending item. The first path is necessary because the
 * case that needs recovering is exactly the case where the user has been cut off
 * — there's no request left to trigger it.
 */
final class RollbackRun implements Capability
{
    public static function name(): string
    {
        return 'rollback.run';
    }

    public function permission(): string
    {
        return 'security.view';
    }

    public function isMutating(): bool
    {
        return true;
    }

    public function summary(): string
    {
        return 'Roll back changes that ran out of confirmation time';
    }

    public function validate(array $args): array
    {
        return [];
    }

    public function run(array $args, Executor $executor, Context $context): array
    {
        $done = (new RollbackGuard($context->db))->rollbackExpired($executor);

        return [
            'rolled_back' => $done,
            'count' => count($done),
            'message' => $done === []
                ? 'Nothing to roll back'
                : sprintf('Rolled back %d expired change(s)', count($done)),
        ];
    }
}
