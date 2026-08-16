<?php

declare(strict_types=1);

namespace Phpcp\Agent\Capability;

use Phpcp\Agent\Capability;
use Phpcp\Agent\Context;
use Phpcp\Agent\Executor\Executor;
use Phpcp\Domain\BackupDestinationRepository;
use Phpcp\Security\Secret;
use Phpcp\Support\Validator;

/**
 * The whole machine's automatic backup cycle — **a single process for the entire
 * system** (items B6, B10)
 *
 * ## Why one process, not many schedules
 *
 * The original design was a row in `scheduled_jobs` per site per backup type,
 * created by hand by an admin · a machine with fifty customers ended up with a
 * hundred-plus schedules to maintain by hand, and **a newly created site got no
 * backups at all** until someone remembered to add a schedule for it — a failure
 * completely silent until the day the backup file was actually needed.
 *
 * Now it's a switch on the account (`users.backup_files`, `users.backup_database`),
 * and this capability follows those switches every cycle · a new site under an
 * account with the switch on is included automatically.
 *
 * ## One account failing must never fail the whole cycle
 *
 * A single site with a full disk or a crashed database must never stop the other
 * forty-nine accounts from getting backed up that night · so failures are
 * collected into a list and reported back, never thrown to halt the whole
 * process — but they must be **counted and reported visibly**, never silently
 * swallowed, which is the opposite mistake and just as bad.
 */
final class BackupRun extends BackupCapability implements Capability
{
    public static function name(): string
    {
        return 'backup.run';
    }

    /**
     * A **whole-machine** permission — this cycle creates files inside every
     * customer's home who has the switch on, and counts against their quota ·
     * this is a server admin's decision, not a customer's.
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
        return 'Back up every account the admin has enabled, then clean up old files';
    }

    public function validate(array $args): array
    {
        return [
            // 0 = use the currently enabled destination (if any) · specified to force one particular destination
            'destination_id' => Validator::optionalInt($args, 'destination_id', 0, 0),
            'prune' => (bool) ($args['prune'] ?? true),
            'days' => Validator::optionalInt($args, 'days', 30, 0),
            'keep' => Validator::optionalInt($args, 'keep', 7, 0),
        ];
    }

    public function run(array $args, Executor $executor, Context $context): array
    {
        $destinationId = $args['destination_id'] > 0
            ? $args['destination_id']
            : $this->defaultDestination($context);

        $create = new BackupCreate();
        $done = [];
        $failed = [];
        $bytes = 0;

        foreach ($this->targets($context) as $target) {
            foreach ($target['types'] as $type) {
                try {
                    $result = $create->run([
                        'type' => $type,
                        'site_id' => $target['site_id'],
                        'database' => '',
                        'destination_id' => $destinationId,
                    ], $executor, $context);

                    $bytes += (int) ($result['bytes'] ?? 0);
                    $done[] = [
                        'site_id' => $target['site_id'],
                        'domain' => $target['domain'],
                        'type' => $type,
                        'bytes' => (int) ($result['bytes'] ?? 0),
                    ];
                } catch (\Throwable $e) {
                    $failed[] = [
                        'site_id' => $target['site_id'],
                        'domain' => $target['domain'],
                        'type' => $type,
                        'error' => $e->getMessage(),
                    ];
                }
            }
        }

        $pruned = $args['prune']
            ? (new BackupPrune())->run(
                ['days' => $args['days'], 'keep' => $args['keep'], 'user_id' => 0, 'dry_run' => false],
                $executor,
                $context,
            )
            : ['removed_count' => 0, 'freed_bytes' => 0];

        return [
            'created' => $done,
            'created_count' => count($done),
            'failed' => $failed,
            'failed_count' => count($failed),
            'bytes' => $bytes,
            'pruned_count' => (int) ($pruned['removed_count'] ?? 0),
            'freed_bytes' => (int) ($pruned['freed_bytes'] ?? 0),
            'message' => sprintf(
                'Backed up %d item(s) (%s bytes)%s · cleaned up %d file(s)',
                count($done),
                number_format($bytes),
                $failed === [] ? '' : sprintf(' · %d failed', count($failed)),
                (int) ($pruned['removed_count'] ?? 0),
            ),
        ];
    }

    /**
     * The sites to back up this cycle, with the types their owner has switched on
     *
     * A suspended site is still backed up — an account that stopped paying is
     * the account that **needs** a backup file the most, since its next step is deletion.
     *
     * The `database` type is only included for sites that genuinely have a
     * database · including it for every site would mean every static site
     * reports a failure every night, and the failures that actually matter would
     * drown in the noise.
     *
     * @return list<array{site_id:int,domain:string,types:list<string>}>
     */
    private function targets(Context $context): array
    {
        $rows = $context->db->all(
            "SELECT s.id, s.primary_domain, u.backup_files, u.backup_database,
                    (SELECT COUNT(*) FROM databases_ d WHERE d.site_id = s.id) AS databases
               FROM sites s JOIN users u ON u.id = s.owner_user_id
              WHERE u.system_user IS NOT NULL
                AND (u.backup_files = 1 OR u.backup_database = 1)
              ORDER BY u.username, s.primary_domain",
        );

        $targets = [];

        foreach ($rows as $row) {
            $types = [];

            if ((int) $row['backup_files'] === 1) {
                $types[] = 'site';
            }

            // A site with multiple databases is skipped — `backup.create` refuses
            // to guess which database to back up, and an automatic cycle must
            // never decide something a human hasn't decided yet
            if ((int) $row['backup_database'] === 1 && (int) $row['databases'] === 1) {
                $types[] = 'database';
            }

            if ($types !== []) {
                $targets[] = [
                    'site_id' => (int) $row['id'],
                    'domain' => (string) $row['primary_domain'],
                    'types' => $types,
                ];
            }
        }

        return $targets;
    }

    /** The currently enabled offsite destination — 0 = none, kept local only */
    private function defaultDestination(Context $context): int
    {
        $enabled = (new BackupDestinationRepository($context->db, new Secret($context->config->secretKey())))
            ->enabled();

        return $enabled === [] ? 0 : (int) $enabled[0]['id'];
    }
}
