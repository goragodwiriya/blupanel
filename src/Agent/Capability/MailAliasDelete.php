<?php

declare(strict_types=1);

namespace Phpcp\Agent\Capability;

use Phpcp\Agent\Context;
use Phpcp\Agent\Executor\Executor;
use Phpcp\Agent\ValidationError;

/** ลบที่อยู่ส่งต่อ — PLAN-MAIL เฟส M2 */
final class MailAliasDelete extends MailCapability
{
    public static function name(): string
    {
        return 'mail.alias_delete';
    }

    public function summary(): string
    {
        return 'ลบที่อยู่ส่งต่อ';
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
            throw new ValidationError('ไม่พบที่อยู่ส่งต่อที่ระบุ');
        }

        $repository->deleteAlias($args['id']);

        return $this->sync($executor, $context) + [
            'message' => sprintf(
                'ลบที่อยู่ส่งต่อ %s แล้ว',
                ((string) $alias['source'] === '' ? '@' : $alias['source'] . '@') . $alias['domain'],
            ),
        ];
    }
}
