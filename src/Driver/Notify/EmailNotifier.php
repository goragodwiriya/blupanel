<?php

declare(strict_types=1);

namespace Phpcp\Driver\Notify;

use Phpcp\Agent\ExecutionFailed;
use Phpcp\Agent\Executor\Executor;
use Phpcp\Agent\ValidationError;
use Phpcp\Driver\Mail\MailManager;

/**
 * Sends email notifications through the Postfix already installed — PLAN-V2 phase E6
 *
 * **Uses the system's own `sendmail`, never connects SMTP directly** — the
 * same reason `MailManager::sendTest()` does: what needs proving is that
 * *this machine's* outbound mail genuinely works, not that PHP can connect
 * to some SMTP server somewhere · Postfix already handles queueing, retries,
 * and logging, all better than this code could do on its own.
 *
 * **Requires an `Executor`**, unlike Telegram/webhook, which can hit HTTPS
 * directly — running a program on the machine must always go through
 * `Executor`, to get the full set of audit logging, dryrun mode, and
 * permission limits (ARCHITECTURE §4.4) · a caller with no executor
 * therefore cannot send email, and that's correct.
 *
 * **A notifier must never become a spam vector** — the recipient comes only
 * from an admin's own setting, never from anything an end user typed in,
 * and the subject/body are always encoded first.
 */
final class EmailNotifier
{
    private const SENDMAIL = '/usr/sbin/sendmail';

    /** Short, since this is a notification — Postfix accepts it into its queue and that's the end of it, no wait for real delivery */
    private const TIMEOUT = 15;

    /** Long bodies are truncated before sending — a whole log file shouldn't become an email */
    private const MAX_BODY = 4000;

    public function __construct(
        private readonly Executor $executor,
        private readonly string $to,
        private readonly string $from,
    ) {
    }

    public function isConfigured(): bool
    {
        return $this->to !== '' && $this->from !== '';
    }

    /** Sends in "can fail, never throws" mode — used everywhere an automatic notification is sent */
    public function notify(string $title, string $body, string $level = 'info'): bool
    {
        if (!$this->isConfigured()) {
            return false;
        }

        try {
            $this->send($title, $body, $level);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /** Sends in "must know if it fails" mode — used only when a user clicks the test button */
    public function test(): array
    {
        if (!$this->isConfigured()) {
            throw new ValidationError('The recipient or sender email for notifications is not set yet');
        }

        $this->send(
            'Email notification test',
            "If you received this message, email notifications are working\n"
            . 'Sent from PHP Server Control Panel at ' . date('d/m/Y H:i:s'),
            'ok',
        );

        return ['to' => $this->to];
    }

    private function send(string $title, string $body, string $level): void
    {
        $icon = match ($level) {
            'ok' => '[OK]',
            'warn', 'warning' => '[WARN]',
            'danger' => '[URGENT]',
            default => '[ALERT]',
        };

        $subject = $icon . ' ' . $title;

        // A subject containing non-ASCII characters has to be encoded as
        // Base64 per RFC 2047, or a mail reader shows garbled characters ·
        // the body declares its charset in the header, so it can be sent raw
        $message = sprintf(
            "From: %s\r\nTo: %s\r\nSubject: =?UTF-8?B?%s?=\r\n"
            . "X-Phpcp-Level: %s\r\n"
            . "MIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\n\r\n%s\r\n",
            $this->from,
            $this->to,
            base64_encode($subject),
            $level,
            $this->footer(mb_substr($body, 0, self::MAX_BODY)),
        );

        $result = $this->executor->exec(
            [$this->executor->path(self::SENDMAIL), '-t', '-i', '-f', $this->from],
            timeout: self::TIMEOUT,
            stdin: $message,
        );

        if (!$result->ok()) {
            throw new ExecutionFailed('Failed to send notification email: ' . trim($result->stderr ?: $result->stdout));
        }
    }

    /** States which machine this came from — an admin watching several machines has to be able to tell them apart from the email alone */
    private function footer(string $body): string
    {
        return $body . "\n\n-- \n" . 'phpcp on ' . (gethostname() ?: 'unknown host');
    }

    /** Validates email format when settings are saved — uses the same rule as the whole system's outbound mail */
    public static function assertEmail(string $email): string
    {
        return $email === '' ? '' : MailManager::assertEmail($email);
    }
}
