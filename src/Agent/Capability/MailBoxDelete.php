<?php

declare(strict_types=1);

namespace Phpcp\Agent\Capability;

use Phpcp\Agent\Context;
use Phpcp\Agent\Executor\Executor;
use Phpcp\Agent\ValidationError;
use Phpcp\Domain\MailAddress;
use Phpcp\Driver\Mail\MailboxManager;
use Phpcp\Support\Validator;

/**
 * Deletes a mailbox along with every message in it — PLAN-MAIL Phase M1
 *
 * **Order matters:** delete the row → rewrite the table (the mailbox is now gone
 * from Postfix) → only then delete the files. Deleting files first would leave a
 * window where Postfix still accepts mail for that mailbox with nowhere to store
 * it, bouncing a message that arrives at exactly the wrong moment with a
 * confusing "mailbox exists but is not writable" error.
 */
final class MailBoxDelete extends MailCapability
{
    public static function name(): string
    {
        return 'mail.box_delete';
    }

    public function summary(): string
    {
        return 'Delete mailbox and all its mail';
    }

    public function validate(array $args): array
    {
        $address = MailAddress::parse(Validator::requireString($args, 'address', 320));

        return ['local_part' => $address->localPart, 'domain' => $address->domain];
    }

    public function run(array $args, Executor $executor, Context $context): array
    {
        $repository = $this->repository($context);
        $domain = $this->domainOrFail($context, $args['domain']);
        $mailbox = $repository->findMailbox((int) $domain['id'], $args['local_part']);

        if ($mailbox === null) {
            throw new ValidationError('Mailbox not found: ' . $args['local_part'] . '@' . $args['domain']);
        }

        $address = new MailAddress($args['local_part'], $args['domain']);

        $repository->deleteMailbox((int) $mailbox['id']);

        $result = $this->sync($executor, $context);

        $this->mailboxes($context)->removeMaildir(
            $executor,
            $address->maildir(MailboxManager::MAIL_ROOT),
        );

        return $result + [
            'address' => $address->full(),
            'message' => sprintf('Deleted mailbox %s and all its mail', $address->full()),
        ];
    }
}
