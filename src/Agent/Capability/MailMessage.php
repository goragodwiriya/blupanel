<?php

declare(strict_types=1);

namespace Phpcp\Agent\Capability;

use Phpcp\Agent\Capability;
use Phpcp\Agent\Context;
use Phpcp\Agent\Executor\Executor;
use Phpcp\Driver\Mail\MailQueue;
use Phpcp\Support\Validator;

/**
 * Opens one message **still sitting in the queue** — PLAN-MAIL §5
 *
 * ## Why this isn't reading a customer's mail
 *
 * A queued message hasn't been delivered yet — it has never landed in anyone's
 * mailbox at all · opening it never touches anyone's Maildir, changes no "read"
 * flag, and no mailbox owner ever feels like their mail was opened, because no
 * mailbox owner has seen it yet.
 *
 * This is the deliberate boundary: it fully answers "why didn't this message go
 * out", without becoming a tool for reading a customer's mailbox, which is a
 * separate matter requiring its own separate decision.
 */
final class MailMessage implements Capability
{
    public static function name(): string
    {
        return 'mail.message';
    }

    /** A queued message could belong to any customer — machine admins only */
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
        return 'Read one queued message';
    }

    public function validate(array $args): array
    {
        return ['id' => MailQueue::assertId(Validator::requireString($args, 'id', 40))];
    }

    public function run(array $args, Executor $executor, Context $context): array
    {
        return [
            'id' => $args['id'],
            'content' => (new MailQueue())->message($executor, $args['id']),
        ];
    }
}
