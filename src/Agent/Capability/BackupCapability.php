<?php

declare(strict_types=1);

namespace Phpcp\Agent\Capability;

use Phpcp\Agent\Context;
use Phpcp\Agent\Executor\Executor;
use Phpcp\Agent\PermissionDenied;
use Phpcp\Agent\ValidationError;
use Phpcp\Domain\DiskQuota;
use Phpcp\Domain\Site;
use Phpcp\Domain\SiteRepository;
use Phpcp\Domain\UserAccount;
use Phpcp\Security\Permissions;

/**
 * The shared base for the backup.* family of capabilities — **every one of them
 * works on the data owner's own home**
 *
 * Since PLAN-BACKUP-V2, backup files no longer live in the panel's own space,
 * but at the customer's own `<home>/backup` · that brings three questions every
 * capability has to answer identically, and they've answered them differently
 * before, back when each answer was scattered across its own file:
 *
 *   1. **Who does this account belong to** — an admin can act on behalf of any account · a customer only their own
 *   2. **Who owns the file that gets created** — the agent runs as root, so a
 *      file it creates belongs to root unless told otherwise · a customer would
 *      then be unable to download their own copy, defeating the whole point
 *   3. **Is there still enough quota** — a backup file already counts against a
 *      customer's quota; writing more while it's full crowds out the space their
 *      website actually needs to run
 */
abstract class BackupCapability
{
    /**
     * The account that owns the backup folder, with the caller's permission checked
     *
     * @throws ValidationError|PermissionDenied
     */
    protected function ownerAccount(Context $context, int $userId): UserAccount
    {
        $actor = $context->actor;

        if (!self::isAdmin($actor->role) && $userId !== $actor->userId) {
            // "Not found", not "access denied" — a different answer would tell the caller which id genuinely exists
            throw new ValidationError("Hosting account {$userId} not found");
        }

        $user = $context->db->first('SELECT * FROM users WHERE id = :id', ['id' => $userId]);

        if ($user === null || ($user['system_user'] ?? null) === null) {
            throw new ValidationError("Hosting account {$userId} not found");
        }

        return UserAccount::fromRow($user);
    }

    /** Every domain name belonging to this account — used to identify which website a backup file belongs to */
    protected function domainsOf(Context $context, int $userId): array
    {
        return array_map(
            static fn (array $row): string => (string) $row['primary_domain'],
            $context->db->all(
                'SELECT primary_domain FROM sites WHERE owner_user_id = :u ORDER BY primary_domain',
                ['u' => $userId],
            ),
        );
    }

    /**
     * A website the caller has permission to touch
     *
     * @throws ValidationError|PermissionDenied
     */
    protected function siteFor(Context $context, int $siteId): Site
    {
        $repository = new SiteRepository($context->db);
        $site = $repository->load($siteId);

        if ($site === null) {
            throw new ValidationError('The specified website was not found');
        }

        $actor = $context->actor;

        if (!self::isAdmin($actor->role) && !$repository->isOwnedBy($siteId, $actor->userId)) {
            throw new PermissionDenied('You do not have permission to manage this website\'s backups');
        }

        return $site;
    }

    /**
     * What a backup file's owner must be (`user:group`) · empty = skip chown
     *
     * The same set `site.reset_owner` and restore use — the group is the web
     * server's own group, not the user's group (the full reasoning lives at
     * `SiteProvisioner::ownershipTargets()`).
     *
     * `shared_owner` means the filesystem can't record file ownership at all
     * anymore, so this is skipped entirely, rather than letting chown fail and
     * take the whole command down with it.
     */
    public static function ownerString(Context $context, UserAccount $owner): string
    {
        if ($context->config->sharedOwner()) {
            return '';
        }

        return $owner->username . ':' . SiteCapability::provisionerFor($context)->webserver()->runAsGroup();
    }

    /**
     * Does this account's disk quota still have room to write another backup file?
     *
     * **Checked before creating it, not once the disk is already full** — a
     * site's backup file is roughly the size of the whole site; letting writes
     * continue until it's full means a still-running site can no longer write
     * its own session data, cache, or uploaded files mid-request, which is far
     * more damaging than not having tonight's copy.
     *
     * **`$bytes` was always the missing piece** — this check used to pass the
     * instant "remaining is greater than 0", never comparing against the size
     * about to be written · an account with 1 MB of quota left could therefore
     * create a backup file of any size at all, meaning this check guarded
     * against nothing in the exact case it exists to guard against.
     *
     * @param int $bytes the size expected to be written · {@see DiskQuota::UNKNOWN} when not yet known
     * @throws ValidationError
     */
    protected function assertQuotaAllows(Context $context, UserAccount $owner, int $bytes = DiskQuota::UNKNOWN): void
    {
        DiskQuota::assertFits($context->db, $owner->userId, $bytes);
    }

    protected static function isAdmin(string $role): bool
    {
        return in_array($role, [Permissions::SUPERADMIN, Permissions::SYSADMIN], true);
    }

    /**
     * Every hosting account the caller has permission to see
     *
     * @return list<UserAccount>
     */
    protected function visibleAccounts(Context $context): array
    {
        $actor = $context->actor;
        $sql = 'SELECT * FROM users WHERE system_user IS NOT NULL';
        $params = [];

        if (!self::isAdmin($actor->role)) {
            $sql .= ' AND id = :id';
            $params['id'] = $actor->userId;
        }

        return array_map(
            static fn (array $row): UserAccount => UserAccount::fromRow($row),
            $context->db->all($sql . ' ORDER BY username', $params),
        );
    }

    /** Does this file genuinely exist — the folder belongs to the customer, who can delete it themselves at any time */
    protected function assertFileExists(Executor $executor, string $path): void
    {
        if (!$executor->exists($executor->path($path))) {
            throw new ValidationError(
                'File ' . basename($path) . ' is no longer in the backup folder — it may have been deleted after this screen was opened',
            );
        }
    }
}
