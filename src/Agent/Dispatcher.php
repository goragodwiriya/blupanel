<?php

declare(strict_types=1);

namespace Phpcp\Agent;

use Phpcp\Agent\Executor\Executor;
use Phpcp\Agent\Executor\ExecutorFactory;
use Phpcp\Domain\Notifier;
use Phpcp\Kernel\Config;
use Phpcp\Kernel\Db;
use Phpcp\Security\AuditLog;

/**
 * The single path every command walks through — ARCHITECTURE §4.1
 *
 * A fixed order that must never be reshuffled:
 *   resolve → check permission → validate → write the "about to run" audit entry → run → record the result
 *
 * The audit entry has to be written before the work starts, because a command
 * that can bring the machine down mid-flight (restarting itself, editing the
 * firewall until it locks out) must still leave a trace of who gave the order.
 */
final class Dispatcher
{
    private readonly ExecutorFactory $executors;

    public function __construct(
        private readonly CapabilityRegistry $registry,
        private readonly Config $config,
        private readonly Db $db,
        private readonly AuditLog $audit,
    ) {
        $this->executors = new ExecutorFactory($config);
    }

    /**
     * @param array<string,mixed> $args
     * @return array<string,mixed>
     */
    public function dispatch(string $name, array $args, Actor $actor): array
    {
        $startedAt = hrtime(true);
        $capability = $this->registry->resolve($name);

        if (!$actor->can($capability->permission())) {
            $this->audit->write($actor, $name, self::targetOf($args), 'denied', [
                'reason' => 'missing permission ' . $capability->permission(),
            ]);

            throw new PermissionDenied('You do not have permission to use this command');
        }

        // validate() throws ValidationError/ProtectedResource out to handle() above to record
        $clean = $capability->validate($args);

        $auditId = null;
        if ($capability->isMutating()) {
            $auditId = $this->audit->write($actor, $name, self::targetOf($clean), 'ok', [
                'phase' => 'started',
                'args' => self::redact($clean),
                'mode' => $this->config->mode->value,
            ]);
        }

        $executor = $this->executors->for($capability);
        $context = new Context($actor, $this->config, $this->db);

        try {
            $data = $capability->run($clean, $executor, $context);
        } catch (\Throwable $e) {
            if ($capability->isMutating()) {
                $this->audit->write($actor, $name, self::targetOf($clean), 'error', [
                    'phase' => 'failed',
                    'audit_ref' => $auditId,
                    'error' => $e->getMessage(),
                ]);

                $this->notify($name, $actor, self::targetOf($clean), false, $e->getMessage(), $executor);
            }
            throw $e;
        }

        if ($capability->isMutating()) {
            $this->audit->write($actor, $name, self::targetOf($clean), 'ok', [
                'phase' => 'succeeded',
                'audit_ref' => $auditId,
                'simulated' => $executor->isSimulated(),
            ]);

            $this->notify($name, $actor, self::targetOf($clean), true, (string) ($data['message'] ?? ''), $executor);
        }

        $meta = [
            'mode' => $this->config->mode->value,
            'simulated' => $executor->isSimulated(),
            'duration_ms' => (int) ((hrtime(true) - $startedAt) / 1_000_000),
        ];

        // dryrun mode has to tell the user "what would run", not just that nothing happened
        $commands = $executor->simulatedCommands();
        if ($commands !== []) {
            $meta['commands'] = $commands;
        }

        return ['data' => $data, 'meta' => $meta];
    }

    /**
     * Commands worth notifying about, along with the category the user can toggle on/off
     *
     * A curated list, not "every command that changes the system" — creating a
     * website or editing a file happens dozens of times a day; notifying on all of
     * it would get an admin to turn notifications off within a week, and then the
     * day the firewall actually gets disabled, nobody would see it.
     *
     * **`service.start` is on the list because a notification that never closes
     * the loop leaves a lingering worry** — an admin who gets "service stopped
     * successfully" at 2am and no message when it comes back up has to log in and
     * check for themselves whether anyone brought it back. Starting a service
     * doesn't happen often enough to turn into spam, unlike creating a site or
     * editing a file.
     *
     * @var array<string,string>
     */
    private const NOTIFY = [
        'firewall.disable' => 'security',
        'firewall.enable' => 'security',
        'ssh.config_set' => 'security',
        'rollback.run' => 'security',
        'service.stop' => 'service',
        'service.start' => 'service',
        'service.restart' => 'service',
        'ssl.issue' => 'ssl',
        'ssl.renew' => 'ssl',
        'ssl.delete' => 'ssl',
        'backup.create' => 'backup',
        'backup.restore' => 'backup',
        'site.delete' => 'security',
    ];

