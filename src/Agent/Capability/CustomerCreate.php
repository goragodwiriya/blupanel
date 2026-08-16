<?php

declare(strict_types=1);

namespace Phpcp\Agent\Capability;

use Phpcp\Agent\Capability;
use Phpcp\Agent\Context;
use Phpcp\Agent\Executor\Executor;
use Phpcp\Agent\ValidationError;
use Phpcp\Domain\UserRepository;
use Phpcp\Support\Validator;

/**
 * Creates a new hosting account — a role=webadmin user with its own quota
 *
 * Since migration 0005, a customer is no longer a separate table, but the same
 * row used to log in — so there are no longer two sets of passwords that can
 * drift out of sync, and no two id systems to convert between.
 */
final class CustomerCreate extends CustomerCapability implements Capability
{
    public static function name(): string
    {
        return 'customer.create';
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
        return 'Create new customer with quota';
    }

    public function validate(array $args): array
    {
        // The username becomes a real Linux account name and home folder in
        // Phase M3 — so the rule lives in one place, UserRepository, not
        // duplicated as a regex in each capability
        $username = Validator::requireString($args, 'username', 32);
        try {
            UserRepository::assertUsername($username);
        } catch (\InvalidArgumentException $e) {
            throw new ValidationError($e->getMessage());
        }

        // Validates the password
        $password = Validator::requireString($args, 'password', 256);
        if (mb_strlen($password) < 8) {
            throw new ValidationError('Password must be at least 8 characters long');
        }

        // Validates the email
        $email = Validator::requireString($args, 'email', 256);
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new ValidationError('Invalid email');
        }

        // Validates the display name (optional)

        // Validates quota (optional, has a database default)
        $quotaDomains = Validator::optionalInt($args, 'quota_domains', 10);
        $quotaSubdomains = Validator::optionalInt($args, 'quota_subdomains', 20);
        $quotaAliases = Validator::optionalInt($args, 'quota_aliases', 50);
        $quotaEmails = Validator::optionalInt($args, 'quota_emails', 100);
        $quotaDatabases = Validator::optionalInt($args, 'quota_databases', 10);
        $quotaFtpUsers = Validator::optionalInt($args, 'quota_ftp_users', 5);

        // Validates expiry_at (optional)
        $expiryAt = null;
        if (isset($args['expiry_at']) && $args['expiry_at'] !== null) {
            $expiryAt = Validator::requireInt($args, 'expiry_at', 0);
            if ($expiryAt < time()) {
                throw new ValidationError('The expiry date must be a time in the future');
            }
        }

        // Quota follows the same rule as everywhere else in the system: -1 =
        // unlimited · 0 = disabled · >0 = limited. This used to reject every
        // negative value, making it impossible to set "unlimited" through this
        // path even though the CLI and the repository both support it — this
        // kind of inconsistency is exactly where hard-to-find bugs come from
        self::assertQuota($quotaDomains, 'domains');
        self::assertQuota($quotaSubdomains, 'subdomains');
        self::assertQuota($quotaAliases, 'aliases');
        self::assertQuota($quotaEmails, 'emails');
        self::assertQuota($quotaDatabases, 'databases');
        self::assertQuota($quotaFtpUsers, 'ftp_users');

        // Disk is measured in MB, not item count, so it isn't in Quota::TYPES —
        // validated separately, the same way as CustomerQuotaUpdate (PLAN-V2 Phase E2)
        $diskQuotaMb = Validator::optionalInt($args, 'disk_quota_mb', 10240);
        if ($diskQuotaMb < -1) {
            throw new ValidationError('Disk space quota must be -1 (unlimited) or a non-negative integer (MB)');
        }

        return [
            'username' => $username,
            'password' => $password,
            'email' => $email,
            'quota_domains' => $quotaDomains,
            'quota_subdomains' => $quotaSubdomains,
            'quota_aliases' => $quotaAliases,
            'quota_emails' => $quotaEmails,
            'quota_databases' => $quotaDatabases,
            'quota_ftp_users' => $quotaFtpUsers,
            'disk_quota_mb' => $diskQuotaMb,
            'expiry_at' => $expiryAt,
            // A system-generated password must always be changed on first login
            // — the caller states whether it was generated or set by an admin,
            // since this layer can't tell the difference itself
            'must_change_password' => (bool) ($args['must_change_password'] ?? false),
        ];
    }

    public function run(array $args, Executor $executor, Context $context): array
    {
        $users = $this->users($context);

        if ($users->findByUsername($args['username']) !== null) {
            throw new ValidationError("User {$args['username']} already exists");
        }

        // The repository checks again at its own layer and throws
        // InvalidArgumentException — it has to be converted to ValidationError
        // right here, or the agent would answer "an internal error occurred" to
        // a user who just mistyped an email, with nobody knowing what to fix
        try {
            $userId = $users->createHostingAccount(
                $args['username'],
                $args['password'],
                $args['email'],
                [
                    'domains' => $args['quota_domains'],
                    'subdomains' => $args['quota_subdomains'],
                    'aliases' => $args['quota_aliases'],
                    'emails' => $args['quota_emails'],
                    'databases' => $args['quota_databases'],
                    'ftp_users' => $args['quota_ftp_users'],
                ],
                $args['expiry_at'],
                $args['must_change_password'],
            );

            $users->updateDiskQuota($userId, $args['disk_quota_mb']);
        } catch (\InvalidArgumentException $e) {
            throw new ValidationError($e->getMessage());
        }

        // The audit log is already written by Dispatcher around every run() call (ARCHITECTURE §4.1)
        return [
            'id' => $userId,
            'username' => $args['username'],
            'email' => $args['email'],
            'quota_domains' => $args['quota_domains'],
            'quota_subdomains' => $args['quota_subdomains'],
            'quota_aliases' => $args['quota_aliases'],
            'quota_emails' => $args['quota_emails'],
            'quota_databases' => $args['quota_databases'],
            'quota_ftp_users' => $args['quota_ftp_users'],
            'disk_quota_mb' => $args['disk_quota_mb'],
            'expiry_at' => $args['expiry_at'],
            'must_change_password' => $args['must_change_password'],
            'message' => "Created hosting account {$args['username']}",
        ];
    }
}
