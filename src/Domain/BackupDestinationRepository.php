<?php

declare(strict_types=1);

namespace Phpcp\Domain;

use Phpcp\Agent\ValidationError;
use Phpcp\Kernel\Db;
use Phpcp\Security\Secret;

/**
 * A backup file's destination — the `backup_destinations` table (PLAN-V2 Phase E1)
 *
 * **A secret never leaves this class in readable form**, except through
 * `secretFor()`, which exists for the driver factory to call from exactly one
 * place · `all()` and `find()` always return a row with the `secret_enc` column
 * already stripped, so accidentally sending the whole row out through the API
 * becomes something that simply can't happen, not something that must be
 * remembered every single time — the same pattern `DbAccountRepository` uses for
 * MariaDB passwords.
 */
final class BackupDestinationRepository
{
    public const DRIVERS = ['local', 'sftp', 'rsync', 's3'];

    public function __construct(
        private readonly Db $db,
        private readonly Secret $secret,
    ) {
    }

    /** @return list<array<string,mixed>> */
    public function all(): array
    {
        return array_map(
            $this->present(...),
            $this->db->all('SELECT * FROM backup_destinations ORDER BY name'),
        );
    }

    /** @return list<array<string,mixed>> */
    public function enabled(): array
    {
        return array_map(
            $this->present(...),
            $this->db->all('SELECT * FROM backup_destinations WHERE enabled = 1 ORDER BY name'),
        );
    }

    /** @return array<string,mixed>|null */
    public function find(int $id): ?array
    {
        $row = $this->db->first('SELECT * FROM backup_destinations WHERE id = :id', ['id' => $id]);

        return $row === null ? null : $this->present($row);
    }

    /**
     * The decrypted secret — callable only from the driver factory
     *
     * Kept as its own method instead of coming attached to the row, because it
     * makes **searching the repo for who touches the secret** a single method-name
     * search, which is something that must be checkable during review.
     */
    public function secretFor(int $id): string
    {
        $encrypted = $this->db->value(
            'SELECT secret_enc FROM backup_destinations WHERE id = :id',
            ['id' => $id],
        );

        if ($encrypted === null || $encrypted === '') {
            return '';
        }

        return $this->secret->decrypt((string) $encrypted);
    }

    /**
     * @param array<string,mixed> $config
     */
    public function create(string $name, string $driver, array $config, string $secret, int $retentionDays, int $retentionCount): int
    {
        $this->assertDriver($driver);

        if (trim($name) === '') {
            throw new ValidationError('A destination name must be set');
        }

        if ($this->db->value('SELECT id FROM backup_destinations WHERE name = :n', ['n' => $name]) !== null) {
            throw new ValidationError('A destination with this name already exists');
        }

        $now = time();

        return $this->db->insert('backup_destinations', [
            'name' => trim($name),
            'driver' => $driver,
            'config_json' => json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'secret_enc' => $secret === '' ? null : $this->secret->encrypt($secret),
            'retention_days' => max(0, $retentionDays),
            'retention_count' => max(0, $retentionCount),
            'enabled' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /**
     * @param array<string,mixed> $changes  only recognized keys are accepted
     */
    public function update(int $id, array $changes): void
    {
        $fields = ['updated_at' => time()];

        foreach (['name', 'retention_days', 'retention_count', 'enabled'] as $key) {
            if (array_key_exists($key, $changes)) {
                $fields[$key] = $changes[$key];
            }
        }

        if (array_key_exists('config', $changes) && is_array($changes['config'])) {
            $fields['config_json'] = json_encode($changes['config'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        // Sending an empty secret means "unchanged," not "clear it" · the edit
        // form sends back the entire form with the secret field always empty
        // (since the real value is never sent out to begin with) — interpreting
        // that as "clear it" would break the destination every single time the
        // admin edited nothing but the name.
        if (isset($changes['secret']) && is_string($changes['secret']) && $changes['secret'] !== '') {
            $fields['secret_enc'] = $this->secret->encrypt($changes['secret']);
        }

        $this->db->update('backup_destinations', $fields, ['id' => $id]);
    }

    public function delete(int $id): void
    {
        $this->db->run('DELETE FROM backup_destinations WHERE id = :id', ['id' => $id]);
    }

    /** Record the most recent connection attempt's result — a destination failing silently is nearly as dangerous as having no destination at all */
    public function recordResult(int $id, bool $ok, string $error = ''): void
    {
        $this->db->update('backup_destinations', [
            'last_ok_at' => $ok ? time() : null,
            'last_error' => $ok ? null : mb_substr($error, 0, 500),
            'updated_at' => time(),
        ], ['id' => $id]);
    }

    public function assertDriver(string $driver): string
    {
        if (!in_array($driver, self::DRIVERS, true)) {
            throw new ValidationError('Invalid destination type — valid values: ' . implode(', ', self::DRIVERS));
        }

        return $driver;
    }

    /**
     * A row safe to export — carries no secret with it no matter how careless the caller is
     *
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function present(array $row): array
    {
        // Reports whether a secret is stored, without saying what it is — the
        // screen needs to tell "no key entered yet" apart from "entered, but not
        // shown" for the admin.
        $row['has_secret'] = ($row['secret_enc'] ?? null) !== null && $row['secret_enc'] !== '';
        unset($row['secret_enc']);

        $config = json_decode((string) ($row['config_json'] ?? '{}'), true);
        $row['config'] = is_array($config) ? $config : [];
        unset($row['config_json']);

        return $row;
    }
}
