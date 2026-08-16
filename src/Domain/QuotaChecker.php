<?php

declare(strict_types=1);

namespace Phpcp\Domain;

use Phpcp\Security\Permissions;

/**
 * Decides whether a user can create more of a given resource
 *
 * Since migration 0005, quotas live on the same `users` row used for login, so
 * there is no longer any way to end up with a "user that has no quota" — quotas used
 * to live in a separate `customers` table, and a webadmin created from the CLI/API
 * would have no customer row, so the check simply couldn't find one and let every
 * request through unlimited. That hole disappeared along with the table being
 * merged away, not by adding an if-statement.
 *
 * The rules for the numbers themselves live in one place, the Quota class:
 * -1 = unlimited · 0 = disabled · >0 = limited by count
 */
final class QuotaChecker
{
    public function __construct(private readonly UserRepository $users)
    {
    }

    /**
     * Whether the user can create $amount more of this resource
     *
     * Accepts the type name as either singular ('domain') or plural ('domains'),
     * since callers throughout the system have always used one or the other, and
     * that mismatch used to make quota checks reject every single request.
     *
     * @return array{ok:bool,message:string,used:int,limit:int}
     */
    public function canCreate(int $userId, string $type, int $amount = 1): array
    {
        if ($amount <= 0) {
            return ['ok' => false, 'message' => 'Amount must be greater than 0', 'used' => 0, 'limit' => 0];
        }

        $name = Quota::normalize($type);

        // A resource with no quota column at all (e.g. redirect) means "not
        // limited," different from "limited to 0" — failing to tell these apart
        // would mean adding a new type silently breaks the existing ones.
        if ($name === null) {
            return [
                'ok' => true,
                'message' => 'This resource type has no limit',
                'used' => 0,
                'limit' => Quota::UNLIMITED,
            ];
        }

        $quotas = $this->users->quotas($userId);

        if ($quotas === null) {
            return ['ok' => false, 'message' => 'User not found', 'used' => 0, 'limit' => 0];
        }

        $limit = $quotas[$name];
        $used = $this->users->usage($userId)[$name];
        $label = Quota::label($name);

        if (Quota::isUnlimited($limit)) {
            return ['ok' => true, 'message' => 'Can be created', 'used' => $used, 'limit' => $limit];
        }

        if (Quota::isDisabled($limit)) {
            return [
                'ok' => false,
                'message' => "{$label} quota is disabled",
                'used' => $used,
                'limit' => $limit,
            ];
        }

        if ($used + $amount > $limit) {
            return [
                'ok' => false,
                'message' => "{$label} quota is full (using {$used} of {$limit})",
                'used' => $used,
                'limit' => $limit,
            ];
        }

        return ['ok' => true, 'message' => 'Can be created', 'used' => $used, 'limit' => $limit];
    }

    /**
     * Check both service status and quota — the single guard a capability should call
     *
     * An owner of 0 means an unowned resource, so no quota needs to be considered.
     *
     * @return array{ok:bool,message:string,used:int,limit:int}
     */
    public function checkOwnerCanCreate(int $ownerUserId, string $type, int $amount = 1): array
    {
        if ($ownerUserId <= 0) {
            return [
                'ok' => true,
                'message' => 'No owner, so no quota is considered',
                'used' => 0,
                'limit' => Quota::UNLIMITED,
            ];
        }

        // A quota is a business constraint on **the customer**, not on server admins.
        //
        // This has to be decided from the role, not from the stored number, because
        // changing a role later doesn't clear the old numbers — a customer promoted
        // to admin would still be stuck with their old quota, and would have no way
        // to fix it either, since the quota-management path only accepts webadmin
        // accounts (deliberately, to keep sysadmins from touching admin accounts) ·
        // found through testing on a real machine.
        $owner = $this->users->find($ownerUserId);

        if ($owner !== null && $owner['role'] !== Permissions::WEBADMIN) {
            return [
                'ok' => true,
                'message' => 'Admin accounts are not subject to quotas',
                'used' => 0,
                'limit' => Quota::UNLIMITED,
            ];
        }

        $service = $this->users->checkService($ownerUserId);

        if (!$service['ok']) {
            return ['ok' => false, 'message' => $service['message'], 'used' => 0, 'limit' => 0];
        }

        // Disk quota blocks every new resource type, not just the ones that have
        // their own counting column — a new site, a new database, or a new domain
        // all have to write files into this account's home regardless (PLAN-V2
        // Phase E2 — the approach that's actually achievable at the app layer
        // without an OS-level project quota).
        $diskMessage = $this->diskQuotaExceeded($owner);

        if ($diskMessage !== null) {
            $used = (int) ($owner['disk_used_mb'] ?? 0);
            $limit = (int) ($owner['disk_quota_mb'] ?? Quota::UNLIMITED);

            return ['ok' => false, 'message' => $diskMessage, 'used' => $used, 'limit' => $limit];
        }

        return $this->canCreate($ownerUserId, $type, $amount);
    }

    /**
     * A reason message if the disk quota is already exceeded — null if not yet full or unlimited
     *
     * **A limitation worth knowing:** this only blocks "creating a new resource
     * through the panel" — a file the customer's own application writes through
     * their own PHP code never passes through this guard at all. Blocking that
     * would really require a filesystem-level project quota (XFS/ext4), which
     * hasn't been implemented in this phase (see Phase E2's "remaining work" in
     * PLAN-V2.md). This message must therefore never imply "can no longer write
     * files," only "can no longer create something new," which is what the system
     * can actually guarantee.
     *
     * @param array<string,mixed>|null $owner
     */
    private function diskQuotaExceeded(?array $owner): ?string
    {
        if ($owner === null) {
            return null;
        }

        $limit = (int) ($owner['disk_quota_mb'] ?? Quota::UNLIMITED);

        if (Quota::isUnlimited($limit)) {
            return null;
        }

        $used = (int) ($owner['disk_used_mb'] ?? 0);

        if ($used < $limit) {
            return null;
        }

        return sprintf(
            'Disk space is full (using %d MB of %d MB) — delete files or increase the quota before creating a new resource',
            $used,
            $limit,
        );
    }

    /**
     * A summary of every quota a user has, for display and for REST
     *
     * @return array<string,array{used:int,limit:int,label:string,unlimited:bool,disabled:bool}>|null
     */
    public function summary(int $userId): ?array
    {
        $quotas = $this->users->quotas($userId);

        if ($quotas === null) {
            return null;
        }

        $usage = $this->users->usage($userId);
        $summary = [];

        foreach ($quotas as $type => $limit) {
            $summary[$type] = [
                'used' => $usage[$type],
                'limit' => $limit,
                'label' => Quota::label($type),
                'unlimited' => Quota::isUnlimited($limit),
                'disabled' => Quota::isDisabled($limit),
                // A type whose number has no meaning (e.g. SFTP, which always has
                // exactly one account) — the screen shows "on/off" instead of
                // "0 / 5," which would be misleading · the rule lives in the Quota
                // class alone.
                'toggle' => Quota::isToggle($type),
                'enabled' => !Quota::isDisabled($limit),
                'in_use' => $usage[$type] > 0,
            ];
        }

        return $summary;
    }
}
