<?php

declare (strict_types = 1);

namespace Phpcp\Agent\Capability;

use Phpcp\Agent\Capability;
use Phpcp\Agent\Context;
use Phpcp\Agent\Executor\Executor;
use Phpcp\Agent\ValidationError;
use Phpcp\Domain\Quota;
use Phpcp\Support\Validator;

/**
 * Updates a hosting account's quota — an admin can adjust quota per individual account
 *
 * Used when:
 * - a customer requests more quota (needing another domain, say)
 * - a customer reduces quota (to cut costs)
 * - testing, or granting a temporary special quota
 */
final class CustomerQuotaUpdate extends CustomerCapability implements Capability
{
    /** arg name (matches the column name) => resource type as the Quota class knows it */
    private const QUOTA_FIELDS = [
        'quota_domains' => 'domains',
        'quota_subdomains' => 'subdomains',
        'quota_aliases' => 'aliases',
        'quota_emails' => 'emails',
        'quota_databases' => 'databases',
        'quota_ftp_users' => 'ftp_users',
    ];

    public static function name(): string
    {
        return 'customer.quota_update';
    }

    public function permission(): string
    {
        return 'customer.manage';
    }

    public function isMutating(): bool
    {
        return true;
    }

    public function summary(): string
    {
        return 'Update customer quota';
    }

    /**
     * @param array $args
     */
    public function validate(array $args): array
    {
        $clean = ['user_id' => Validator::requireInt($args, 'user_id', 1)];
        $given = 0;

        foreach (self::QUOTA_FIELDS as $field => $type) {
            // null = not sent, so unchanged — different from 0, which means "disabled"
            $value = Validator::nullableInt($args, $field);

            // The same rule everywhere in the system: -1 = unlimited · 0 = disabled · >0 = limited (domains can't be 0)
            self::assertQuota($value, $type);

            $clean[$field] = $value;
            $given += $value === null ? 0 : 1;
        }

        // Disk quota isn't in Quota::TYPES (measured in MB, not item count, no
        // rule against 0), so it's checked separately and passed through
        // user_id to UserRepository::updateDiskQuota() during run()
        $diskQuotaMb = Validator::nullableInt($args, 'disk_quota_mb');

        if ($diskQuotaMb !== null && $diskQuotaMb < -1) {
            throw new ValidationError('Disk space quota must be -1 (unlimited) or a non-negative integer (MB)');
        }

        $clean['disk_quota_mb'] = $diskQuotaMb;
        $given += $diskQuotaMb === null ? 0 : 1;

        if ($given === 0) {
            throw new ValidationError('At least one quota to update must be specified');
        }

        return $clean;
    }

    /**
     * @param array $args
     * @param Executor $executor
     * @param Context $context
     */
    public function run(array $args, Executor $executor, Context $context): array
    {
        $users = $this->users($context);
        $before = $this->loadHostingAccount($context, $args['user_id']);

        $quotas = [];
        foreach (self::QUOTA_FIELDS as $field => $type) {
            $quotas[$type] = $args[$field];
        }

        try {
            $users->updateQuota($args['user_id'], $quotas);

            if ($args['disk_quota_mb'] !== null) {
                $users->updateDiskQuota($args['user_id'], $args['disk_quota_mb']);
            }
        } catch (\InvalidArgumentException $e) {
            throw new ValidationError($e->getMessage());
        }

        // **The package no longer includes SFTP, so access left open must be
        // revoked immediately**
        //
        // Otherwise an account that previously had SFTP enabled could keep
        // logging into the machine even though the package no longer includes
        // it, and the screen would even hide the disable button
        // (`sftp_available` is false), making it impossible to turn off from the
        // web page at all — an unrevokable privilege · found while auditing FTP
        // quota consistency (2026-08-10)
        $sftpRevoked = false;

        if ($args['quota_ftp_users'] !== null
            && Quota::isDisabled($args['quota_ftp_users'])
            && (int) ($before['sftp_enabled'] ?? 0) === 1) {
            (new SftpDisable())->run(['user_id' => $args['user_id']], $executor, $context);
            $sftpRevoked = true;
        }

        $updated = $users->find($args['user_id']);

        // Summarizes what changed from what to what — this goes into the audit
        // log through Dispatcher · "domain quota from 10 to 3" is far more
        // useful during a later investigation than just the new value alone
        $changes = [];
        foreach (array_keys(self::QUOTA_FIELDS) as $field) {
            if ($args[$field] !== null) {
                $changes[$field] = ['from' => (int) $before[$field], 'to' => $args[$field]];
            }
        }
        if ($args['disk_quota_mb'] !== null) {
            // disk_quota_mb can be NULL (the column has no DEFAULT) — must be
            // read as "unlimited", the same way QuotaChecker interprets it, not
            // let (int) null turn into 0 in the audit log
            $changes['disk_quota_mb'] = ['from' => (int) ($before['disk_quota_mb'] ?? -1), 'to' => $args['disk_quota_mb']];
        }

        // The audit log is already written by Dispatcher around every run() call (ARCHITECTURE §4.1)
        return [
            'id' => $updated['id'],
            'username' => $updated['username'],
            'quota_domains' => (int) $updated['quota_domains'],
            'quota_subdomains' => (int) $updated['quota_subdomains'],
            'quota_aliases' => (int) $updated['quota_aliases'],
            'quota_emails' => (int) $updated['quota_emails'],
            'quota_databases' => (int) $updated['quota_databases'],
            'quota_ftp_users' => (int) $updated['quota_ftp_users'],
            'disk_quota_mb' => (int) ($updated['disk_quota_mb'] ?? -1),
            'sftp_revoked' => $sftpRevoked,
            'changes' => $changes,
            // Must state clearly that access was also revoked, not just
            // "quota updated" — the admin needs to know the customer can no
            // longer reach SFTP as of this second
            'message' => $sftpRevoked
                ? "Updated quota for {$before['username']} and revoked SFTP access left open"
                : "Updated quota for {$before['username']}",
        ];
    }
}
