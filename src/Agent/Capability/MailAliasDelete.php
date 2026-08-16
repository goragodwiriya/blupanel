<?php

declare(strict_types=1);

namespace Phpcp\Agent\Capability;

use Phpcp\Agent\Context;
use Phpcp\Agent\Executor\Executor;
use Phpcp\Agent\ValidationError;

/** Deletes a forwarding address — PLAN-MAIL Phase M2 */
final class MailAliasDelete extends MailCapability
{
    public static function name(): string
    {
        return 'mail.alias_delete';
    }

    public function summary(): string
    {
        return 'Delete forwarding address';
    }

    public function validate(array $args): array
    {
        return ['id' => (int) ($args['id'] ?? 0)];
    }

    public function run(array $args, Executor $executor, Context $context): array
    {
        $repository = $this->repository($context);
        $alias = $repository->findAlias($args['id']);

        if ($alias === null) {
            throw new ValidationError('The specified forwarding address was not found');
        }

        $repository->deleteAlias($args['id']);

        return $this->sync($executor, $context) + [
            'message' => sprintf(
                'Deleted forwarding address %s',
                ((string) $alias['source'] === '' ? '@' : $alias['source'] . '@') . $alias['domain'],
            ),
        ];
    }
}
