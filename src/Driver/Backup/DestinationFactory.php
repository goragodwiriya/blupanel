<?php

declare(strict_types=1);

namespace Phpcp\Driver\Backup;

use Phpcp\Agent\ValidationError;
use Phpcp\Domain\BackupDestinationRepository;

/**
 * Assembles a destination driver from a database row — the single place where a secret is ever used
 *
 * Kept separate from the repository, because the repository shouldn't know
 * about drivers and a driver shouldn't know about the database · this class
 * is the one place both sides meet, so "where is a secret used" can be
 * answered by reading a single file.
 */
final class DestinationFactory
{
    public function __construct(
        private readonly BackupDestinationRepository $destinations,
        private readonly string $sourceDir = '',
    ) {
    }

    /**
     * @param array<string,mixed> $row a row that has already passed through `present()` (no secret in it)
     */
    public function make(array $row): Destination
    {
        $id = (int) ($row['id'] ?? 0);
        $config = is_array($row['config'] ?? null) ? $row['config'] : [];
        $driver = (string) ($row['driver'] ?? '');

        return match ($driver) {
            'local' => new LocalDestination((string) ($config['path'] ?? ''), $this->sourceDir),

            'sftp' => new SftpDestination(
                host: (string) ($config['host'] ?? ''),
                port: (int) ($config['port'] ?? 22),
                user: (string) ($config['user'] ?? ''),
                path: (string) ($config['path'] ?? ''),
                privateKey: $this->destinations->secretFor($id),
                knownHosts: (string) ($config['known_hosts'] ?? ''),
            ),

            'rsync' => new RsyncDestination(
                host: (string) ($config['host'] ?? ''),
                port: (int) ($config['port'] ?? 22),
                user: (string) ($config['user'] ?? ''),
                path: (string) ($config['path'] ?? ''),
                privateKey: $this->destinations->secretFor($id),
                knownHosts: (string) ($config['known_hosts'] ?? ''),
            ),

            's3' => new S3Destination(
                bucket: (string) ($config['bucket'] ?? ''),
                region: (string) ($config['region'] ?? ''),
                accessKey: (string) ($config['access_key'] ?? ''),
                secretKey: $this->destinations->secretFor($id),
                path: (string) ($config['path'] ?? ''),
                endpoint: (string) ($config['endpoint'] ?? ''),
                pathStyle: (bool) ($config['path_style'] ?? false),
            ),

            default => throw new ValidationError('Unrecognized destination type: ' . $driver),
        };
    }

    /**
     * The fields each driver requires — the screen and the validator share the same list
     *
     * @return array<string,list<string>>
     */
    public static function requiredFields(): array
    {
        return [
            'local' => ['path'],
            'sftp' => ['host', 'user', 'path'],
            'rsync' => ['host', 'user', 'path'],
            's3' => ['bucket', 'region', 'access_key'],
        ];
    }

    /** A driver that needs a secret (an ssh key / secret key) before it can work at all */
    public static function needsSecret(string $driver): bool
    {
        return in_array($driver, ['sftp', 'rsync', 's3'], true);
    }
}
