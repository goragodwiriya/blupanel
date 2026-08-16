<?php

declare(strict_types=1);

namespace Phpcp\Domain;

use Phpcp\Agent\Executor\Executor;
use Phpcp\Driver\Notify\EmailNotifier;
use Phpcp\Driver\Notify\TelegramNotifier;
use Phpcp\Driver\Notify\WebhookNotifier;
use Phpcp\Kernel\Db;

/**
 * Decides what's worth notifying, then sends it to every channel that's enabled
 *
 * Kept separate from TelegramNotifier because the two answer different questions:
 * that one answers "how to send a message," this one answers "should this be sent
 * at all." Merging them would mean adding a second channel (e.g. Discord) has to
 * duplicate the entire filtering logic.
 *
 * The principle for what deserves a notification: **only notify what needs action**.
 * Notifying about everything that happens gets people to turn notifications off
 * within a week, and then when something real happens, nobody sees it — which is
 * worse than having no notification system at all, since the admin believes they're
 * being watched over when they aren't.
 */
final class Notifier
{
    /**
     * Event categories, with the key used to turn each on/off
     *
     * @var array<string,string>
     */
    public const EVENTS = [
        'security' => 'notify.events.security',
        'ssl' => 'notify.events.ssl',
        'service' => 'notify.events.service',
        'backup' => 'notify.events.backup',
        'login' => 'notify.events.login',
        'quota' => 'notify.events.quota',
        'alert' => 'notify.events.alert',
    ];

    public const LABELS = [
        'security' => 'Security — a risk was found that needs fixing',
        'ssl' => 'SSL certificate — nearing expiry or failed to renew',
        'service' => 'A critical service has stopped',
        'backup' => 'Backup and restore results',
        'login' => 'Unusual login failures',
        'quota' => "A customer account's disk quota is nearing full",
        'alert' => 'Machine alert thresholds — disk, RAM, load, services, and certificates',
    ];

    private SettingsRepository $settings;

    /**
     * @param Executor|null $executor only needed for the email channel — calling
     *        `sendmail` must always go through `Executor` (ARCHITECTURE §4.4),
     *        unlike Telegram/webhook, which can fire an HTTPS request directly ·
     *        a caller from the web tier with no executor can therefore only send
     *        through those two channels
     */
    public function __construct(
        private readonly Db $db,
        private readonly ?Executor $executor = null,
    ) {
        $this->settings = new SettingsRepository($db);
    }

    /**
     * Send a notification to every enabled channel, if this category is turned on
     *
     * Always returns bool instead of throwing, because the caller is the actual
     * work that has already succeeded — a notification failing must never bounce
     * back and make that work look like it failed too.
     *
     * **Sends to every enabled channel, not just the first one that succeeds** — an
     * admin who's set up both Telegram and email deliberately wants both (their
     * phone, and the team's inbox) · returns true if at least one channel sent
     * successfully — a failed channel doesn't count a successful one as failed too.
     */
    public function send(string $event, string $title, string $body, string $level = 'info'): bool
    {
        if (!isset(self::EVENTS[$event])) {
            return false;
        }

        try {
            if (!$this->settings->bool(self::EVENTS[$event])) {
                return false;
            }

            $sent = false;

            if ($this->settings->bool('notify.telegram.enabled')) {
                $sent = (new TelegramNotifier(
                    $this->settings->get('notify.telegram.token'),
                    $this->settings->get('notify.telegram.chat_id'),
                ))->notify($title, $body, $level) || $sent;
            }

            if ($this->settings->bool('notify.webhook.enabled')) {
                $sent = (new WebhookNotifier(
                    $this->settings->get('notify.webhook.url'),
                    $this->settings->get('notify.webhook.secret'),
                ))->notify($event, $title, $body, $level) || $sent;
            }

            // Email can only be sent when the caller provided an executor — not a failure if there isn't one
            if ($this->executor !== null && $this->settings->bool('notify.email.enabled')) {
                $sent = (new EmailNotifier(
                    $this->executor,
                    $this->settings->get('notify.email.to'),
                    $this->settings->get('mail.from'),
                ))->notify($title, $body, $level) || $sent;
            }

            return $sent;
        } catch (\Throwable) {
            // Including the case where the database is locked — a notification that can't be sent must fail silently
            return false;
        }
    }

    /** Whether at least one channel is actually usable */
    public function isActive(): bool
    {
        return $this->activeChannels() !== [];
    }

    /**
     * Channels that are both fully configured and actually enabled
     *
     * "Switch turned on but the token was never filled in" must not count as
     * usable — otherwise the screen would report that notifications are ready when
     * nothing can actually be sent out at all, which is more dangerous than having
     * no notification system.
     *
     * @return list<string>
     */
    public function activeChannels(): array
    {
        try {
            $channels = [];

            if ($this->settings->bool('notify.telegram.enabled')
                && $this->settings->get('notify.telegram.token') !== ''
                && $this->settings->get('notify.telegram.chat_id') !== '') {
                $channels[] = 'telegram';
            }

            if ($this->settings->bool('notify.webhook.enabled')
                && $this->settings->get('notify.webhook.url') !== '') {
                $channels[] = 'webhook';
            }

            if ($this->settings->bool('notify.email.enabled')
                && $this->settings->get('notify.email.to') !== ''
                && $this->settings->get('mail.from') !== '') {
                $channels[] = 'email';
            }

            return $channels;
        } catch (\Throwable) {
            return [];
        }
    }
}
