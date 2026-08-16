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

/**
 * Sends a test email through the exact same path a user's website would use
 *
 * Uses the system's `sendmail`, not a direct SMTP connection, because what has to
 * be proven is "does the path a website actually uses work". Testing through a
 * different path and having it pass would leave the user believing mail works,
 * while `mail()` inside their website still can't send anything at all.
 */
final class MailTest implements Capability
{
    public static function name(): string
    {
        return 'mail.test';
    }

    public function permission(): string
    {
        return 'settings.manage';
    }

    public function isMutating(): bool
    {
        return true;
    }

    public function summary(): string
    {
        return 'Send test email';
    }

    public function validate(array $args): array
    {
        return ['to' => MailManager::assertEmail(trim((string) ($args['to'] ?? '')))];
    }

    public function run(array $args, Executor $executor, Context $context): array
    {
        $settings = new SettingsRepository($context->db);
        $from = $settings->get('mail.from');

        if ($from === '') {
            throw new ValidationError('No sender address is set yet — fill it in on the settings page first');
        }

        $manager = new MailManager(new Template($context->config->paths->templates()));
        $result = $manager->sendTest($executor, $args['to'], $from);

        return [
            'to' => $result['to'],
            'queued' => $result['queued'],
            'message' => sprintf(
                'Sent a test email to %s%s — if it doesn\'t arrive within 5 minutes, check '
                . 'the Postfix log and make sure it didn\'t land in spam',
                $result['to'],
                $result['queued'] > 0 ? sprintf(' (%d message(s) still queued)', $result['queued']) : '',
            ),
        ];
    }
}
