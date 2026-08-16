<?php

declare(strict_types=1);

namespace Phpcp\Agent\Capability;

use Phpcp\Agent\Context;
use Phpcp\Agent\ValidationError;
use Phpcp\Domain\Quota;
use Phpcp\Domain\QuotaChecker;
use Phpcp\Domain\UserRepository;
use Phpcp\Security\Permissions;

/**
 * The shared base for capabilities that manage hosting accounts (customer.*)
 *
 * Since migration 0005, a "customer" is a row in users with role=webadmin, no
 * longer a separate table — so this group of capabilities works directly on users.
 *
 * **The permission boundary enforced right here:** every one of them loads an
 * account through loadHostingAccount(), which only ever accepts a row with
 * role=webadmin · the result is that someone holding `customer.manage`
 * (sysadmin) can never touch a superadmin/sysadmin account through this path,
 * even guessing the right account id. Managing an admin account requires
 * `user.manage`, a completely separate path. Without this guard right here,
 * merging the two tables into one would have instantly become a privilege
 * escalation for sysadmin.
 *
 * Quota value rules (-1 = unlimited, 0 = disabled) live in the Quota class alone
 * — this just converts its exception type into a ValidationError, so the agent
 * answers "the value sent was invalid" instead of "an internal error occurred".
 */
abstract class CustomerCapability
{
    protected function users(Context $context): UserRepository
    {
        return new UserRepository($context->db);
    }

    protected function quotaChecker(Context $context): QuotaChecker
    {
        return new QuotaChecker($this->users($context));
    }

    /**
     * Validates a quota value — skips null (not sent = not changed)
     *
     * @throws ValidationError
     */
    protected static function assertQuota(?int $value, string $type): void
    {
        if ($value === null) {
            return;
        }

        try {
            Quota::assertValue($value, $type);
        } catch (\InvalidArgumentException $e) {
            throw new ValidationError($e->getMessage());
        }
    }

    /**
     * Loads a hosting account, or rejects outright
     *
     * An account that isn't webadmin answers "not found", the same as one that
     * genuinely doesn't exist, never "access denied" — because saying "it exists
     * but you can't touch it" would confirm to the caller which id belongs to an
     * admin account.
     *
     * @return array<string,mixed>
     *
     * @throws ValidationError
     */
    protected function loadHostingAccount(Context $context, int $userId): array
    {
        $user = $this->users($context)->find($userId);

        if ($user === null || $user['role'] !== Permissions::WEBADMIN) {
            throw new ValidationError("Hosting account {$userId} not found");
        }

        return $user;
    }

    /**
     * A website being transferred already carries its domains/databases with it
     * — does the new owner still have enough quota?
     *
     * This has to be checked right here, not only when adding domains one at a
     * time — a single site can carry a dozen-plus subdomains and aliases at once,
     * so a transfer can blow through quota in a single command.
     *
     * @return array{ok:bool,message:string}
     */
    protected function checkAttachQuota(Context $context, int $userId, int $siteId): array
    {
        $counts = [
            'domains' => (int) $context->db->value(
                'SELECT count(*) FROM domains WHERE site_id = :id',
                ['id' => $siteId],
                0,
            ),
            'subdomains' => (int) $context->db->value(
                "SELECT count(*) FROM domains WHERE site_id = :id AND type = 'subdomain'",
                ['id' => $siteId],
                0,
            ),
            'aliases' => (int) $context->db->value(
                "SELECT count(*) FROM domains WHERE site_id = :id AND type = 'alias'",
                ['id' => $siteId],
                0,
            ),
            'databases' => (int) $context->db->value(
                'SELECT count(*) FROM databases_ WHERE site_id = :id',
                ['id' => $siteId],
                0,
            ),
        ];

        $quotaChecker = $this->quotaChecker($context);

        foreach ($counts as $type => $amount) {
            if ($amount <= 0) {
                continue;
            }

            $result = $quotaChecker->canCreate($userId, $type, $amount);

            if (!$result['ok']) {
                return ['ok' => false, 'message' => $result['message']];
            }
        }

        return ['ok' => true, 'message' => ''];
    }
}
