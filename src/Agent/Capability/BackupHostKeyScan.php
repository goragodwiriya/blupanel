<?php

declare(strict_types=1);

namespace Phpcp\Agent\Capability;

use Phpcp\Agent\Capability;
use Phpcp\Agent\Context;
use Phpcp\Agent\ExecutionFailed;
use Phpcp\Agent\Executor\Executor;
use Phpcp\Agent\PermissionDenied;
use Phpcp\Agent\ValidationError;
use Phpcp\Security\Permissions;
use Phpcp\Support\Validator;

/**
 * Fetches the target machine's host key for it — `ssh-keyscan` clicked from
 * the web page
 *
 * ## Why this is worth having
 *
 * `StrictHostKeyChecking=yes` is always on, so setting up an sftp/rsync
 * destination fails the very first time, every time, until a host key
 * exists · the old way was for an admin to find a machine with
 * `ssh-keyscan`, run it there, then copy the result back in by hand — which
 * fails in plenty of places: mistyping the port · copying an incomplete
 * line · the machine that ran it seeing a different IP than the panel sees
 * (NAT/split-horizon DNS), ending up with the wrong machine's key without
 * realizing it.
 *
 * This button runs from **the same machine that will actually push backup
 * files**, so the result is the key of the machine it will genuinely talk
 * to, not whichever machine the admin happens to be sitting at.
 *
 * ## What this button **does not** do
 *
 * **It does not confirm the key is genuine** — `ssh-keyscan` trusts whatever
 * the other end answers with, exactly like trust-on-first-use · if someone
 * is already intercepting the connection before the button is even clicked,
 * the key returned is the interceptor's.
 *
 * What it actually fixes is **typos and grabbing the wrong machine**, not a
 * man-in-the-middle · an admin who wants full confidence still has to
 * compare the fingerprint against one read from the target machine's own
 * console — which is why the response attaches a fingerprint to compare,
 * instead of leaving that lookup to the admin.
 */
final class BackupHostKeyScan implements Capability
{
    private const KEYSCAN = '/usr/bin/ssh-keyscan';
    private const KEYGEN = '/usr/bin/ssh-keygen';

    public static function name(): string
    {
        return 'backup.host_key_scan';
    }

    public function permission(): string
    {
        return 'backup.offsite';
    }

    /**
     * Read-only — changes nothing on this machine or the target
     *
     * A side benefit of that: it's still backed by a real `Executor` even in
     * dryrun mode, so keys can still be read while an admin is in the middle
     * of trying out a configuration — exactly when this is needed most.
     */
    public function isMutating(): bool
    {
        return false;
    }

    public function summary(): string
    {
        return "Read the target machine's host key";
    }

    public function validate(array $args): array
    {
        $host = trim(Validator::requireString($args, 'host', 255));

        /*
         * This value becomes a command argument — it must be a hostname or
         * IP only.
         *
         * `Executor::exec()` already takes argv as an array, so there's no
         * shell to interpret it, but `ssh-keyscan` itself accepts options
         * starting with `-` · a value that starts that way would become an
         * option instead of a hostname.
         */
        $hostname = '[A-Za-z0-9](?:[A-Za-z0-9.\-:]*[A-Za-z0-9])?';   // domain name · IPv4 · bare IPv6
        $bracketed = '\[[0-9A-Fa-f:]+\]';                            // bracketed IPv6

        if (preg_match('/^(?:' . $hostname . '|' . $bracketed . ')$/', $host) !== 1) {
            throw new ValidationError('The target machine must be a hostname or IP address only');
        }

        $port = (int) ($args['port'] ?? 22);

        if ($port < 1 || $port > 65535) {
            throw new ValidationError('Port must be between 1 and 65535');
        }

        return ['host' => $host, 'port' => $port];
    }

    public function run(array $args, Executor $executor, Context $context): array
    {
        if (!in_array($context->actor->role, [Permissions::SUPERADMIN, Permissions::SYSADMIN], true)) {
            throw new PermissionDenied('Reading a host key requires server admin permission');
        }

        $result = $executor->exec([
            self::KEYSCAN,
            '-T', '10',
            '-p', (string) $args['port'],
            $args['host'],
        ], timeout: 30);

        // ssh-keyscan normally writes status lines to stderr, so the outcome is judged from stdout alone
        $keys = trim($result->stdout);

        if ($keys === '') {
            throw new ExecutionFailed(
                'Failed to read the host key of ' . $args['host'] . ':' . $args['port']
                . ' — check that the target machine is up, the port is correct, and its firewall allows this machine to connect'
                . ($result->stderr !== '' ? "\n\n" . trim($result->stderr) : ''),
            );
        }

        return [
            'host' => $args['host'],
            'port' => $args['port'],
            'known_hosts' => $keys,
            'lines' => count(array_filter(explode("\n", $keys), static fn (string $l): bool => trim($l) !== '')),
            // Lets it be compared against what's read from the target machine's own console — this button can't confirm identity on its own
            'fingerprints' => $this->fingerprints($executor, $keys),
            'message' => sprintf('Read the host key of %s:%d', $args['host'], $args['port']),
        ];
    }

    /**
     * The fingerprint of the key just read — the only part that can be
     * compared against the target machine by eye
     *
     * A failure here doesn't fail the whole command · the key is still
     * usable, it just can't be visually compared.
     *
     * @return list<string>
     */
    private function fingerprints(Executor $executor, string $keys): array
    {
        $file = sys_get_temp_dir() . '/phpcp-scan-' . bin2hex(random_bytes(6));

        try {
            $executor->writeFile($executor->path($file), $keys . "\n", 0600);

            $result = $executor->exec([self::KEYGEN, '-l', '-f', $executor->path($file)], timeout: 15);

            if (!$result->ok()) {
                return [];
            }

            return array_values(array_filter(
                array_map(trim(...), explode("\n", $result->stdout)),
                static fn (string $line): bool => $line !== '',
            ));
        } catch (\Throwable) {
            return [];
        } finally {
            if ($executor->exists($executor->path($file))) {
                $executor->removePath($executor->path($file));
            }
        }
    }
}
