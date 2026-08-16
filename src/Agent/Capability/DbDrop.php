<?php

declare(strict_types=1);

namespace Phpcp\Agent\Capability;

use Phpcp\Agent\Context;
use Phpcp\Agent\Executor\Executor;
use Phpcp\Agent\ValidationError;
use Phpcp\Domain\Site;
use Phpcp\Domain\SiteRepository;
use Phpcp\Driver\Db\MariaDbManager;
use Phpcp\Support\Validator;

/**
 * Drops a database — an irreversible operation, so an automatic backup is always
 * forced first
 *
 * Unlike deleting a website, where files can move to a holding area, a database
 * that's been DROPped is gone instantly — the only way back is having a dump
 * file beforehand, so the system backs it up on its own without asking, and
 * cancels the whole deletion if that backup fails.
 */
final class DbDrop extends DbCapability
{
    public static function name(): string
    {
        return 'db.drop';
    }

    public function permission(): string
    {
        return 'db.manage';
    }

    public function isMutating(): bool
    {
        return true;
    }

    public function summary(): string
    {
        return 'Drop database (always backed up automatically first)';
    }

    public function validate(array $args): array
    {
        return [
            'name' => MariaDbManager::assertDatabaseName(Validator::requireString($args, 'name', 64)),
            'confirm' => Validator::requireString($args, 'confirm', 64),
            'drop_user' => ($args['drop_user'] ?? '') === '1' || ($args['drop_user'] ?? false) === true,
        ];
    }

    public function run(array $args, Executor $executor, Context $context): array
    {
        $manager = $this->manager();
        $name = $args['name'];

        if ($args['confirm'] !== $name) {
            throw new ValidationError('The confirmation database name does not match the one being dropped — cancelled for safety');
        }

        $this->assertOwnership($context, $name);

        if (!$manager->isInstalled($executor)) {
            throw new ValidationError('MariaDB or MySQL is not installed on this machine');
        }

        // Always backed up first — no backup means no drop
        $site = $this->ownerSite($context, $name);
        $backupPath = $this->safetyPath($context, $site, $name);

        $executor->makeDirectory($executor->path(dirname($backupPath)), 0750);

        $bytes = $manager->dump($executor, $name, $backupPath);

        // Hands the file over to the data's owner — otherwise a customer who
        // dropped the wrong database would have to ask an admin to fetch their
        // own file for them, even though it's already sitting in their own home
        $owner = $site === null ? '' : BackupCapability::ownerString($context, $site->owner);

        if ($owner !== '') {
            $executor->exec(['/usr/bin/chown', $owner, $executor->path($backupPath)], timeout: 60);
            $executor->changeMode($executor->path($backupPath), 0640);
        }

        $manager->dropDatabase($executor, $name);

        $users = $this->removeRecords($context, $name, $args['drop_user']);

        foreach ($users as $user) {
            // Only deletes a user with no other database still using them
            $manager->dropUser($executor, $user['username'], $user['host']);
        }

        $this->invalidateSizesCache($executor, $context);

        return [
            'name' => $name,
            'backup' => $backupPath,
            'backup_bytes' => $bytes,
            'users_removed' => array_column($users, 'username'),
            'message' => sprintf(
                'Dropped database %s — backed up to %s (%s bytes) before dropping',
                $name,
                basename($backupPath),
                number_format($bytes),
            ),
        ];
    }

    /**
     * Deletes the panel's own row and returns the list of users that should be deleted along with it
     *
     * @return list<array{username:string,host:string}>
     */
    private function removeRecords(Context $context, string $name, bool $dropUser): array
    {
        return $context->db->transaction(static function ($db) use ($name, $dropUser): array {
            $row = $db->first('SELECT id FROM databases_ WHERE db_name = :n', ['n' => $name]);

            if ($row === null) {
                return [];   // Not managed by the panel — dropping it on the machine is all that's needed
            }

            $databaseId = (int) $row['id'];
            $orphans = [];

            if ($dropUser) {
                $candidates = $db->all(
                    'SELECT u.id, u.username, u.host FROM db_grants g
                     JOIN db_users u ON u.id = g.db_user_id WHERE g.db_id = :id',
                    ['id' => $databaseId],
                );

                foreach ($candidates as $candidate) {
                    // A user still bound to another database must never be deleted
                    $others = (int) $db->value(
                        'SELECT count(*) FROM db_grants WHERE db_user_id = :u AND db_id != :d',
                        ['u' => $candidate['id'], 'd' => $databaseId],
                        0,
                    );

                    if ($others === 0) {
                        $orphans[] = ['username' => (string) $candidate['username'], 'host' => (string) $candidate['host']];
                        $db->run('DELETE FROM db_users WHERE id = :id', ['id' => $candidate['id']]);
                    }
                }
            }

            $db->run('DELETE FROM databases_ WHERE id = :id', ['id' => $databaseId]);

            return $orphans;
        });
    }

    /**
     * Where the pre-drop backup file goes — **inside that database's owner's own home**
     *
     * Used to pile up at `/var/lib/phpcp/backups` with a row recorded in a table
     * · a customer who dropped the wrong database had to ask an admin to fetch
     * the file for them, even though it was their own data · now the file lands
     * in their own backup folder and shows up in the listing on its own, since
     * that listing reads from the real folder (PLAN-BACKUP-V2 item B4) — no row
     * needed to point at it.
     *
     * A database not bound to a panel-managed website has no home to go to, so
     * it falls back to the panel's own holding area as before — that's not a
     * reason to skip the backup.
     */
    private function safetyPath(Context $context, ?Site $site, string $name): string
    {
        $stamp = date('Ymd-His');

        if ($site === null) {
            return $context->config->paths->backups() . '/db-' . $name . '-' . $stamp . '.sql.gz';
        }

        // Starts with the domain like every other backup file, so a listing
        // reading from the folder can match the file back to its site (see
        // BackupFiles::domainOf) · pure ASCII, since this name gets sent back as
        // part of a URL when deleted from the screen
        return sprintf('%s/%s-db-%s-before-drop-%s.sql.gz', $site->backupDir(), $site->domain, $name, $stamp);
    }

    /** The website that owns this database — null = a database not managed by the panel */
    private function ownerSite(Context $context, string $name): ?Site
    {
        $row = $context->db->first(
            'SELECT s.id FROM databases_ d JOIN sites s ON s.id = d.site_id WHERE d.db_name = :n',
            ['n' => $name],
        );

        return $row === null ? null : (new SiteRepository($context->db))->load((int) $row['id']);
    }
}
