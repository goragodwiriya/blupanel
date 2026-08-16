<?php

declare(strict_types=1);

namespace Phpcp\Agent\Capability;

use Phpcp\Agent\Capability;
use Phpcp\Agent\Context;
use Phpcp\Agent\Executor\Executor;
use Phpcp\Driver\Mail\MailQueue;

/**
 * The outbound mail queue — PLAN-MAIL §5
 *
 * **Permission is `settings.manage`, not `mail.view` as the plan originally wrote it**
 *
 * The queue belongs to the whole machine, with every row mixing **every
 * customer's** sender and recipient addresses together · `mail.view` is a
 * permission site owners hold themselves, so letting them view the whole
 * machine's queue would mean one customer sees who another customer is in
 * contact with · the plan hadn't thought through multi-tenancy on this point when
 * it was written.
 *
 * Filtering the queue per owner is possible (comparing the sender/recipient
 * domain), but hasn't been done in this pass — half-showing a customer's data is
 * worse than not showing it at all.
 */
final class MailQueueList implements Capability
{
    public static function name(): string
    {
        return 'mail.queue';
    }

    public function permission(): string
    {
        return 'settings.manage';
    }

    public function isMutating(): bool
    {
        return false;
    }

    public function summary(): string
    {
        return 'Read outbound mail queue';
    }

    public function validate(array $args): array
    {
        return [];
    }

    public function run(array $args, Executor $executor, Context $context): array
    {
        $queue = (new MailQueue())->list($executor);

        return $queue + [
            'message' => $queue['available']
                ? sprintf('%d message(s) in the queue (%d deferred)', $queue['total'], $queue['deferred'])
                : 'Could not read the queue — Postfix on this machine is older than 3.1 and does not support postqueue -j',
        ];
    }
}
