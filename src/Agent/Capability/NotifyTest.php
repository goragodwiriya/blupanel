<?php

declare(strict_types=1);

namespace Phpcp\Agent\Capability;

use Phpcp\Agent\Capability;
use Phpcp\Agent\Context;
use Phpcp\Agent\Executor\Executor;
use Phpcp\Domain\SettingsRepository;
use Phpcp\Driver\Notify\EmailNotifier;
use Phpcp\Driver\Notify\TelegramNotifier;
use Phpcp\Driver\Notify\WebhookNotifier;
use Phpcp\Support\Validator;

/**
 * Sends a test message to the specified channel — Telegram · email · webhook (PLAN-V2 Phase E6)
 *
 * Unlike an automatic notification, **a failure here has to be loud** — a user
 * who just clicked test would assume the setup was correct if it failed silently,
 * even with a wrong token, and then get no real notification on the day the
 * server actually has a problem.
 *
 * **Tests one channel at a time, never fires all of them together** — an admin
 * needs to know exactly which channel is broken. A combined test reporting
 * "2 of 3 succeeded" would still leave them guessing which one failed.
 */
final class NotifyTest implements Capability
{
    public static function name(): string
    {
        return 'notify.test';
    }

    public function permission(): string
    {
        return 'settings.manage';
    }

    public function isMutating(): bool
    {
        // Never changes system state, but sends a message out externally, so it belongs in the audit log
        return true;
    }

    public function summary(): string
    {
        return 'Send test notification message';
    }

    public function validate(array $args): array
    {
        // Not specified = Telegram, for compatibility with older callers that only had one channel
        return [
            'channel' => Validator::optionalEnum($args, 'channel', ['telegram', 'email', 'webhook'], 'telegram'),
        ];
    }

    public function run(array $args, Executor $executor, Context $context): array
    {
        $settings = new SettingsRepository($context->db);

        return match ($args['channel']) {
            'email' => $this->testEmail($executor, $settings),
            'webhook' => $this->testWebhook($settings),
            default => $this->testTelegram($settings),
        };
    }

    /** @return array<string,mixed> */
    private function testTelegram(SettingsRepository $settings): array
    {
        $result = (new TelegramNotifier(
            $settings->get('notify.telegram.token'),
            $settings->get('notify.telegram.chat_id'),
        ))->test();

        return [
            'channel' => 'telegram',
            'message_id' => $result['message_id'],
            'message' => 'Sent a test message to Telegram — check the chat to confirm it arrived',
        ];
    }

    /** @return array<string,mixed> */
    private function testEmail(Executor $executor, SettingsRepository $settings): array
    {
        $result = (new EmailNotifier(
            $executor,
            $settings->get('notify.email.to'),
            $settings->get('mail.from'),
        ))->test();

        return [
            'channel' => 'email',
            'to' => $result['to'],
            // Postfix accepting it into the queue is the end of the line here —
            // "sent successfully" doesn't yet mean it reached the mailbox. This
            // has to be stated clearly, or an admin would assume it's done when
            // it might still get stuck at the destination.
            'message' => sprintf(
                'Sent a test email to %s — Postfix accepted it into the queue, check the mailbox to confirm it actually arrived',
                $result['to'],
            ),
        ];
    }

    /** @return array<string,mixed> */
    private function testWebhook(SettingsRepository $settings): array
    {
        $result = (new WebhookNotifier(
            $settings->get('notify.webhook.url'),
            $settings->get('notify.webhook.secret'),
        ))->test();

        return [
            'channel' => 'webhook',
            'status' => $result['status'],
            'message' => sprintf('The webhook destination responded with code %d — sent successfully', $result['status']),
        ];
    }
}
