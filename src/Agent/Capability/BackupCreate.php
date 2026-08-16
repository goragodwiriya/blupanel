<?php

declare(strict_types=1);

namespace Phpcp\Agent\Capability;

use Phpcp\Agent\Capability;
use Phpcp\Agent\Context;
use Phpcp\Agent\Executor\Executor;
use Phpcp\Agent\PermissionDenied;
use Phpcp\Agent\ValidationError;
use Phpcp\Domain\DiskQuota;
use Phpcp\Domain\Site;
use Phpcp\Driver\BackupManager;
use Phpcp\Driver\Db\MariaDbManager;
use Phpcp\Support\Validator;

/**
 * Creates a backup file **into the website owner's own home** — website files or a database
 *
 * ## What changed from the original design (PLAN-BACKUP-V2)
 *
 * It used to write into `/var/lib/phpcp/backups`, the panel's own space, and
 * treat a row in the `backups` table as the source of truth · now the file
 * lives at the customer's own `<home>/backup`: they can download it
 * themselves, delete it themselves, and it counts against their own quota —
 * **so no parallel row is recorded anymore** (see
 * {@see \Phpcp\Domain\BackupFiles} for why a row a customer can delete the
 * underlying file out from under is actively harmful).
 *
 * The `config`/`full` types were cut — machine-level settings don't belong
 * to any one customer, so there's no home for them to live in · a VPS
 * snapshot or git is a more direct way to back those up (item B2).
 *
 * `destination_id` is still here, because a scheduled job calls a capability
 * once per job — splitting "create" and "push offsite" into two separate
 * commands would leave a window where the backup file sits on the same disk
 * as the real data with nothing carrying it away.
 */
final class BackupCreate extends BackupCapability implements Capability
{
    private const DU = '/usr/bin/du';

    /** Measuring size must never itself become the reason a backup job hangs — same ceiling as DiskQuotaCheck */
    private const MEASURE_TIMEOUT = 120;

    public static function name(): string
    {
        return 'backup.create';
    }

    /**
     * **A server admin's permission, not a customer's**
     *
     * Creating one backup file consumes space equal to the whole site
     * against the customer's own quota, and CPU on a machine every site
     * shares · an admin is the one who decides which accounts get backed up
     * at all (per-account switch + a single machine-wide schedule), so the
     * "back up now" button has to sit in the same hands, not be something a
     * customer can click without limit.
     *
     * A customer still has `backup.manage` to **delete** their own copies —
     * which returns space, rather than consuming it.
     */
    public function permission(): string
    {
        return 'backup.offsite';
    }

    public function isMutating(): bool
    {
        return true;
    }

    public function summary(): string
    {
        return "Create a website or database backup into the owner's backup folder";
    }

    public function validate(array $args): array
    {
        return [
            'type' => BackupManager::assertType(Validator::requireString($args, 'type', 16)),
            'site_id' => Validator::requireInt($args, 'site_id', 1),
            'database' => Validator::optionalString($args, 'database', '', 64),
            // 0 = keep it on this machine only · when given, pushed offsite immediately after creation
            'destination_id' => Validator::optionalInt($args, 'destination_id', 0, 0),
        ];
    }

    public function run(array $args, Executor $executor, Context $context): array
    {
        $site = $this->siteFor($context, $args['site_id']);
        $owner = $site->owner;

        // Has to know which database to back up before measuring size — and
        // a user should always see "this site has no database" before
        // "quota exceeded", since the first is more specific and more
        // directly actionable
        $database = $args['type'] === 'database'
            ? $this->database($context, $site->id, $args['database'])
            : '';

        $this->assertQuotaAllows($context, $owner, $this->estimateBytes($executor, $site, $database));

        $manager = new BackupManager();
        $ownerString = self::ownerString($context, $owner);

        $created = $database !== ''
            ? $manager->backupDatabase($executor, $site, $database, $ownerString)
            : $manager->backupSite($executor, $site, $ownerString);

        $file = [
            'type' => $args['type'],
            'domain' => $site->domain,
            'user_id' => $owner->userId,
            'name' => basename($created['path']),
            'path' => $created['path'],
            'bytes' => $created['bytes'],
            'checksum' => $created['checksum'],
        ];

        $offsite = $this->push($file, $args['destination_id'], $executor, $context);

        return [
            'created' => [$file],
            'count' => 1,
            'bytes' => $created['bytes'],
            'offsite' => $offsite,
            'message' => sprintf(
                // BackupManager::typeLabel() returns a Thai label (src/Driver
                // is not yet converted) — mapped to English here instead of
                // calling it, so this message doesn't end up half-translated
                'Backed up %s for %s (%s bytes), saved to %s',
                $args['type'] === 'database' ? 'database' : 'website files',
                $site->domain,
                number_format($created['bytes']),
                $owner->backupDir(),
            ) . ($offsite === [] ? '' : ' · ' . $offsite['message']),
        ];
    }

