<?php

declare(strict_types=1);

namespace Phpcp\Driver;

use Phpcp\Agent\ExecutionFailed;
use Phpcp\Agent\Executor\Executor;
use Phpcp\Agent\ValidationError;
use Phpcp\Support\BinaryPath;

/**
 * The machine's hostname — readable and changeable from the web page
 *
 * ## Why this needs to exist
 *
 * A hostname isn't decoration · Postfix uses it as `myhostname` when
 * introducing itself to a destination mail server (a destination with
 * strict checks rejects mail from a machine whose name doesn't match its
 * rDNS), and it's also the name used to request a certificate for the
 * panel's own login page · this used to be changeable only through
 * `hostnamectl` at the console, which goes against the panel's own
 * principle that anything set at install time must remain editable from
 * the web page afterward.
 *
 * ## `/etc/hosts` must always be updated to match
 *
 * `hostnamectl set-hostname` only changes the name inside the kernel — **it
 * never touches `/etc/hosts`** — the result is that `hostname -f` has to
 * rely entirely on DNS; if DNS hasn't propagated yet or is down, that
 * command hangs until it times out and falls back to the short name, which
 * cascades into Postfix introducing itself with the wrong name and
 * `MailReadiness` reporting incorrectly · this very machine is an example:
 * `/etc/hosts` has only a single `localhost` line, so its FQDN comes entirely from DNS.
 *
 * The `127.0.1.1` line is Debian/Ubuntu's own convention for a hostname
 * that isn't tied to any one network card — the opposite of pointing at
 * `127.0.0.1`, which would overwrite `localhost`'s own entry.
 *
 * ## What this deliberately does **not** do
 *
 * Never fixes rDNS (has to be requested from the cloud provider), never
 * requests a new certificate, and never restarts Postfix — all three are
 * separate decisions an admin should make deliberately · a caller gets that
 * list back to pass along, instead of the system doing it silently and mail breaking with nobody knowing why.
 */
final class HostnameManager
{
    public const HOSTS_FILE = '/etc/hosts';

    /** @var list<string> hostnamectl lives at /usr/bin on Debian/Ubuntu · /bin on some other systems */
    private const HOSTNAMECTL_PATHS = ['/usr/bin/hostnamectl', '/bin/hostnamectl'];

    /**
     * The currently-set name
     *
     * @return array{hostname:string,short:string,fqdn_resolves:bool}
     */
    public function read(Executor $executor): array
    {
        $static = trim($executor->exec(
            [BinaryPath::resolve($executor, self::HOSTNAMECTL_PATHS, 'systemd'), '--static'],
            timeout: 10,
        )->output());

        $short = strstr($static, '.', true) ?: $static;

        return [
            'hostname' => $static,
            'short' => $short,
            // Is there a line in /etc/hosts? — an indicator that the full name resolves without relying on DNS
            'fqdn_resolves' => $static !== '' && $this->hostsHas($executor, $static),
        ];
    }

    /**
     * Sets a new name, keeping `/etc/hosts` in sync with it
     *
     * @return array{hostname:string,previous:string,hosts_updated:bool,follow_up:list<string>}
     */
    public function apply(Executor $executor, string $hostname): array
    {
        $hostname = self::assertHostname($hostname);
        $previous = $this->read($executor)['hostname'];

        if ($previous === $hostname) {
            return [
                'hostname' => $hostname,
                'previous' => $previous,
                'hosts_updated' => $this->syncHostsFile($executor, $hostname),
                'follow_up' => [],
            ];
        }

        $result = $executor->exec(
            [BinaryPath::resolve($executor, self::HOSTNAMECTL_PATHS, 'systemd'), 'set-hostname', $hostname],
            timeout: 20,
        );

        if (!$result->ok()) {
            throw new ExecutionFailed(
                'Failed to set the hostname: ' . (trim($result->stderr) ?: trim($result->output())),
            );
        }

        return [
            'hostname' => $hostname,
            'previous' => $previous,
            'hosts_updated' => $this->syncHostsFile($executor, $hostname),
            // Things the admin has to go do themselves — the system either can't do these, or shouldn't do them silently
            'follow_up' => [
                sprintf("Set the IP's rDNS to point back at %s at the cloud provider — a destination with strict checks will reject mail if it doesn't match", $hostname),
                'Request a new certificate for this name if it will be used for the panel or for mail',
                'Restart Postfix so it introduces itself with the new name (if mail is enabled)',
            ],
        ];
    }

