<?php

declare(strict_types=1);

namespace Phpcp\Agent\Capability;

use Phpcp\Agent\Context;
use Phpcp\Agent\Executor\Executor;
use Phpcp\Agent\ValidationError;
use Phpcp\Domain\MailAddress;
use Phpcp\Domain\QuotaChecker;
use Phpcp\Domain\UserRepository;
use Phpcp\Driver\Mail\MailboxManager;
use Phpcp\Security\Password;
use Phpcp\Support\Validator;

/**
 * Creates a new mailbox — PLAN-MAIL Phase M1
 *
 * **The password is hashed right here, not at the web tier**, and hashed with
 * Dovecot's own `doveadm pw`, not PHP's `password_hash()` — the format has to be
 * Dovecot's own ({ARGON2ID}...), or login fails with no message explaining why.
 *
 * The real password is sent back to display **exactly once**, same as a
 * database password — the system never stores it anywhere; forget it and it has
 * to be reset.
 */
final class MailBoxCreate extends MailCapability
{
    /** Default mailbox size — enough for typical use, without eating disk alarmingly */
    private const DEFAULT_QUOTA_MB = 1024;

    public static function name(): string
    {
        return 'mail.box_create';
    }

    public function summary(): string
    {
        return 'Create new mailbox';
    }

    public function validate(array $args): array
    {
        $address = MailAddress::parse(Validator::requireString($args, 'address', 320));
        $quota = (int) ($args['quota_mb'] ?? self::DEFAULT_QUOTA_MB);

        if ($quota < 1 || $quota > 1024 * 100) {
            throw new ValidationError('Mailbox size must be between 1 MB and 100 GB');
        }

        return [
            'local_part' => $address->localPart,
            'domain' => $address->domain,
            'quota_mb' => $quota,
            'password' => (string) ($args['password'] ?? ''),
        ];
    }

    public function run(array $args, Executor $executor, Context $context): array
    {
        $repository = $this->repository($context);
        $domain = $this->domainOrFail($context, $args['domain']);

        if ((int) ($domain['mail_enabled'] ?? 0) !== 1) {
            throw new ValidationError(
                'Domain ' . $args['domain'] . ' does not have mail enabled yet — enable it before creating a mailbox',
            );
        }

        if ($repository->findMailbox((int) $domain['id'], $args['local_part']) !== null) {
            throw new ValidationError('Mailbox ' . $args['local_part'] . '@' . $args['domain'] . ' already exists');
        }

        /*
         * The owner's mailbox-count quota — the `quota_emails` field on the
         * customer page has existed from the start, but nothing ever enforced
         * it, since there was no real mail system yet · checked in this one
         * place, so the web page and the CLI share the exact same rule
         */
        $owner = $repository->ownerOf((int) $domain['id']);

        if ($owner > 0) {
            $quota = (new QuotaChecker(new UserRepository($context->db)))->canCreate($owner, 'emails');

            if (!$quota['ok']) {
                throw new ValidationError((string) $quota['message']);
            }
        }

        // Generated randomly if not specified — the admin doesn't have to come up with one (which usually produces a weak password)
        $plain = $args['password'] !== '' ? $args['password'] : Password::random(16);
        $manager = $this->mailboxes($context);

        $address = new MailAddress($args['local_part'], $args['domain']);
        $maildir = $address->maildir(MailboxManager::MAIL_ROOT);

        $id = $repository->createMailbox(
            (int) $domain['id'],
            $args['local_part'],
            $manager->hashPassword($executor, $plain),
            $args['quota_mb'],
        );

        // The folder is created before writing the table — writing the table
        // first and having folder creation fail would leave a window where
        // Postfix accepts mail for a mailbox with nowhere to store it, and mail bounces
        $manager->createMaildir($executor, $maildir);

        $result = $this->sync($executor, $context);

        return $result + [
            'id' => $id,
            'address' => $address->full(),
            'quota_mb' => $args['quota_mb'],
            // Shown exactly once — the system never stores the real password anywhere
            'password' => $plain,
            /*
             * Whether it needs to be shown at all — a different question from "is
             * the password in the response"
             *
             * The password is always in the response, whether the system
             * generated it or the admin typed it themselves · using only
             * `password !== ''` as the condition would pop the password window
             * open on every save, including right after the admin typed that
             * exact password by hand — they'd see the window still sitting there
             * and think the form hadn't closed, even though the mailbox had
             * already been created.
             */
            'password_generated' => $args['password'] === '',
            'message' => sprintf('Created mailbox %s', $address->full()),
        ];
    }
}
