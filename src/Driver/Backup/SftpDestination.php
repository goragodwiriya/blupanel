<?php

declare(strict_types=1);

namespace Phpcp\Driver\Backup;

use Phpcp\Agent\ExecutionFailed;
use Phpcp\Agent\Executor\Executor;

/**
 * Pushes a backup file to another machine with `sftp` — PLAN-V2 phase E1
 *
 * Fits the most common case: the destination machine is Linux with OpenSSH
 * already running, and the destination account is restricted to sftp only
 * (`ForceCommand internal-sftp` + chroot) — the recommended setup, since
 * even a leaked key still can't run commands on the backup machine.
 *
 * **Completeness is confirmed with a checksum on the destination side, not
 * just file size** — `sftp` reports success once the write finishes, but a
 * file written to a disk that's about to fail also "finishes" writing · if
 * the destination machine has no `sha256sum` available (e.g. a chroot with
 * only internal-sftp), this falls back to comparing size instead, and
 * **says so in the result that only size could be checked**, rather than silently letting it pass.
 */
final class SftpDestination extends SshDestination
{
    private const SFTP = '/usr/bin/sftp';
    private const SSH = '/usr/bin/ssh';

    public static function driver(): string
    {
        return 'sftp';
    }

    public function push(Executor $executor, string $localPath, string $remoteName): string
    {
        $remotePath = $this->remote($remoteName);

        return $this->withKey($executor, function (string $keyFile) use ($executor, $localPath, $remotePath): string {
            // -b - reads the command batch from stdin · so filenames never pass through a shell at all
            //
            // Directories are created one level at a time, top-down — sftp
            // has no `mkdir -p` (see makeDirectoryScript) · a level that
            // already exists is skipped on its own because of the leading `-`
            $script = sprintf(
                "%sput %s %s\nbye\n",
                $this->makeDirectoryScript(),
                $this->quote($executor->path($localPath)),
                $this->quote($remotePath),
            );

            $result = $executor->exec(
                [self::SFTP, ...$this->sshOptions($keyFile), '-P', (string) $this->port, '-b', '-',
                 $this->user . '@' . $this->host],
                timeout: 1800,
                stdin: $script,
            );

            $this->assertOk($result, 'Failed to push the backup file to the destination');
            $this->assertArrived($executor, $keyFile, $localPath, $remotePath);

            return $remotePath;
        });
    }

    public function pull(Executor $executor, string $remotePath, string $localPath): void
    {
        $this->assertInsidePath($remotePath);

        $this->withKey($executor, function (string $keyFile) use ($executor, $remotePath, $localPath): void {
            $script = sprintf(
                "get %s %s\nbye\n",
                $this->quote($remotePath),
                $this->quote($executor->path($localPath)),
            );

            $result = $executor->exec(
                [self::SFTP, ...$this->sshOptions($keyFile), '-P', (string) $this->port, '-b', '-',
                 $this->user . '@' . $this->host],
                timeout: 1800,
                stdin: $script,
            );

            $this->assertOk($result, 'Failed to pull the backup file from the destination');

            if (!$executor->exists($executor->path($localPath))) {
                throw new ExecutionFailed('The pull command succeeded, but the file was not found on this machine');
            }
        });
    }

    public function delete(Executor $executor, string $remotePath): void
    {
        $this->assertInsidePath($remotePath);

        $this->withKey($executor, function (string $keyFile) use ($executor, $remotePath): void {
            $result = $executor->exec(
                [self::SFTP, ...$this->sshOptions($keyFile), '-P', (string) $this->port, '-b', '-',
                 $this->user . '@' . $this->host],
                timeout: 120,
                stdin: sprintf("rm %s\nbye\n", $this->quote($remotePath)),
            );

            // A file that's already gone counts as success — the cleanup job must be able to call this again
            if (!$result->ok() && !str_contains($result->stderr, 'No such file')) {
                throw new ExecutionFailed('Failed to delete the file at the destination: ' . $this->explain($result->stderr));
            }
        });
    }

    /**
     * Confirms the file at the destination genuinely matches the original
     *
     * If the destination can't run `sha256sum` (an internal-sftp chroot
     * definitely can't), this falls back to comparing size — weaker, but
     * still catches a half-arrived file, the most common failure of all.
     */
    private function assertArrived(Executor $executor, string $keyFile, string $localPath, string $remotePath): void
    {
        $localSize = $executor->stat($executor->path($localPath))['size'] ?? 0;

        $remoteSum = $executor->exec(
            [self::SSH, ...$this->sshOptions($keyFile), '-p', (string) $this->port,
             $this->user . '@' . $this->host, 'sha256sum', '--', $remotePath],
            timeout: 600,
        );

        if ($remoteSum->ok()) {
            $expected = @hash_file('sha256', $executor->path($localPath));
            $actual = strtok(trim($remoteSum->stdout), ' ');

            if ($expected === false || !is_string($actual) || !hash_equals($expected, $actual)) {
                throw new ExecutionFailed('The file at the destination does not match the original — treated as a failed push');
            }

            return;
        }

        // The destination can't run commands at all — fall back to comparing size through sftp instead
        $listing = $executor->exec(
            [self::SFTP, ...$this->sshOptions($keyFile), '-P', (string) $this->port, '-b', '-',
             $this->user . '@' . $this->host],
            timeout: 120,
            stdin: sprintf("ls -l %s\nbye\n", $this->quote($remotePath)),
        );

        $this->assertOk($listing, 'Failed to check the file at the destination');

        if ($localSize > 0 && !str_contains($listing->stdout, (string) $localSize)) {
            throw new ExecutionFailed(
                'The file size at the destination does not match the original — treated as a failed push',
            );
        }
    }

    /** Quotes a value the way sftp understands — our own filenames never contain a " anyway, but this guards against it */
    private function quote(string $value): string
    {
        return '"' . str_replace('"', '\\"', $value) . '"';
    }
}