    /**
     * Ensures `/etc/hosts` always has a line for the full name — overwrites the existing `127.0.1.1` line if there is one
     *
     * Only ever edits that one line, never touches the rest — this file
     * often has entries an admin added by hand (a machine on the LAN,
     * pointing a test domain) — writing the whole file would delete someone else's entries.
     *
     * @return bool whether anything genuinely changed
     */
    private function syncHostsFile(Executor $executor, string $hostname): bool
    {
        $path = $executor->path(self::HOSTS_FILE);

        if (!$executor->exists($path)) {
            return false;
        }

        $short = strstr($hostname, '.', true) ?: $hostname;
        $wanted = $hostname === $short
            ? "127.0.1.1\t{$hostname}"
            : "127.0.1.1\t{$hostname} {$short}";

        $lines = preg_split('/\R/', $executor->readFile($path)) ?: [];
        $out = [];
        $replaced = false;

        foreach ($lines as $line) {
            if (preg_match('/^\s*127\.0\.1\.1\s/', $line) === 1) {
                // This is our own line — replaced, not appended again into several conflicting lines
                if (!$replaced) {
                    $out[] = $wanted;
                    $replaced = true;
                }

                continue;
            }

            $out[] = $line;
        }

        if (!$replaced) {
            // Placed right after localhost, its conventional first line — appended at the end if that can't be found
            $at = null;
            foreach ($out as $i => $line) {
                if (preg_match('/^\s*127\.0\.0\.1\s/', $line) === 1) {
                    $at = $i + 1;

                    break;
                }
            }

            $at === null ? $out[] = $wanted : array_splice($out, $at, 0, [$wanted]);
        }

        $content = rtrim(implode("\n", $out), "\n") . "\n";

        if ($content === $executor->readFile($path)) {
            return false;
        }

        $executor->writeFile($path, $content, 0644);

        return true;
    }

    private function hostsHas(Executor $executor, string $hostname): bool
    {
        $path = $executor->path(self::HOSTS_FILE);

        if (!$executor->exists($path)) {
            return false;
        }

        foreach (preg_split('/\R/', $executor->readFile($path)) ?: [] as $line) {
            $line = trim(preg_replace('/#.*$/', '', $line) ?? '');

            if ($line === '') {
                continue;
            }

            $parts = preg_split('/\s+/', $line) ?: [];

            if (in_array($hostname, array_slice($parts, 1), true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * A hostname has to be clean enough to write into `/etc/hosts` and pass to `hostnamectl`
     *
     * Deliberately stricter than what the RFC actually permits: no leading
     * or trailing hyphen or dot, no consecutive dots, no label longer than
     * 63 characters, total length no more than 253 · this value gets
     * written into a system file and passed as a command argument, so it
     * has to be guarded at the source, not left hoping the destination validates it.
     */
    public static function assertHostname(string $hostname): string
    {
        $hostname = strtolower(trim($hostname));

        if ($hostname === '') {
            throw new ValidationError('A hostname must be specified');
        }

        if (strlen($hostname) > 253) {
            throw new ValidationError('Hostname exceeds 253 characters');
        }

        if (preg_match('/^(?!-)[a-z0-9-]{1,63}(?<!-)(\.(?!-)[a-z0-9-]{1,63}(?<!-))*$/', $hostname) !== 1) {
            throw new ValidationError(
                "Invalid hostname: {$hostname}\n\n"
                . 'Only a-z, 0-9, and hyphens are allowed, separated by dots · each part must be '
                . 'no more than 63 characters, and must not start or end with a hyphen',
            );
        }

        return $hostname;
    }
}
