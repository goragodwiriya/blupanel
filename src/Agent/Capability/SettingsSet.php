<?php

declare(strict_types=1);

namespace Phpcp\Agent\Capability;

use Phpcp\Agent\Capability;
use Phpcp\Agent\Context;
use Phpcp\Agent\Executor\Executor;
use Phpcp\Domain\Notifier;
use Phpcp\Domain\SettingsRepository;
use Phpcp\Driver\Mail\MailManager;
use Phpcp\Driver\Notify\TelegramNotifier;
use Phpcp\Driver\Notify\WebhookNotifier;
use Phpcp\Support\Validator;

/**
 * Saves settings
 *
 * A secret value (a token, a password) sent back as the masked placeholder
 * (********) means the user never touched that field — the existing value must
 * be kept, never overwritten with the asterisks themselves — overwriting it
 * would destroy the token every single time the save button is clicked to
 * change just one unrelated value.
 */
final class SettingsSet implements Capability
{
    public static function name(): string
    {
        return 'settings.set';
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
        return 'Save system settings';
    }

    public function validate(array $args): array
    {
        $out = [];

        foreach (SettingsRepository::keys() as $key => $type) {
            if (!array_key_exists($key, $args)) {
                continue;
            }

            $value = (string) $args[$key];

            $out[$key] = match ($type) {
                'bool' => $value === '1' || $value === 'on' || $value === 'true' ? '1' : '0',
                'int' => (string) (int) $value,
                default => Validator::pattern(
                    trim($value),
                    // Guards against control characters and newlines — these values get written into config files
                    '/^[^\x00-\x1F\x7F]{0,255}$/u',
                    "The value for {$key} contains an unusable character",
                ),
            };
        }

        // Format-specific checks run after the basic values are cleaned
        if (isset($out['notify.telegram.token']) && $out['notify.telegram.token'] !== '********') {
            TelegramNotifier::assertToken($out['notify.telegram.token']);
        }

        if (isset($out['notify.telegram.chat_id'])) {
            TelegramNotifier::assertChatId($out['notify.telegram.chat_id']);
        }

        if (($out['mail.from'] ?? '') !== '') {
            MailManager::assertEmail($out['mail.from']);
        }

        if (($out['notify.email.to'] ?? '') !== '') {
            MailManager::assertEmail($out['notify.email.to']);
        }

        // https and the URL format are enforced right at save time — catches
        // the mistake while it's being typed, not the moment a critical
        // notification fails to send exactly when it was needed most
        if (isset($out['notify.webhook.url'])) {
            WebhookNotifier::assertUrl($out['notify.webhook.url']);
        }

        if (($out['mail.relay_host'] ?? '') !== '') {
            MailManager::assertHost($out['mail.relay_host']);
        }

        if (isset($out['mail.relay_port'])) {
            MailManager::assertPort((int) $out['mail.relay_port']);
        }

        return ['values' => $out];
    }

    public function run(array $args, Executor $executor, Context $context): array
    {
        $settings = new SettingsRepository($context->db);
        $values = $args['values'];

        foreach ($values as $key => $value) {
            // A value still holding asterisks = the user never touched that field, keep the existing value
            if (SettingsRepository::isSecret($key) && $value === '********') {
                unset($values[$key]);
            }
        }

        $settings->save($values);

        $message = sprintf('Saved %d setting(s)', count($values));
        $dns = [];

        // Turning the DNS switch on has to "actually turn it on", not just remember the value — see activateDns()
        if (($values['dns.enabled'] ?? '') === '1') {
            $dns = $this->activateDns($executor, $settings, $context->config->dnsZoneDir());
            $message .= ' · ' . $dns['message'];
        }

        return [
            'saved' => count($values),
            'notify_active' => (new Notifier($context->db, $executor))->isActive(),
            'notify_channels' => (new Notifier($context->db, $executor))->activeChannels(),
            'dns' => $dns,
            'message' => $message,
        ];
    }

    /**
     * Makes BIND9 genuinely usable after an admin turns on the `dns.enabled` switch
     *
     * **This switch used to just save a value to the database** — the result on
     * a machine installed without `--dns-ns` (install.sh's default) was that the
     * bind9 package was already installed, but the service had never been
     * enabled/started, and `/etc/bind/zones` was never created · an admin would
     * click the switch on, the screen would say "saved", and then adding a real
     * record would fail at `rndc reload` with a message that never pointed at
     * needing to start the service first.
     *
     * So this does everything `install.sh --dns-ns` does at install time, all in
     * one request — this is what "can be fully configured from the web page"
     * actually means, not just recording an intention.
     *
     * Never throws on failure, because the value has already been saved and can
     * still be fixed from the Services page — instead it reports back what
     * genuinely happened, so the admin knows what's left to do.
     *
     * @return array{ready:bool,unit:string,message:string}
     */
    private function activateDns(Executor $executor, SettingsRepository $settings, string $zoneDir): array
    {
        $nameservers = trim($settings->get('dns.nameservers'));

        if ($nameservers === '') {
            return [
                'ready' => false,
                'unit' => '',
                'message' => 'Zones cannot be created yet until nameserver names are filled in — BIND9 rejects a zone with no NS record',
            ];
        }

        // The zone directory has to exist before BindZoneManager can write files into it
        $executor->makeDirectory($executor->path(rtrim($zoneDir, '/')), 0755);

        // Unit name differs by distro/version: newer Debian uses `named`, older uses `bind9`
        foreach (['named', 'bind9'] as $unit) {
            if (!(ServiceProbe::read($executor, $unit)['installed'] ?? false)) {
                continue;
            }

            $result = $executor->exec(
                [$executor->path('/usr/bin/systemctl'), 'enable', '--now', $unit],
                timeout: 30,
            );

            $running = ServiceProbe::read($executor, $unit)['running'] ?? false;

            if ($result->ok() || $running) {
                return [
                    'ready' => true,
                    'unit' => $unit,
                    'message' => sprintf('Enabled service %s and set it to start on boot', $unit),
                ];
            }

            return [
                'ready' => false,
                'unit' => $unit,
                'message' => sprintf(
                    'Failed to enable service %s: %s — start it manually from the Services page',
                    $unit,
                    trim($result->stderr) !== '' ? trim($result->stderr) : 'Unknown cause',
                ),
            ];
        }

        return [
            'ready' => false,
            'unit' => '',
            'message' => 'BIND9 is not installed on this machine — install it with `sudo apt install bind9`, then turn this switch on again',
        ];
    }
}
