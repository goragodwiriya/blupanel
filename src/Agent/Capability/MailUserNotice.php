<?php

declare(strict_types=1);

namespace Phpcp\Agent\Capability;

use Phpcp\Agent\Capability;
use Phpcp\Agent\Context;
use Phpcp\Agent\Executor\Executor;
use Phpcp\Agent\ValidationError;
use Phpcp\Domain\SettingsRepository;
use Phpcp\Driver\Mail\MailManager;
use Phpcp\Driver\Template;
use Phpcp\Support\Validator;

/**
 * Sends one panel message to one account holder — their welcome email, or the
 * notice that their password was reset
 *
 * ## The recipient is read here, never accepted from the caller
 *
 * The caller sends a **user id**, and this looks the email address up in the
 * users table itself. That one decision is what stops this becoming a way to
 * mail arbitrary strangers: even a fully compromised web tier can only ever
 * make the machine send mail to an address that is already on the account —
 * an address only an admin can change, through a route that writes an audit
 * entry when they do.
 *
 * `EmailNotifier` already states the rule this follows: a notifier must never
 * become a spam vector, so the recipient comes from stored state and never
 * from something typed into a request.
 *
 * ## The body is composed in the web tier
 *
 * Everything in a welcome email — websites, databases, mailboxes, quotas —
 * already lives in the panel's database, and `Domain\UserNotice` reads it
 * there. Rebuilding all of it inside the agent would mean a second copy of the
 * same knowledge, kept in step by hand. What only the agent can do is run
 * `sendmail`, and that is all it does here.
 */
final class MailUserNotice implements Capability
{
    /** Long enough for a welcome email with a dozen sites, short enough not to be a way to queue a large file */
    private const MAX_BODY = 20000;

    public static function name(): string
    {
        return 'mail.user_notice';
    }

    public function permission(): string
    {
        return 'customer.manage';
    }

    public function isMutating(): bool
    {
        // Mutating on purpose, although nothing on disk changes — the Dispatcher
        // writes an audit entry for a mutating capability, and "who mailed this
        // customer their password, and when" is exactly the kind of thing that
        // has to be answerable later
        return true;
    }

    public function summary(): string
    {
        return 'Send an account notice to a hosting customer';
    }

    public function validate(array $args): array
    {
        $body = (string) ($args['body'] ?? '');

        if (trim($body) === '') {
            throw new ValidationError('The email body must not be empty');
        }

        if (strlen($body) > self::MAX_BODY) {
            throw new ValidationError('The email body is too long');
        }

        return [
            'user_id' => Validator::requireInt($args, 'user_id', 1),
            // 200 characters is already past what any mail client shows · the
            // shape check that matters (no newline) is MailManager's, which
            // base64-encodes the subject rather than filtering it
            'subject' => Validator::requireString($args, 'subject', 200),
            'body' => $body,
        ];
    }

    public function run(array $args, Executor $executor, Context $context): array
    {
        $user = $context->db->first(
            'SELECT username, email FROM users WHERE id = :id',
            ['id' => $args['user_id']],
        );

        if ($user === null) {
            throw new ValidationError('User not found');
        }

        $to = trim((string) ($user['email'] ?? ''));

        if ($to === '') {
            throw new ValidationError(sprintf('%s has no email address on file — add one before sending', $user['username']));
        }

        $from = trim((new SettingsRepository($context->db))->get('mail.from'));

        if ($from === '') {
            throw new ValidationError(
                'No sender address is set yet — fill in the outgoing mail sender on the settings page first',
            );
        }

        $result = (new MailManager(new Template($context->config->paths->templates())))
            ->sendMessage($executor, $to, $from, $args['subject'], $args['body']);

        return [
            'to' => $result['to'],
            'queued' => $result['queued'],
            'username' => (string) $user['username'],
            'message' => sprintf(
                'Sent to %s%s',
                $result['to'],
                $result['queued'] > 0 ? sprintf(' (%d message(s) still in the outgoing queue)', $result['queued']) : '',
            ),
        ];
    }
}
