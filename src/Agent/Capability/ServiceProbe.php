<?php

declare (strict_types = 1);

namespace Phpcp\Agent\Capability;

use Phpcp\Agent\Executor\Executor;
use Phpcp\Domain\ServiceCatalog;

/**
 * Reads one service's status from systemd — shared between several capabilities
 *
 * Not a Capability itself, but a helper, so both service.status and the
 * commands that change state (start/stop/restart/reload) return the same data
 * shape — letting the UI update its card immediately after a click, with no
 * need to call again.
 */
final class ServiceProbe
{
    public const PROPERTIES = [
        'Description',
        'LoadState',
        'ActiveState',
        'SubState',
        'UnitFileState',
        'MainPID',
        'MemoryCurrent',
        'ActiveEnterTimestamp'
    ];

    /** @return array<string,mixed> */
    public static function read(Executor $executor, string $unit): array
    {
        $keyValues = self::show($executor, $unit);

        // When systemd isn't running as PID 1 (inside a Docker container, or an
        // environment with no systemd, say), systemctl show fails — falls back
        // to a service / sysvinit / process check instead
        if (empty($keyValues['LoadState']) || $keyValues['LoadState'] === 'not-found') {
            $fallback = self::probeFallback($executor, $unit);
            if ($fallback !== null) {
                return $fallback;
            }
        }

        $status = self::parse($unit, $keyValues);

        return $status['running'] === true ? $status : self::withSocketActivation($executor, $unit, $status);
    }

    /**
     * A service woken by a socket still counts as "available", even while its
     * `.service` reads inactive
     *
     * **Found on a real server (Lightsail + Ubuntu 24.04):** as of Ubuntu
     * 22.10, OpenSSH switched to socket activation — `ssh.socket` listens on
     * port 22 and wakes up `ssh@<n>.service` for each connection · the result
     * is that `ssh.service` shows `ActiveState=inactive` **all the time**, even
     * while SSH works completely normally (the very SSH session an admin used
     * to install the panel goes through that exact path).
     *
     * Without knowing this, the system would report "SSH is down", and
     * {@see \Phpcp\Driver\Ssh\SftpAccessManager::assertSshdRunning()} would
     * refuse to enable SFTP for a reason that isn't true — an admin clicking
     * the button would see "the SSH service isn't running" on the very machine
     * they're ssh'd into at that exact moment.
     *
     * `activation` tells the caller this availability comes from the socket,
     * not the service itself — whoever is about to run `reload` needs to know,
     * since reloading an inactive service can't be done.
     *
     * @param  array<string,mixed> $status
     * @return array<string,mixed>
     */
    private static function withSocketActivation(Executor $executor, string $unit, array $status): array
    {
        $socket = self::show($executor, $unit.'.socket');

        if (($socket['LoadState'] ?? 'not-found') === 'not-found'
            || ($socket['ActiveState'] ?? '') !== 'active') {
            return $status;
        }

        return [
            ...$status,
            'installed' => true,
            'active' => 'active',
            'sub' => $socket['SubState'] ?? 'listening',
            'running' => true,
            'status' => 'running',
            // A service like this always has its `.service` set to `static` —
            // the genuine "starts on boot?" state lives on `.socket`, not `.service`
            'enabled' => $socket['UnitFileState'] ?? $status['enabled'],
            'activation' => 'socket',
            'socket_unit' => $unit.'.socket',
        ];
    }

    /**
     * @return array<string,string>
     */
    private static function show(Executor $executor, string $unit): array
    {
        $argv = [$executor->path('/usr/bin/systemctl'), 'show', $unit, '--no-pager'];
        foreach (self::PROPERTIES as $property) {
            $argv[] = '--property='.$property;
        }

        return $executor->exec($argv, timeout: 10)->keyValues();
    }

