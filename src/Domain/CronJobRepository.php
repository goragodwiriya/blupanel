<?php

declare(strict_types=1);

namespace Phpcp\Domain;

use Phpcp\Agent\ValidationError;
use Phpcp\Kernel\Db;

/**
 * A per-site scheduled job — the `cron_jobs` table
 *
 * The database is the single source of truth; the `/etc/cron.d` file is a
 * generated result that follows afterward (same as a vhost) · every edit must
 * therefore always end with rewriting the file.
 *
 * **The most important part of this class is `applyThenSync()`** — if writing the
 * file fails, the database edit must be rolled back, otherwise the screen would
 * report a job as active while cron has never heard of it, a mismatch that's very
 * hard to track down · this logic used to live only in the web page's controller —
 * moving it here means the REST API gets the exact same guarantee without
 * duplicating the code (which is exactly the situation where one copy eventually
 * forgets to roll back).
 */
final class CronJobRepository
{
    public function __construct(private readonly Db $db)
    {
    }

    /** @return array<string,mixed>|null */
    public function find(int $id): ?array
    {
        return $this->db->first('SELECT * FROM cron_jobs WHERE id = :id', ['id' => $id]);
    }

    /**
     * The job list along with site data — filtered by owner at the query level
     *
     * @return list<array<string,mixed>>
     */
    public function listWithSite(?int $ownerId = null): array
    {
        $where = $ownerId === null ? '' : ' WHERE s.owner_user_id = :owner';
        $params = $ownerId === null ? [] : ['owner' => $ownerId];

        return $this->db->all(
            'SELECT c.*, s.primary_domain, u.system_user
             FROM cron_jobs c
             JOIN sites s ON s.id = c.site_id
             JOIN users u ON u.id = s.owner_user_id' . $where . '
             ORDER BY s.primary_domain, c.name',
            $params,
        );
    }

    /**
     * Validate what the user submitted — the same rules for both the web page and the API
     *
     * @param array<string,mixed> $input
     * @return array{name:string,schedule:string,command:string,enabled:int}
     */
    public static function validate(array $input): array
    {
        $name = trim((string) ($input['name'] ?? ''));
        $command = trim((string) ($input['command'] ?? ''));

        if ($name === '' || mb_strlen($name) > 100) {
            throw new ValidationError('Job name must be 1-100 characters long');
        }

        if ($command === '') {
            throw new ValidationError('A command to run must be specified');
        }

        return [
            'name' => $name,
            // Throws its own ValidationError on a malformed schedule — a broken cron line gets the whole file silently skipped
            'schedule' => CronSchedule::normalize((string) ($input['schedule'] ?? '')),
            'command' => $command,
            'enabled' => ($input['enabled'] ?? true) ? 1 : 0,
        ];
    }

    /** @param array{name:string,schedule:string,command:string,enabled:int} $data */
    public function create(int $siteId, array $data): int
    {
        return $this->db->insert('cron_jobs', $data + ['site_id' => $siteId, 'created_at' => time()]);
    }

    /** @param array<string,mixed> $data */
    public function update(int $id, array $data): void
    {
        $this->db->update('cron_jobs', $data, ['id' => $id]);
    }

    public function delete(int $id): void
    {
        $this->db->run('DELETE FROM cron_jobs WHERE id = :id', ['id' => $id]);
    }

    /**
     * Apply an edit, then rewrite the cron file — roll back immediately on failure
     *
     * `$undo` receives `$change`'s result as its argument, because undoing "a
     * create" needs the id of the row that was just created, which doesn't exist
     * yet when the caller builds the closure.
     *
     * @param callable():mixed      $change makes the database edit, returns the value to hand back to the caller
     * @param callable():mixed      $sync   dispatches cron.sync through the agent (throws on failure)
     * @param callable(mixed):mixed $undo   reverts whatever $change did
     */
    public function applyThenSync(callable $change, callable $sync, callable $undo): mixed
    {
        $result = $change();

        try {
            $sync();
        } catch (\Throwable $e) {
            $undo($result);

            throw $e;
        }

        return $result;
    }

    /**
     * A restorable snapshot of one job's values
     *
     * @param array<string,mixed> $job
     * @return array<string,mixed>
     */
    public static function restorable(array $job): array
    {
        return [
            'name' => $job['name'],
            'schedule' => $job['schedule'],
            'command' => $job['command'],
            'enabled' => $job['enabled'],
        ];
    }
}
