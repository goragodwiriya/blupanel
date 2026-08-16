<?php

declare(strict_types=1);

namespace Phpcp\Agent\Capability;

use Phpcp\Agent\Context;
use Phpcp\Agent\Executor\Executor;
use Phpcp\Agent\ValidationError;
use Phpcp\Domain\MailAddress;
use Phpcp\Security\Password;
use Phpcp\Support\Validator;

/**
 * Edits an existing mailbox — sets a new password, or changes its quota (PLAN-MAIL Phase M2)
 *
 * Both are combined into one capability because they're both "edit this
 * mailbox", and the same form on the web page can send them together · splitting
 * this into two capabilities would force the page to fire two requests and then
 * decide for itself what to do if the first succeeded but the second failed.
 *
 * **An empty password sent means "don't change it"**, not "set it to empty" —
 * the edit form always leaves the password field blank, because the system never
 * sends the existing password back to display it (only its hash is ever stored).
 */
final class MailBoxUpdate extends MailCapability
{
    public static function name(): string
    {
        return 'mail.box_update';
    }

    public function summary(): string
    {
        return 'Set a new password or change a mailbox quota';
    }

    public function validate(array $args): array
    {
        $address = MailAddress::parse(Validator::requireString($args, 'address', 320));
        $quota = isset($args['quota_mb']) ? (int) $args['quota_mb'] : 0;

        if ($quota !== 0 && ($quota < 1 || $quota > 1024 * 100)) {
            throw new ValidationError('Mailbox size must be between 1 MB and 100 GB');
        }

        return [
            'local_part' => $address->localPart,
            'domain' => $address->domain,
            'quota_mb' => $quota,
            'password' => (string) ($args['password'] ?? ''),
            'reset_password' => (bool) ($args['reset_password'] ?? false),
        ];
    }

    public function run(array $args, Executor $executor, Context $context): array
    {
        $repository = $this->repository($context);
        $domain = $this->domainOrFail($context, $args['domain']);
        $mailbox = $repository->findMailbox((int) $domain['id'], $args['local_part']);

        if ($mailbox === null) {
            throw new ValidationError('Mailbox not found: ' . $args['local_part'] . '@' . $args['domain']);
        }

        $manager = $this->mailboxes($context);
        $plain = '';

        if ($args['password'] !== '' || $args['reset_password']) {
            $plain = $args['password'] !== '' ? $args['password'] : Password::random(16);
            $repository->setPassword((int) $mailbox['id'], $manager->hashPassword($executor, $plain));
        }

        if ($args['quota_mb'] > 0) {
            $repository->setQuota((int) $mailbox['id'], $args['quota_mb']);
        }

        $result = $this->sync($executor, $context);
        $address = (new MailAddress($args['local_part'], $args['domain']))->full();

        return $result + [
            'address' => $address,
            // Shown exactly once, same as when the mailbox is created
            'password' => $plain,
            // Only flagged when the system generated the password — same reason as at creation
            'password_generated' => $plain !== '' && $args['password'] === '',
            'message' => $plain !== ''
                ? sprintf('Set a new password for %s', $address)
                : sprintf('Saved mailbox %s', $address),
        ];
    }
}
