<?php

declare(strict_types=1);

namespace Phpcp\Driver\Backup;

use Phpcp\Agent\ExecutionFailed;
use Phpcp\Agent\Executor\Executor;

/**
 * Pushes a backup file with `rsync` over ssh — PLAN-V2 phase E1
 *
 * **How this differs from sftp:** rsync sends only the part that differs
 * from whatever the destination already has, and can resume from where it
 * left off if the connection drops (`--partial`) · this matters a lot for a
 * multi-gigabyte backup file sent across the internet every night, a case
 * where sftp has to start the whole file over from scratch every time.
 *
 * **`--checksum` is not turned on for the push itself**, because it forces
 * reading the entire file on both sides before deciding what to do, which
 * is slower than just sending the whole file again in plenty of cases ·
 * completeness is instead confirmed after the push with a single checksum
 * comparison, which gets the same guarantee while paying the read cost only once.
 *
 * Requires `rsync` on both ends — if the destination doesn't have it, use an sftp destination instead.
 */
final class RsyncDestination extends SshDestination
{
    private const RSYNC = '/usr/bin/rsync';
    private const SSH = '/usr/bin/ssh';

    public static function driver(): string
    {
        return 'rsync';
    }

    public function push(Executor $executor, string $localPath, string $remoteName): string
    {
        $remotePath = $this->remote($remoteName);

        return $this->withKey($executor, function (string $keyFile) use ($executor, $localPath, $remotePath): string {
            // The destination directory is created first — rsync doesn't create intermediate levels unless --relative is used
            $mkdir = $executor->exec(
                [self::SSH, ...$this->sshOptions($keyFile), '-p', (string) $this->port,
                 $this->user . '@' . $this->host, 'mkdir', '-p', '--', dirname($remotePath)],
                timeout: 120,
            );

            $this->assertOk($mkdir, 'Failed to create the directory at the destination');

            $result = $executor->exec([
                self::RSYNC,
                '--archive',
                '--partial',          // Resumes from where it left off if the connection drops, instead of starting over
                '--compress',
                '--times',
                '--chmod=F600',       // A backup file at the destination must not be readable by other users on that machine
                '--rsh', $this->rshCommand($keyFile),
                $executor->path($localPath),
                sprintf('%s@%s:%s', $this->user, $this->host, $remotePath),
            ], timeout: 3600);

            $this->assertOk($result, 'Failed to push the backup file to the destination');
            $this->assertArrived($executor, $keyFile, $localPath, $remotePath);

            return $remotePath;
        });
    }

    public function pull(Executor $executor, string $remotePath, string $localPath): void
    {
        $this->assertInsidePath($remotePath);

        $this->withKey($executor, function (string $keyFile) use ($executor, $remotePath, $localPath): void {
            $result = $executor->exec([
                self::RSYNC,
                '--archive',
                '--partial',
                '--compress',
                '--rsh', $this->rshCommand($keyFile),
                sprintf('%s@%s:%s', $this->user, $this->host, $remotePath),
                $executor->path($localPath),
            ], timeout: 3600);

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
            // `rm -f` doesn't fail when the file is already gone, which is exactly what the cleanup job needs
            $result = $executor->exec(
                [self::SSH, ...$this->sshOptions($keyFile), '-p', (string) $this->port,
                 $this->user . '@' . $this->host, 'rm', '-f', '--', $remotePath],
                timeout: 120,
            );

            $this->assertOk($result, 'Failed to delete the file at the destination');
        });
    }

    /** Confirmed with sha256 at the destination — a machine that has rsync always has a shell, so this can be expected to work */
    private function assertArrived(Executor $executor, string $keyFile, string $localPath, string $remotePath): void
    {
        $remoteSum = $executor->exec(
            [self::SSH, ...$this->sshOptions($keyFile), '-p', (string) $this->port,
             $this->user . '@' . $this->host, 'sha256sum', '--', $remotePath],
            timeout: 600,
        );

        $this->assertOk($remoteSum, 'Failed to check the file at the destination');

        $expected = @hash_file('sha256', $executor->path($localPath));
        $actual = strtok(trim($remoteSum->stdout), ' ');

        if ($expected === false || !is_string($actual) || !hash_equals($expected, $actual)) {
            throw new ExecutionFailed('The file at the destination does not match the original — treated as a failed push');
        }
    }

    /**
     * The ssh command rsync will use
     *
     * `--rsh` takes a single string that rsync splits into words itself ·
     * every value in it comes from a field already validated when the
     * object was constructed (host/port/path) or is a file path this code
     * built itself, so no freely-typed user value can ever leak into this string.
     */
    private function rshCommand(string $keyFile): string
    {
        return implode(' ', [self::SSH, ...$this->sshOptions($keyFile), '-p', (string) $this->port]);
    }
}
