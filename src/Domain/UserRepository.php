<?php

declare(strict_types=1);

namespace Phpcp\Domain;

use Phpcp\Kernel\Db;
use Phpcp\Security\Password;
use Phpcp\Security\Permissions;

/**
 * A panel user — an entirely different thing from a Linux system user
 *
 * A user here is someone who logs into the web page; the system user (web_17) is the
 * owner of the site's files. PROMPT.md already draws this distinction clearly, and
 * the code must follow it too.
 */
final class UserRepository
{
    public function __construct(private readonly Db $db)
    {
    }

    /** @return array<string,mixed>|null */
    public function find(int $id): ?array
    {
        return $this->db->first('SELECT * FROM users WHERE id = :id', ['id' => $id]);
    }

    /** @return array<string,mixed>|null */
    public function findByUsername(string $username): ?array
    {
        return $this->db->first('SELECT * FROM users WHERE username = :u', ['u' => $username]);
    }

    /** @return list<array<string,mixed>> */
    public function all(): array
    {
        return $this->db->all('SELECT * FROM users ORDER BY role, username');
    }

    public function count(): int
    {
        return (int) $this->db->value('SELECT count(*) FROM users');
    }

    public function countByRole(string $role): int
    {
        return (int) $this->db->value('SELECT count(*) FROM users WHERE role = :r AND status = \'active\'', ['r' => $role]);
    }