    /**
     * Sends a notification if this command is on the curated list
     *
     * Must never throw an exception — by the time execution reaches this point,
     * the main work has already succeeded (or failed). If a failed notification
     * send took the whole command down with it, the user would see a backup as
     * "failed" even though the backup file had already been created just fine.
     */
    private function notify(
        string $name,
        Actor $actor,
        string $target,
        bool $ok,
        string $detail,
        ?Executor $executor = null,
    ): void {
        $event = self::NOTIFY[$name] ?? null;

        if ($event === null) {
            return;
        }

        try {
            $capability = $this->registry->resolve($name);

            // Always pass the executor along — the **email** channel has to call
            // `sendmail`, which only ever goes through the Executor (ARCHITECTURE
            // §4.4) · without it, `Notifier` silently skips email, and an admin
            // with every email setting correctly configured gets nothing at all,
            // with nothing to complain about it — this actually happened, until it
            // was caught testing on the real machine.
            (new Notifier($this->db, $executor))->send(
                $event,
                ($ok ? 'Succeeded: ' : 'Failed: ') . $capability->summary(),
                sprintf(
                    "Target: %s\nActor: %s (%s)\nMachine: %s\n\n%s",
                    $target !== '' ? $target : '—',
                    $actor->username,
                    $actor->ip,
                    gethostname() ?: 'unknown',
                    mb_substr(trim($detail), 0, 500),
                ),
                $ok ? 'info' : 'danger',
            );
        } catch (\Throwable) {
            // A notification that fails to send must stay silent, not turn an already-successful command into a failure
        }
    }

    /**
     * Argument names that must never be logged raw — matched as "this word appears
     * in the name", not an exact match
     *
     * Covers `password`, `notify.telegram.token`, `smtp_secret`, and names that
     * don't exist yet today. Kept deliberately broad: logging *** more than
     * strictly necessary hurts nobody, but a password that leaks into the audit
     * log can never be removed, because that would break the hash chain.
     */
    private const SECRET_HINTS = ['password', 'passwd', 'secret', 'token', 'passphrase', 'private_key', 'credential'];

    /** Values longer than this only have their size recorded — a whole file's contents shouldn't live in the audit log */
    private const MAX_AUDIT_VALUE = 200;

    /**
     * Strips out anything that shouldn't be in the audit log before it's written
     *
     * Why this matters: `audit_log` is a hash chain that's deliberately impossible
     * to edit out of, and it's mirrored to a file every admin can read. Left to
     * write raw args, creating one customer would permanently embed that
     * customer's password, and one `file.write` call would copy the entire
     * contents of a file (including a .env file holding database credentials)
     * right along with it.
     *
     * @param array<string,mixed> $args
     * @return array<string,mixed>
     */
    private static function redact(array $args): array
    {
        $clean = [];

        foreach ($args as $key => $value) {
            $name = strtolower((string) $key);

            foreach (self::SECRET_HINTS as $hint) {
                if (str_contains($name, $hint)) {
                    $clean[$key] = '***';

                    continue 2;
                }
            }

            if (is_array($value)) {
                $clean[$key] = self::redact($value);

                continue;
            }

            if (is_string($value) && strlen($value) > self::MAX_AUDIT_VALUE) {
                $clean[$key] = sprintf('(truncated, %s bytes)', number_format(strlen($value)));

                continue;
            }

            $clean[$key] = $value;
        }

        return $clean;
    }

    /** Guesses a target from args for the audit record — lets you search back later for who did what to what */
    private static function targetOf(array $args): string
    {
        foreach (['service', 'domain', 'site_id', 'db_name', 'username', 'path', 'unit'] as $key) {
            if (isset($args[$key]) && is_scalar($args[$key])) {
                return substr((string) $args[$key], 0, 190);
            }
        }

        return '';
    }
}
