<?php

declare(strict_types=1);

namespace Phpcp\Domain;

use Phpcp\Agent\ValidationError;
use Phpcp\Kernel\Db;
use Phpcp\Security\Permissions;

/**
 * The disk-quota guard for "a single write" — the single source of truth for
 * whether a write can proceed
 *
 * ## Why this class exists when QuotaChecker already does
 *
 * {@see QuotaChecker::checkOwnerCanCreate()} answers "can a new **resource** be
 * created" (a site, a database, a mailbox) — things counted by item · this answers
 * a different question: "can N more bytes be written into this account's home" —
 * something counted by size. Different question, but it uses the exact same
 * numbers (`disk_quota_mb`/`disk_used_mb`), so they must be interpreted identically.
 *
 * This rule used to be duplicated in each place and genuinely drifted apart: the
 * backup file's guard passed whenever "more than 0 remained," never comparing
 * against the size actually about to be written — an account with 1 MB of quota
 * left could create a 40 GB backup file · the file manager (upload, write, extract,
 * compress) had no guard at all, despite being the most direct way to write data
 * onto the machine.
 *
 * ## The limits of what this guard actually guarantees
 *
 * This is **application-level** enforcement, the same as `QuotaChecker` describes
 * — it only blocks writes that go through the panel · a file the customer's own
 * PHP code writes itself never passes through here at all. Blocking that requires
 * a filesystem-level project quota, which hasn't been implemented yet (PLAN-V2
 * Phase E2).
 *
 * And `disk_used_mb` is a value measured **on the previous cycle**
 * ({@see \Phpcp\Agent\Capability\DiskQuotaCheck}), not a live reading — so this
 * guard can be off by however much was written between the two measurement
 * cycles, which is acceptable for a guard whose job is "prevent one account from
 * crowding out space until someone else's site goes down."
 */
final class DiskQuota
{
    /** A size that isn't known in advance — can only check that it isn't already full */
    public const UNKNOWN = 0;

    private const MB = 1_048_576;

    /**
     * Can this account fit `$bytes` more bytes
     *
     * `$bytes = self::UNKNOWN` is used for work whose output size can't be known
     * beforehand (compressing a file, extracting a tar) — in that case, this can
     * only check that the quota isn't already full, which is still better than no
     * check at all.
     *
     * @throws ValidationError
     */
    public static function assertFits(Db $db, int $userId, int $bytes = self::UNKNOWN): void
    {
        $row = $db->first(
            'SELECT username, role, disk_quota_mb, disk_used_mb FROM users WHERE id = :id',
            ['id' => $userId],
        );

        // Admin accounts are not subject to quotas — the same rule as QuotaChecker::checkOwnerCanCreate()
        if ($row === null || ($row['role'] ?? '') !== Permissions::WEBADMIN) {
            return;
        }

        $limit = (int) ($row['disk_quota_mb'] ?? Quota::UNLIMITED);

        if (Quota::isUnlimited($limit)) {
            return;
        }

        $used = (int) ($row['disk_used_mb'] ?? 0);
        $free = $limit - $used;
        $need = (int) ceil(max(0, $bytes) / self::MB);

        if ($free > 0 && $need <= $free) {
            return;
        }

        $username = (string) ($row['username'] ?? '');

        // The message must say "how much is left," not just "full" — the customer
        // needs to decide how many old files to delete or how much to request the
        // quota be raised by, without having to guess.
        throw new ValidationError($free > 0
            ? sprintf(
                'Account %s does not have enough space — needs up to %s MB more, but only %s MB'
                . ' remains (using %s MB of %s MB) — delete old files or increase the quota first',
                $username,
                number_format($need),
                number_format($free),
                number_format($used),
                number_format($limit),
            )
            : sprintf(
                'Account %s is already full (using %s MB of %s MB) — backup files and uploaded files'
                . ' also count toward the quota, so delete old files or increase the quota first',
                $username,
                number_format($used),
                number_format($limit),
            ));
    }
}
