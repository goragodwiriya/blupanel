<?php

declare(strict_types=1);

namespace Phpcp\Driver\Notify;

use Phpcp\Agent\ExecutionFailed;
use Phpcp\Agent\ValidationError;

/**
 * Sends notifications through Telegram
 *
 * Telegram was chosen because it's the one channel that can be set up in 2
 * minutes with no domain needed, no mail server needed, and no
 * spam-folder problem — which is exactly why most of a panel's email
 * notifications never actually reach anyone.
 *
 * The single most important thing to know: **a notification must never
 * fail the main job it's attached to** — if the network has a problem or
 * Telegram is down, creating a website still has to succeed exactly the
 * same · every method that sends a message therefore swallows its own
 * error, except when a user clicks "test", which has to be loud about it.
 */
final class TelegramNotifier
{
    private const API = 'https://api.telegram.org/bot';

    /** Short, since this is a notification, not the main job — it must never be allowed to run slow */
    private const TIMEOUT = 8;

    /** Telegram limits a message to 4096 characters */
    private const MAX_LENGTH = 3800;

    public function __construct(
        private readonly string $token,
        private readonly string $chatId,
    ) {
    }

    public function isConfigured(): bool
    {
        return $this->token !== '' && $this->chatId !== '';
    }

    /**
     * Sends in "can fail, never throws" mode
     *
     * Used everywhere an automatic notification is sent — the main job has
     * already succeeded by the time this is called · letting an exception
     * escape here would report a website that was already created
     * successfully as a failure, just because sending a notification failed.
     */
    public function notify(string $title, string $body, string $level = 'info'): bool
    {
        if (!$this->isConfigured()) {
            return false;
        }

        try {
            $this->send($this->format($title, $body, $level));

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Sends in "must know if it fails" mode
     *
     * Used only when a user clicks the test button — staying quiet on
     * failure would leave the user believing the setup is correct even with
     * a wrong token, and they wouldn't get a real notification exactly when it mattered.
     */
    public function test(): array
    {
        if (!$this->isConfigured()) {
            throw new ValidationError("Telegram's token or chat id is not set yet");
        }

        $response = $this->send($this->format(
            'Notification test',
            "If you see this message, the setup is correct\n"
            . 'Sent from PHP Server Control Panel at ' . date('d/m/Y H:i:s'),
            'ok',
        ));

        return ['message_id' => $response['result']['message_id'] ?? 0];
    }

    /**
     * Formats the message
     *
     * Uses Telegram's HTML mode and escapes every part of the content —
     * notification messages contain domain names and system error text
     * mixed in, which might contain < > & that would make Telegram reject
     * the whole message, turning into a notification that silently vanishes exactly when it matters most.
     */
    private function format(string $title, string $body, string $level): string
    {
        $icon = match ($level) {
            'ok' => '✅',
            'warn' => '⚠️',
            'danger' => '🚨',
            default => 'ℹ️',
        };

        $text = sprintf(
            "%s <b>%s</b>\n\n%s",
            $icon,
            htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            htmlspecialchars($body, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
        );

        return mb_strlen($text) > self::MAX_LENGTH
            ? mb_substr($text, 0, self::MAX_LENGTH) . "\n…"
            : $text;
    }

    /** @return array<string,mixed> */
    private function send(string $text): array
    {
        $handle = curl_init(self::API . $this->token . '/sendMessage');

        if ($handle === false) {
            throw new ExecutionFailed('Failed to start a connection to Telegram');
        }

        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => self::TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query([
                'chat_id' => $this->chatId,
                'text' => $text,
                'parse_mode' => 'HTML',
                'disable_web_page_preview' => 'true',
            ]),
        ]);

        $raw = curl_exec($handle);
        $error = curl_error($handle);
        curl_close($handle);

        if ($raw === false) {
            throw new ExecutionFailed('Failed to send message: ' . $error);
        }

        $data = json_decode((string) $raw, true);

        if (!is_array($data) || ($data['ok'] ?? false) !== true) {
            // Telegram's own error messages are direct enough to show a
            // user as-is — e.g. "chat not found" or "Unauthorized" already say how to fix it
            throw new ExecutionFailed(
                'Telegram rejected the message: ' . (is_array($data) ? (string) ($data['description'] ?? 'unknown reason') : 'malformed response'),
            );
        }

        return $data;
    }

    public static function assertToken(string $token): string
    {
        if ($token === '') {
            return '';
        }

        // A token's shape is <number>:<35 characters> — checked to catch a
        // value pasted into the wrong field at save time, better than
        // finding out when an important notification fails to send
        if (preg_match('/^\d{5,}:[A-Za-z0-9_-]{30,}$/', $token) !== 1) {
            throw new ValidationError("The bot token's format is invalid (must look like 123456789:AA...)");
        }

        return $token;
    }

    public static function assertChatId(string $chatId): string
    {
        if ($chatId === '') {
            return '';
        }

        // Accepts either a number (including a group's negative value) or a public channel's @username
        if (preg_match('/^(-?\d+|@[A-Za-z][A-Za-z0-9_]{4,})$/', $chatId) !== 1) {
            throw new ValidationError('The chat id must be a number or a channel @username');
        }

        return $chatId;
    }
}