    public function create(
        string $username,
        string $plainPassword,
        string $role,
        bool $mustChangePassword = false,
    ): int {
        if (!Permissions::isValidRole($role)) {
            throw new \InvalidArgumentException("Invalid role: {$role}");
        }

        $now = time();

        return $this->db->insert('users', [
            'username' => $username,
            'password_hash' => Password::hash($plainPassword),
            'role' => $role,
            'must_change_password' => $mustChangePassword ? 1 : 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function setPassword(int $userId, string $plainPassword, bool $clearMustChange = true): void
    {
        $data = [
            'password_hash' => Password::hash($plainPassword),
            'updated_at' => time(),
        ];

        if ($clearMustChange) {
            $data['must_change_password'] = 0;
        }

        $this->db->update('users', $data, ['id' => $userId]);
    }

    /** true = the account is temporarily locked out from repeated failed logins */
    public function isLocked(array $user): bool
    {
        $until = $user['locked_until'] ?? null;

        return $until !== null && (int) $until > time();
    }

    public function lockRemaining(array $user): int
    {
        $until = (int) ($user['locked_until'] ?? 0);

        return max(0, $until - time());
    }

    /**
     * Record a failed login, and lock the account once the attempt count is reached
     * The lockout duration grows exponentially, so sustained password guessing gets
     * progressively more expensive.
     */
    public function registerFailure(int $userId, int $maxAttempts, int $lockSeconds): void
    {
        $user = $this->find($userId);
        if ($user === null) {
            return;
        }

        $attempts = (int) $user['failed_attempts'] + 1;
        $data = ['failed_attempts' => $attempts, 'updated_at' => time()];

        if ($attempts >= $maxAttempts) {
            $rounds = intdiv($attempts, max(1, $maxAttempts));
            $multiplier = min(8, 2 ** max(0, $rounds - 1));
            $data['locked_until'] = time() + $lockSeconds * $multiplier;
        }

        $this->db->update('users', $data, ['id' => $userId]);
    }

    /**
     * Record which 2FA time-step has already been used — the same code must not be reusable
     *
     * Stores the time-step number, not the code itself · the number only ever moves
     * forward, so comparison is simple and nothing extra needs to be kept secret
     * ({@see \Phpcp\Security\Totp::verifyAt()})
     */
    public function recordTotpCounter(int $userId, int $counter): void
    {
        $this->db->update(
            'users',
            ['totp_last_counter' => $counter, 'updated_at' => time()],
            ['id' => $userId],
        );
    }

    public function registerSuccess(int $userId, string $ip, string $currentHash): void
    {
        $data = [
            'failed_attempts' => 0,
            'locked_until' => null,
            'last_login_at' => time(),
            'last_login_ip' => $ip,
            'updated_at' => time(),
        ];

        // Rehash if the Argon2id parameters have changed since last time
        if (Password::needsRehash($currentHash)) {
            $data['password_hash'] = $currentHash;
        }

        $this->db->update('users', $data, ['id' => $userId]);
    }

    public function setRole(int $userId, string $role): void
    {
        if (!Permissions::isValidRole($role)) {
            throw new \InvalidArgumentException("Invalid role: {$role}");
        }

        $this->db->update('users', ['role' => $role, 'updated_at' => time()], ['id' => $userId]);
    }

    public function setStatus(int $userId, string $status): void
    {
        $this->db->update('users', ['status' => $status, 'updated_at' => time()], ['id' => $userId]);
    }

    public function enableTotp(int $userId, string $encryptedSecret): void
    {
        $this->db->update('users', [
            'totp_secret' => $encryptedSecret,
            'totp_enabled' => 1,
            'updated_at' => time(),
        ], ['id' => $userId]);
    }

    public function disableTotp(int $userId): void
    {
        $this->db->update('users', [
            'totp_secret' => null,
            'totp_enabled' => 0,
            'updated_at' => time(),
        ], ['id' => $userId]);

        $this->db->run('DELETE FROM totp_recovery_codes WHERE user_id = :u', ['u' => $userId]);
    }

    /** @param list<string> $codes */
    public function storeRecoveryCodes(int $userId, array $codes): void
    {
        $this->db->run('DELETE FROM totp_recovery_codes WHERE user_id = :u', ['u' => $userId]);

        foreach ($codes as $code) {
            $this->db->insert('totp_recovery_codes', [
                'user_id' => $userId,
                'code_hash' => Password::hash($code),
            ]);
        }
    }

    /** Consume one recovery code, returns true if it was valid */
    public function consumeRecoveryCode(int $userId, string $code): bool
    {
        $rows = $this->db->all(
            'SELECT id, code_hash FROM totp_recovery_codes WHERE user_id = :u AND used_at IS NULL',
            ['u' => $userId],
        );

        foreach ($rows as $row) {
            if (Password::verify($code, (string) $row['code_hash'])) {
                $this->db->update('totp_recovery_codes', ['used_at' => time()], ['id' => (int) $row['id']]);

                return true;
            }
        }

        return false;
    }

    /**
     * There must never be fewer than 1 active superadmin left
     * Check before deleting, disabling, or demoting an account (SECURITY §2.5)
     */
    public function wouldRemoveLastSuperadmin(int $userId): bool
    {
        $user = $this->find($userId);
        if ($user === null || $user['role'] !== Permissions::SUPERADMIN || $user['status'] !== 'active') {
            return false;
        }

        return $this->countByRole(Permissions::SUPERADMIN) <= 1;
    }

    public function delete(int $userId): void
    {
        $this->db->run('DELETE FROM users WHERE id = :id', ['id' => $userId]);
    }

    // =========================================================================
    // Hosting accounts — used to be a separate `customers` table
    //
    // Since migration 0005, a customer is the same `users` row used for login, no
    // longer a parallel table — so there's no second password_hash, no second status
    // that can contradict the first, and no more converting customer_id ↔ user_id
    // back and forth throughout the system.
    // =========================================================================

    /**
     * Usernames that are off-limits, because they become real Linux account names and
     * home folders in Phase M3
     *
     * This is only the first guard — the guard that actually matters is the agent
     * checking `getent passwd` before creating an account, because a hardcoded list
     * will always miss an account an admin created by hand afterward.
     *
     * @var list<string>
     */
    public const RESERVED_USERNAMES = [
        'root', 'daemon', 'bin', 'sys', 'sync', 'games', 'man', 'lp', 'mail', 'news',
        'uucp', 'proxy', 'www-data', 'backup', 'list', 'irc', 'gnats', 'nobody',
        'systemd', 'syslog', 'messagebus', 'sshd', 'ssh', 'mysql', 'mariadb', 'postgres',
        'redis', 'postfix', 'dovecot', 'bind', 'named', 'ftp', 'nginx', 'apache', 'http',
        'phpcp', 'phpcp-agent', 'panel', 'admin-panel', 'test', 'guest',
    ];

    /**
     * Validate a username against rules safe enough for it to become a Linux account name
     *
     * Three ways this is stricter than a plain username: lowercase only (the
     * filesystem and MariaDB don't treat case the same way), no dots allowed (breaks
     * how chown, quota, and mail tools interpret it), and can't collide with a system name.
     *
     * @throws \InvalidArgumentException
     */
    public static function assertUsername(string $username): void
    {
        if (preg_match('/^[a-z][a-z0-9_-]{2,31}$/', $username) !== 1) {
            throw new \InvalidArgumentException(
                'Username must be 3-32 characters, start with a lowercase letter, and use only a-z 0-9 _ -',
            );
        }

        if (in_array($username, self::RESERVED_USERNAMES, true)) {
            throw new \InvalidArgumentException("The name \"{$username}\" is reserved for the system");
        }
    }

    /**
     * Create a hosting account (role=webadmin, with quotas)
     *
     * @param array<string,int> $quotas resource type → amount (omitted = use the table's default)
     *
     * @throws \InvalidArgumentException
     */
    public function createHostingAccount(
        string $username,
        string $plainPassword,
        string $email,
        array $quotas = [],
        ?int $expiryAt = null,
        bool $mustChangePassword = false,
    ): int {
        self::assertUsername($username);

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('Invalid email');
        }

        if ($this->findByUsername($username) !== null) {
            throw new \InvalidArgumentException('A user with this name already exists');
        }

        if ($expiryAt !== null && $expiryAt < time()) {
            throw new \InvalidArgumentException('Expiry date must be in the future');
        }

        $now = time();
        $row = [
            'username' => $username,
            'password_hash' => Password::hash($plainPassword),
            'role' => Permissions::WEBADMIN,
            'email' => $email,
            'must_change_password' => $mustChangePassword ? 1 : 0,
            'status' => 'active',
            'service_status' => 'active',
            'expiry_at' => $expiryAt,
            'created_at' => $now,
            'updated_at' => $now,
        ];

        foreach ($quotas as $type => $value) {
            Quota::assertValue($value, $type);
            $row[Quota::column($type)] = $value;
        }

        return $this->db->insert('users', $row);
    }

    /**
     * @return list<array<string,mixed>> all hosting accounts, with the number of sites each owns
     */
    public function hostingAccounts(): array
    {
        return $this->db->all("
            SELECT u.*, (SELECT count(*) FROM sites s WHERE s.owner_user_id = u.id) AS site_count
            FROM users u
            WHERE u.role = :role
            ORDER BY u.created_at DESC
        ", ['role' => Permissions::WEBADMIN]);
    }

    public function countByServiceStatus(string $status): int
    {
        return (int) $this->db->value(
            'SELECT count(*) FROM users WHERE role = :role AND service_status = :s',
            ['role' => Permissions::WEBADMIN, 's' => $status],
        );
    }

    /** Number of accounts expiring within the given number of days ahead */
    public function countExpiring(int $daysBefore): int
    {
        $now = time();

        return (int) $this->db->value("
            SELECT count(*) FROM users
            WHERE service_status = 'active'
              AND expiry_at IS NOT NULL
              AND expiry_at <= :expiry
              AND expiry_at > :now
        ", ['expiry' => $now + ($daysBefore * 86400), 'now' => $now]);
    }

    /**
     * Edit the display name and email
     *
     * **Email can be empty, and that means "no email," not an invalid value** —
     * accounts created via `phpcp user:create` have no email, and the table doesn't
     * require one · this used to validate the format even against an empty value,
     * which meant an account with no email **couldn't have its display name edited at
     * all**, because `UsersController::update()` passes the existing (empty) email
     * back in as the default.
     */
    public function updateProfile(int $userId, string $email): void
    {
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('Invalid email');
        }

        $this->db->update('users', ['email' => $email, 'updated_at' => time()], ['id' => $userId]);
    }

    /**
     * @param array<string,int|null> $quotas resource type → amount (null = don't change)
     *
     * @throws \InvalidArgumentException
     */
    public function updateQuota(int $userId, array $quotas): void
    {
        $data = [];

        foreach ($quotas as $type => $value) {
            if ($value === null) {
                continue;
            }

            Quota::assertValue($value, $type);
            $data[Quota::column($type)] = $value;
        }

        if ($data === []) {
            return;
        }

        $data['updated_at'] = time();
        $this->db->update('users', $data, ['id' => $userId]);
    }

    /** @throws \InvalidArgumentException */
    public function updateExpiry(int $userId, ?int $expiryAt): void
    {
        if ($expiryAt !== null && $expiryAt < time()) {
            throw new \InvalidArgumentException('Expiry date must be in the future, or null');
        }

        $this->db->update('users', ['expiry_at' => $expiryAt, 'updated_at' => time()], ['id' => $userId]);
    }

    /**
     * Change the hosting service status — a separate axis from `status`, which controls login access
     *
     * Deliberately allows a suspended account to still log in to see why and renew —
     * something the previous behavior couldn't do, because setStatus() always disabled
     * login too.
     *
     * @throws \InvalidArgumentException
     */
    public function setServiceStatus(int $userId, string $status): void
    {
        if (!in_array($status, ['active', 'suspended', 'expired'], true)) {
            throw new \InvalidArgumentException('Invalid service status');
        }

        $this->db->update('users', ['service_status' => $status, 'updated_at' => time()], ['id' => $userId]);
    }

    /**
     * Force a password change on the next login
     *
     * Used when the system generated a random password and displayed it on screen —
     * a password that's passed through a middleman's eyes needs the shortest possible lifespan.
     */
    public function requirePasswordChange(int $userId): bool
    {
        return $this->db->update(
            'users',
            ['must_change_password' => 1, 'updated_at' => time()],
            ['id' => $userId],
        ) > 0;
    }

    /** @return list<int> ids of the sites the user owns */
    public function siteIds(int $userId): array
    {
        $rows = $this->db->all(
            'SELECT id FROM sites WHERE owner_user_id = :u ORDER BY id',
            ['u' => $userId],
        );

        return array_map(intval(...), array_column($rows, 'id'));
    }

    /**
     * All of a user's sites, along with the owner's system account name
     *
     * The owner's name must always be included, since the caller uses it to build file paths.
     *
     * @return list<array<string,mixed>>
     */
    public function sites(int $userId): array
    {
        return $this->db->all(
            'SELECT s.*, u.username AS owner_username, u.system_user AS owner_system_user
             FROM sites s JOIN users u ON u.id = s.owner_user_id
             WHERE s.owner_user_id = :u ORDER BY s.created_at DESC',
            ['u' => $userId],
        );
    }

    /**
     * The user's configured quotas
     *
     * @return array<string,int>|null null = user not found
     */
    public function quotas(int $userId): ?array
    {
        $user = $this->find($userId);

        if ($user === null) {
            return null;
        }

        $quotas = [];
        foreach (Quota::TYPES as $type => [$column]) {
            $quotas[$type] = (int) $user[$column];
        }

        return $quotas;
    }

    /**
     * The amount of resources the user has already used
     *
     * Counted directly from sites.owner_user_id — this used to go through a
     * customer_sites table, which was a separate source of truth from owner_user_id
     * and actually contradicted it in real data.
     *
     * @return array<string,int>
     */
    public function usage(int $userId): array
    {
        $siteIds = $this->siteIds($userId);

        $usage = array_fill_keys(array_keys(Quota::TYPES), 0);

        // SFTP is counted from the account's actual status, not a separate table — a
        // hosting account has always had exactly one system account since Phase E4 ·
        // this must be counted before returning early when there are no sites, since
        // this status doesn't depend on site count (even though SFTP can only be
        // enabled once a system account exists, which happens when the first site is created)
        $usage['ftp_users'] = (int) $this->db->value(
            'SELECT sftp_enabled FROM users WHERE id = :id',
            ['id' => $userId],
            0,
        ) > 0 ? 1 : 0;

        if ($siteIds === []) {
            return $usage;
        }

        $in = implode(',', array_fill(0, count($siteIds), '?'));

        $usage['domains'] = (int) $this->db->value("SELECT count(*) FROM domains WHERE site_id IN ($in)", $siteIds, 0);
        $usage['subdomains'] = (int) $this->db->value(
            "SELECT count(*) FROM domains WHERE site_id IN ($in) AND type = 'subdomain'",
            $siteIds,
            0,
        );
        $usage['aliases'] = (int) $this->db->value(
            "SELECT count(*) FROM domains WHERE site_id IN ($in) AND type = 'alias'",
            $siteIds,
            0,
        );
        $usage['databases'] = (int) $this->db->value(
            "SELECT count(*) FROM databases_ WHERE site_id IN ($in)",
            $siteIds,
            0,
        );

        // Mailboxes are counted from the real table since PLAN-MAIL Phase M2 — before
        // that, this value was hardcoded to 0, so the quota field on the customer's
        // page always showed a number that meant nothing.
        $usage['emails'] = (int) $this->db->value(
            "SELECT count(*)
               FROM mailboxes m
               JOIN domains d ON d.id = m.domain_id
              WHERE d.site_id IN ($in)",
            $siteIds,
            0,
        );

        // SFTP was already counted above, from `sftp_enabled`
        return $usage;
    }

    /**
     * Whether the user's service is still usable
     *
     * @return array{ok:bool,message:string}
     */
    public function checkService(int $userId): array
    {
        $user = $this->find($userId);

        if ($user === null) {
            return ['ok' => false, 'message' => 'User not found'];
        }

        if ($user['service_status'] === 'expired') {
            return ['ok' => false, 'message' => 'Account has expired'];
        }

        if ($user['service_status'] === 'suspended') {
            return ['ok' => false, 'message' => 'Account is temporarily suspended'];
        }

        if ($user['expiry_at'] !== null && (int) $user['expiry_at'] < time()) {
            return ['ok' => false, 'message' => 'Expiry date has passed'];
        }

        return ['ok' => true, 'message' => 'Active'];
    }

    /** true = a new notification was recorded · false = this round was already notified */
    public function recordExpiryNotification(int $userId, int $daysBefore): bool
    {
        $existing = $this->db->first(
            'SELECT id FROM expiry_notifications WHERE user_id = :u AND days_before = :d',
            ['u' => $userId, 'd' => $daysBefore],
        );

        if ($existing !== null) {
            return false;
        }

        $this->db->insert('expiry_notifications', [
            'user_id' => $userId,
            'days_before' => $daysBefore,
            'notified_at' => time(),
        ]);

        return true;
    }

    /** @return list<array<string,mixed>> accounts that should be notified their expiry is approaching */
    public function findExpiring(int $daysBefore): array
    {
        $now = time();

        return $this->db->all("
            SELECT * FROM users
            WHERE service_status = 'active'
              AND expiry_at IS NOT NULL
              AND expiry_at <= :expiry
              AND expiry_at > :now
            ORDER BY expiry_at
        ", ['expiry' => $now + ($daysBefore * 86400), 'now' => $now]);
    }

    /**
     * The most recent disk quota threshold already notified (0/80/90/100) — 0 = never notified
     *
     * Differs from expiry_notifications in that disk usage can go up and down, so only
     * the single most recent value is kept, not a history of every notification —
     * full reasoning in migration 0011's comment.
     */
    public function diskQuotaThreshold(int $userId): int
    {
        return (int) $this->db->value(
            'SELECT last_threshold FROM disk_quota_state WHERE user_id = :u',
            ['u' => $userId],
            0,
        );
    }

    /**
     * Record the most recently checked threshold — called every time `quota.disk_check`
     * runs, whether the level went up or down, so `diskQuotaThreshold()` always
     * reflects the current state.
     */
    public function recordDiskQuotaThreshold(int $userId, int $threshold): void
    {
        $now = time();

        $this->db->run(
            'INSERT INTO disk_quota_state (user_id, last_threshold, checked_at, updated_at)
             VALUES (:u, :t, :now, :now)
             ON CONFLICT(user_id) DO UPDATE SET last_threshold = :t, checked_at = :now, updated_at = :now',
            ['u' => $userId, 't' => $threshold, 'now' => $now],
        );
    }

    /**
     * Edit the disk space quota — separate from updateQuota() because disk is measured
     * in MB, not item count, so it's not in Quota::TYPES (which has a "can't be 0" rule
     * for certain types that has nothing to do with disk at all).
     *
     * @throws \InvalidArgumentException
     */
    public function updateDiskQuota(int $userId, int $quotaMb): void
    {
        if ($quotaMb < Quota::UNLIMITED) {
            throw new \InvalidArgumentException('Disk quota must be -1 (unlimited) or a non-negative integer (MB)');
        }

        $this->db->update('users', ['disk_quota_mb' => $quotaMb, 'updated_at' => time()], ['id' => $userId]);
    }
}