    /**
     * A fallback for environments with no systemd (a Docker container, say)
     * @return array<string,mixed>|null
     */
    private static function probeFallback(Executor $executor, string $unit): ?array
    {
        $initScript = '/etc/init.d/'.$unit;
        $unitFiles = [
            '/lib/systemd/system/'.$unit.'.service',
            '/usr/lib/systemd/system/'.$unit.'.service',
            '/etc/systemd/system/'.$unit.'.service',
        ];

        $exists = file_exists($initScript);
        foreach ($unitFiles as $unitFile) {
            $exists = $exists || file_exists($unitFile);
        }

        // Tries running service <unit> status
        $serviceBin = file_exists('/usr/sbin/service') ? '/usr/sbin/service' : '/usr/bin/service';
        $statusRes = $executor->exec([$executor->path($serviceBin), $unit, 'status'], timeout: 5);

        $output = strtolower($statusRes->stdout.' '.$statusRes->stderr);

        // The phrase each system uses to say "this unit doesn't exist" — differs by which init system is installed
        //
        // `could not be found` is what newer systemd genuinely uses, and was
        // once **missed in the original survey**: the original list only had
        // `unrecognized service` and `not-found`, matching neither, which made
        // a PHP-FPM version that was never installed get reported as "installed
        // but stopped", firing an alert about a service that never existed at all
        $notFoundPhrases = [
            'unrecognized service',
            'not-found',
            'could not be found',
            'no such file or directory',
            'not be found',
        ];

        $isUnrecognized = false;
        foreach ($notFoundPhrases as $phrase) {
            $isUnrecognized = $isUnrecognized || str_contains($output, $phrase);
        }

        if (!$exists && $isUnrecognized) {
            return null; // Treated as genuinely not installed
        }

        // No unit file, and the command failed too — the state can't be
        // guessed, so it must not be guessed
        //
        // Returning `installed => true` in this case is more dangerous than
        // admitting it's unknown, because a caller (`alert.check`, say) would
        // see "installed but not running" and wake someone up in the middle of
        // the night about a service that never existed on this machine at all
        if (!$exists && $statusRes->exitCode !== 0) {
            return null;
        }

        $isRunning = $statusRes->exitCode === 0 || str_contains($output, 'is running') || str_contains($output, 'running');
        $activeState = $isRunning ? 'active' : 'inactive';

        return [
            'unit' => $unit,
            'label' => ServiceCatalog::label($unit),
            'kind' => ServiceCatalog::kind($unit),
            'critical' => ServiceCatalog::isCritical($unit),
            'installed' => true,
            'description' => ServiceCatalog::label($unit),
            'active' => $activeState,
            'sub' => $isRunning ? 'running' : 'dead',
            'enabled' => 'enabled',
            'running' => $isRunning,
            'pid' => 0,
            'memory_bytes' => 0,
            'since' => null,
            'status' => self::statusOf(true, $activeState)
        ];
    }

    /**
     * @param array<string,string> $values
     * @return array<string,mixed>
     */
    public static function parse(string $unit, array $values): array
    {
        $activeState = $values['ActiveState'] ?? 'inactive';
        $loadState = $values['LoadState'] ?? 'not-found';
        $installed = $loadState !== 'not-found';

        $since = null;
        $timestamp = trim($values['ActiveEnterTimestamp'] ?? '');
        if ($timestamp !== '') {
            $parsed = strtotime($timestamp);
            $since = $parsed === false ? null : $parsed;
        }

        // MemoryCurrent reads "[not set]" while the service isn't running
        $memoryRaw = $values['MemoryCurrent'] ?? '0';

        return [
            'unit' => $unit,
            'label' => ServiceCatalog::label($unit),
            'kind' => ServiceCatalog::kind($unit),
            'critical' => ServiceCatalog::isCritical($unit),
            'installed' => $installed,
            'description' => $values['Description'] ?? ServiceCatalog::label($unit),
            'active' => $activeState,
            'sub' => $values['SubState'] ?? 'dead',
            'enabled' => $values['UnitFileState'] ?? 'disabled',
            'running' => $activeState === 'active',
            'pid' => (int) ($values['MainPID'] ?? 0),
            'memory_bytes' => ctype_digit($memoryRaw) ? (int) $memoryRaw : 0,
            'since' => $since,
            'status' => self::statusOf($installed, $activeState)
        ];
    }

    /**
     * Converts systemd's state into the status the UI displays, per PROMPT.md
     * (running / stopped / failed / needs attention)
     */
    public static function statusOf(bool $installed, string $activeState): string
    {
        if (!$installed) {
            return 'not_installed';
        }

        return match ($activeState) {
            'active', 'reloading' => 'running',
            'failed' => 'failed',
            'activating', 'deactivating' => 'transitioning',
            default => 'stopped',
        };
    }
}
