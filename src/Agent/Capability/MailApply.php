<?php

declare(strict_types=1);

namespace Phpcp\Agent\Capability;

use Phpcp\Agent\Context;
use Phpcp\Agent\Executor\Executor;
use Phpcp\Agent\ValidationError;
use Phpcp\Domain\SettingsRepository;
use Phpcp\Driver\Mail\MailManager;

/**
 * Saves outbound mail settings and writes them into Postfix in one command — PLAN-MAIL Phase M3
 *
 * Kept separate from `settings.set` because saving a value to the database and
 * writing a service's config file are different risks — the latter has to go
 * through `ConfigTransaction` and `postfix check` before it takes effect ·
 * bundled into one command the same way as `webserver.apply`, because "saved but
 * not yet in effect" is a state where an admin has no way to tell which path
 * mail is actually going out through.
 *
 * **Writes `main.cf` only through `sync()`, never calls MailManager directly** —
 * this class only knows about outbound mail, it has no idea which domains this
 * machine has mail hosting enabled for · back when it called MailManager
 * directly, saving the sender address would overwrite `main.cf` with a version
 * that had no inbound-mail section at all, and Postfix would go back to
 * listening on loopback only — **every mailbox on the machine stopped receiving
 * mail**, with no error message at all, because everything that got written
 * "succeeded".
 */
final class MailApply extends MailCapability
{
    public static function name(): string
    {
        return 'mail.apply';
    }

    /**
     * Outbound mail settings belong to the whole machine, not to any one domain
     * — a site owner holding `mail.manage` can manage their own mailboxes, but
     * can't change the machine's outbound mail path
     */
    public function permission(): string
    {
        return 'settings.manage';
    }

    public function summary(): string
    {
        return 'Save outbound mail settings and write to Postfix';
    }

    public function validate(array $args): array
    {
        $out = ['values' => []];

        // Only the fields being changed are sent — a field not sent keeps its existing database value
        foreach (['mail.from', 'mail.relay_host', 'mail.relay_user', 'mail.relay_password', 'mail.hostname'] as $key) {
            if (array_key_exists($key, $args)) {
                $out['values'][$key] = trim((string) $args[$key]);
            }
        }

        if (array_key_exists('mail.mode', $args)) {
            $mode = trim((string) $args['mail.mode']);

            if (!in_array($mode, ['local', 'relay'], true)) {
                throw new ValidationError('Outbound mail mode must be local or relay');
            }

            $out['values']['mail.mode'] = $mode;
        }

        foreach (['mail.enabled', 'mail.relay_tls'] as $key) {
            if (array_key_exists($key, $args)) {
                $out['values'][$key] = in_array($args[$key], [true, 1, '1', 'on', 'true'], true) ? '1' : '0';
            }
        }

        if (array_key_exists('mail.relay_port', $args)) {
            $out['values']['mail.relay_port'] = (string) MailManager::assertPort((int) $args['mail.relay_port']);
        }

        if (($out['values']['mail.from'] ?? '') !== '') {
            MailManager::assertEmail($out['values']['mail.from']);
        }

        if (($out['values']['mail.relay_host'] ?? '') !== '') {
            MailManager::assertHost($out['values']['mail.relay_host']);
        }

        return $out;
    }

    public function run(array $args, Executor $executor, Context $context): array
    {
        $settings = new SettingsRepository($context->db);
        $values = $args['values'];

        /*
         * relay mode with no destination host means mail that goes nowhere at
         * all · Postfix would queue it and quietly keep trying to send it
         * locally instead, the opposite of what was chosen
         */
        $mode = $values['mail.mode'] ?? $settings->get('mail.mode');
        $host = $values['mail.relay_host'] ?? $settings->get('mail.relay_host');

        if ($mode === 'relay' && $host === '') {
            throw new ValidationError(
                'Choosing to send through a relay requires specifying the provider\'s host too '
                . '(smtp.sendgrid.net, for instance), or mail will sit in the queue with nothing to say why',
            );
        }

        // Asterisks = the user never touched the password field, keep the existing value, don't overwrite it with asterisks
        if (($values['mail.relay_password'] ?? '') === '********') {
            unset($values['mail.relay_password']);
        }

        if ($values !== []) {
            $settings->save($values);
        }

        // Rewrites main.cf/master.cf entirely from the machine-wide picture,
        // including the inbound section for domains with mail enabled — see the
        // reasoning at the top of this class
        $counts = $this->sync($executor, $context);

        $relay = $settings->get('mail.relay_host');
        $applied = $settings->get('mail.mode');

        return [
            'mode' => $applied,
            'relay' => $relay,
            'domains' => $counts['domains'],
            'message' => $applied === 'relay'
                ? sprintf('Configured to send mail through %s', $relay)
                : 'Configured to send mail directly from this machine',
        ];
    }
}
