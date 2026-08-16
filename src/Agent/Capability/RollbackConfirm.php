<?php

declare(strict_types=1);

namespace Phpcp\Agent\Capability;

use Phpcp\Agent\Capability;
use Phpcp\Agent\Context;
use Phpcp\Agent\Executor\Executor;
use Phpcp\Driver\RollbackGuard;
use Phpcp\Support\Validator;

/**
 * Confirms the connection still works — cancels the automatic rollback
 *
 * The fact that makes this mechanism work: this request "arriving at all" is
 * itself the proof that the connection still works after the change.
 */
final class RollbackConfirm implements Capability
{
    public static function name(): string
    {
        return 'rollback.confirm';
    }

    public function permission(): string
    {
        return 'security.manage';
    }

    public function isMutating(): bool
    {
        return true;
    }

    public function summary(): string
    {
        return 'Confirm a pending change';
    }

    public function validate(array $args): array
    {
        return ['rollback_id' => Validator::requireInt($args, 'rollback_id', 1)];
    }

    public function run(array $args, Executor $executor, Context $context): array
    {
        $row = (new RollbackGuard($context->db))->confirm($args['rollback_id']);

        return [
            'rollback_id' => $args['rollback_id'],
            'action' => $row['action'],
            'message' => 'Confirmed — the change is now permanent, the system will not roll it back',
        ];
    }
}
