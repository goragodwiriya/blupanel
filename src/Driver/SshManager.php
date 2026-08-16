<?php

declare(strict_types=1);

namespace Phpcp\Driver;

use Phpcp\Agent\Executor\Executor;
use Phpcp\Agent\ValidationError;

/**
 * Reads and edits SSH configuration — PROMPT.md
 *
 * Only the 5 keys listed here can be edited, and only to an enum value or a
 * port number — never the whole file, since a broken sshd_config locks
 * everyone out of the machine for good.
 *
 * Every edit has to go through RollbackGuard — this is a value that, once
 * changed, can instantly cut off the connection of the very person editing
 * it (ARCHITECTURE §5.4).
 */
final class SshManager
{
    public const CONFIG = '/etc/ssh/sshd_config';

    /** The editable keys, with their accepted values */
    private const EDITABLE = [
        'Port' => 'port',
        'PermitRootLogin' => ['yes', 'no', 'prohibit-password', 'forced-commands-only'],
        'PasswordAuthentication' => ['yes', 'no'],
        'PubkeyAuthentication' => ['yes', 'no'],
        'PermitEmptyPasswords' => ['yes', 'no'],
    ];

    /** OpenSSH's own defaults when a key isn't specified in the file */
    private const DEFAULTS = [
        'Port' => '22',
        'PermitRootLogin' => 'prohibit-password',
        'PasswordAuthentication' => 'yes',
        'PubkeyAuthentication' => 'yes',
        'PermitEmptyPasswords' => 'no',
    ];

    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys(self::EDITABLE);
    }

    public static function label(string $key): string
    {
        return match ($key) {
            'Port' => 'SSH port',
            'PermitRootLogin' => 'Permit root login',
            'PasswordAuthentication' => 'Password login',
            'PubkeyAuthentication' => 'Key-based login',
            'PermitEmptyPasswords' => 'Permit empty passwords',
            default => $key,
        };
    }

    /** @return list<string>|null the selectable values — null = it's a port number */
    public static function choices(string $key): ?array
    {
        $spec = self::EDITABLE[$key] ?? null;

        return is_array($spec) ? $spec : null;
    }

    public static function assertValue(string $key, string $value): string
    {
        if (!isset(self::EDITABLE[$key])) {
            throw new ValidationError("{$key} cannot be changed through this system");
        }

        $spec = self::EDITABLE[$key];

        if ($spec === 'port') {
            if (preg_match('/^\d{1,5}$/', $value) !== 1 || (int) $value < 1 || (int) $value > 65535) {
                throw new ValidationError('Port number must be between 1 and 65535');
            }

            return $value;
        }

        if (!in_array($value, $spec, true)) {
            throw new ValidationError("The value of {$key} must be one of: " . implode(', ', $spec));
        }

        return $value;
    }

    public function isInstalled(Executor $executor): bool
    {
        return $executor->exists($executor->path(self::CONFIG));
    }

    /**
     * Reads the current values — a key missing from the file falls back to OpenSSH's own default
     *
     * @return array<string,array{value:string,explicit:bool}>
     */
    public function read(Executor $executor): array
    {
        $values = [];

        foreach (self::DEFAULTS as $key => $default) {
            $values[$key] = ['value' => $default, 'explicit' => false];
        }

        if (!$this->isInstalled($executor)) {
            return $values;
        }

        foreach (preg_split('/\R/', $executor->readFile($executor->path(self::CONFIG))) ?: [] as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            foreach (self::DEFAULTS as $key => $ignored) {
                // Compared case-insensitively, since sshd itself doesn't care about case
                if (preg_match('/^' . preg_quote($key, '/') . '\s+(\S+)/i', $line, $m) === 1) {
                    // The first line found wins — sshd itself also uses the first value
                    if (!$values[$key]['explicit']) {
                        $values[$key] = ['value' => $m[1], 'explicit' => true];
                    }
                }
            }
        }

        return $values;
    }

    /**
     * Writes the new values to the file, returning the original content for the caller to keep for restoring
     *
     * @param array<string,string> $changes
     * @return array{original:string,updated:string}
     */
    public function apply(Executor $executor, array $changes): array
    {
        $path = $executor->path(self::CONFIG);

        if (!$executor->exists($path)) {
            throw new ValidationError('The SSH config file was not found on this machine');
        }

        $original = $executor->readFile($path);
        $lines = preg_split('/\R/', $original) ?: [];

        foreach ($changes as $key => $value) {
            self::assertValue($key, $value);
            $lines = self::replaceDirective($lines, $key, $value);
        }

        $updated = implode("\n", $lines);
        $executor->writeFile($path, $updated, 0600);

        return ['original' => $original, 'updated' => $updated];
    }

    /**
     * Replaces a directive — the original line is commented out rather than deleted, so what used to be set stays traceable
     *
     * @param list<string> $lines
     * @return list<string>
     */
    private static function replaceDirective(array $lines, string $key, string $value): array
    {
        $out = [];
        $written = false;

        foreach ($lines as $line) {
            if (preg_match('/^\s*' . preg_quote($key, '/') . '\s+\S+/i', $line) === 1) {
                if (!$written) {
                    $out[] = '# ' . trim($line) . '   # edited by PHP Server Control Panel ' . date('Y-m-d H:i');
                    $out[] = $key . ' ' . $value;
                    $written = true;
                } else {
                    // Any remaining duplicate line is commented out, or the values would conflict with each other
                    $out[] = '# ' . trim($line) . '   # duplicate, disabled by Control Panel';
                }

                continue;
            }

            $out[] = $line;
        }

        if (!$written) {
            $out[] = '';
            $out[] = '# added by PHP Server Control Panel ' . date('Y-m-d H:i');
            $out[] = $key . ' ' . $value;
        }

        return $out;
    }

    /**
     * Validates the file with sshd itself before a reload is triggered
     *
     * @return array{0:bool,1:string}
     */
    public function testConfig(Executor $executor): array
    {
        $binary = '/usr/sbin/sshd';

        if (!$executor->exists($binary)) {
            return [true, 'Skipped validation: sshd was not found on this machine'];
        }

        // /run/sshd has to exist first, or `sshd -t` fails with "Missing
        // privilege separation directory" even though nothing is wrong with
        // the config file — full reasoning lives at
        // {@see \Phpcp\Driver\Ssh\SftpAccessManager::ensureRuntimeDir()}
        $executor->exec([$executor->path('/usr/bin/mkdir'), '-m', '0755', '-p', '/run/sshd'], timeout: 10);

        $result = $executor->exec([$binary, '-t', '-f', $executor->path(self::CONFIG)], timeout: 20);
        $output = trim($result->stderr) !== '' ? $result->stderr : $result->stdout;

        return [$result->ok(), $output];
    }
}