    /**
     * The most this backup file could possibly consume — the number the quota gate needs
     *
     * **Deliberately the pre-compression size** · the real file that comes
     * out is almost always smaller (web text and SQL compress roughly
     * 5–10×), but the quota gate has to err on the safe side — guessing low
     * and being wrong means the disk fills until other customers' sites
     * can't write files at all, while guessing high and being wrong just
     * means a message telling the customer to delete old files first, which
     * they can fix themselves in 10 seconds · that's why the gate's own
     * message says "no more than", not "requires"
     * ({@see DiskQuota::assertFits()}).
     *
     * Can't be measured = returns UNKNOWN, not 0, which would mean "takes no
     * space at all" — an account whose home can't be measured has to fall
     * through to the "already full?" gate, not sail through just because
     * measuring failed.
     */
    private function estimateBytes(Executor $executor, Site $site, string $database): int
    {
        try {
            if ($database !== '') {
                return (new MariaDbManager())->sizes($executor)[$database] ?? DiskQuota::UNKNOWN;
            }

            return $this->measureDirectory($executor, $site);
        } catch (\Throwable) {
            // A failed measurement must never fail the whole backup job — the remaining gate still blocks a genuinely full quota
            return DiskQuota::UNKNOWN;
        }
    }

    /**
     * The docroot's size in bytes — walks files under the owner's own privileges, per ARCHITECTURE §4.4
     *
     * Walks the file tree an extra time before tar walks it again, which is
     * acceptable: `du` only reads metadata, while tar reads every file's
     * whole content and compresses it — an entirely different order of cost.
     */
    private function measureDirectory(Executor $executor, Site $site): int
    {
        $path = $executor->path($site->docroot());

        if (!$executor->exists($path)) {
            return DiskQuota::UNKNOWN;
        }

        $result = $executor->asUser($site->systemUser(), static function () use ($executor, $path): array {
            $run = $executor->exec([self::DU, '-sk', '-x', '--', $path], timeout: self::MEASURE_TIMEOUT);

            return ['ok' => $run->ok(), 'out' => $run->output()];
        });

        if (($result['ok'] ?? false) !== true
            || preg_match('/^(\d+)/', (string) ($result['out'] ?? ''), $m) !== 1) {
            return DiskQuota::UNKNOWN;
        }

        return ((int) $m[1]) * 1024;
    }

    /**
     * The database to back up — must genuinely belong to this site
     *
     * A site with only one database doesn't need to specify it · guessing
     * when there are several would mean backing up the wrong database while
     * reporting success, only noticed at restore time.
     *
     * @throws ValidationError|PermissionDenied
     */
    private function database(Context $context, int $siteId, string $requested): string
    {
        $owned = array_map(
            static fn (array $row): string => (string) $row['db_name'],
            $context->db->all('SELECT db_name FROM databases_ WHERE site_id = :id ORDER BY db_name', ['id' => $siteId]),
        );

        if ($owned === []) {
            throw new ValidationError('This website has no database to back up yet');
        }

        if ($requested === '') {
            if (count($owned) > 1) {
                throw new ValidationError(
                    'This website has several databases (' . implode(', ', $owned) . ') — pick which one to back up',
                );
            }

            return $owned[0];
        }

        if (!in_array($requested, $owned, true)) {
            throw new PermissionDenied('This database does not belong to the selected website');
        }

        return $requested;
    }

    /**
     * Pushes the file just created out to a destination — the step that makes "automatic backup" actually mean something
     *
     * **A failed push deliberately doesn't fail the whole command** · the
     * local file was already created successfully and can still be restored
     * from · throwing away the whole job would leave the user thinking there
     * is no backup at all when there genuinely is one — so the push result
     * is returned alongside the response instead.
     *
     * @param  array<string,mixed> $file
     * @return array<string,mixed>
     */
    private function push(array $file, int $destinationId, Executor $executor, Context $context): array
    {
        if ($destinationId < 1) {
            return [];
        }

        try {
            $result = (new BackupPush())->run([
                'user_id' => $file['user_id'],
                'file' => $file['name'],
                'destination_id' => $destinationId,
            ], $executor, $context);

            return ['ok' => true, 'message' => (string) ($result['message'] ?? 'Pushed offsite')];
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => 'Failed to push offsite: ' . $e->getMessage()];
        }
    }
}
