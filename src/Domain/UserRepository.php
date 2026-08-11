<?php

declare(strict_types=1);

namespace Phpcp\Domain;

use Phpcp\Kernel\Db;
use Phpcp\Security\Password;
use Phpcp\Security\Permissions;

/**
 * ผู้ใช้ของ panel — คนละเรื่องกับ system user ของ Linux โดยสิ้นเชิง
 *
 * ผู้ใช้ที่นี่คือคนที่ล็อกอินเข้าหน้าเว็บ ส่วน system user (web_17) คือเจ้าของไฟล์เว็บไซต์
 * PROMPT.md แยกสองอย่างนี้ไว้ชัดเจนแล้ว และโค้ดก็ต้องแยกตาม
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
        string $displayName = '',
        bool $mustChangePassword = false,
    ): int {
        if (!Permissions::isValidRole($role)) {
            throw new \InvalidArgumentException("บทบาทไม่ถูกต้อง: {$role}");
        }

        $now = time();

        return $this->db->insert('users', [
            'username' => $username,
            'display_name' => $displayName !== '' ? $displayName : $username,
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

    /** true = บัญชีถูกล็อกชั่วคราวจากการล็อกอินผิดซ้ำ ๆ */
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
     * บันทึกการล็อกอินผิด และล็อกบัญชีเมื่อครบจำนวนครั้ง
     * ระยะเวลาล็อกเพิ่มขึ้นแบบทวีคูณเพื่อให้การเดารหัสแบบต่อเนื่องแพงขึ้นเรื่อย ๆ
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

    public function registerSuccess(int $userId, string $ip, string $currentHash): void
    {
        $data = [
            'failed_attempts' => 0,
            'locked_until' => null,
            'last_login_at' => time(),
            'last_login_ip' => $ip,
            'updated_at' => time(),
        ];

        // แฮชใหม่ถ้าพารามิเตอร์ Argon2id เปลี่ยนไปตั้งแต่ครั้งก่อน
        if (Password::needsRehash($currentHash)) {
            $data['password_hash'] = $currentHash;
        }

        $this->db->update('users', $data, ['id' => $userId]);
    }

    public function setRole(int $userId, string $role): void
    {
        if (!Permissions::isValidRole($role)) {
            throw new \InvalidArgumentException("บทบาทไม่ถูกต้อง: {$role}");
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

    /** ใช้รหัสสำรอง 1 ครั้ง คืน true ถ้าใช้ได้ */
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
     * ห้ามเหลือผู้ดูแลระบบที่ใช้งานได้น้อยกว่า 1 คน
     * ตรวจก่อนลบ ปิดบัญชี หรือลดบทบาท (SECURITY §2.5)
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
    // บัญชีโฮสติ้ง — เดิมเป็นตาราง customers แยกต่างหาก
    //
    // ตั้งแต่ migration 0005 ลูกค้าคือ users แถวเดียวกับที่ใช้ล็อกอิน ไม่ใช่ตารางคู่ขนาน
    // อีกต่อไป จึงไม่มี password_hash สองชุด ไม่มีสถานะสองชุดที่ขัดกันเอง และไม่ต้อง
    // แปลง customer_id ↔ user_id ตามจุดต่าง ๆ ทั่วระบบอีก
    // =========================================================================

    /**
     * ชื่อผู้ใช้ที่ห้ามใช้ เพราะจะกลายเป็นชื่อบัญชี Linux และโฟลเดอร์บ้านจริง ๆ ในเฟส M3
     *
     * นี่เป็นด่านแรกเท่านั้น ด่านที่เชื่อถือได้จริงคือ agent ตรวจ `getent passwd` ก่อนสร้าง
     * บัญชี เพราะรายชื่อที่เขียนตายตัวย่อมตกหล่นบัญชีที่ผู้ดูแลสร้างเองทีหลัง
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
     * ตรวจชื่อผู้ใช้ตามกฎที่ปลอดภัยพอจะเอาไปเป็นชื่อบัญชี Linux
     *
     * เข้มกว่าเดิมสามอย่าง: พิมพ์เล็กล้วน (ระบบไฟล์และ MariaDB ปฏิบัติกับตัวพิมพ์ไม่เหมือนกัน),
     * ห้ามมีจุด (ทำให้ chown, quota และเครื่องมือจัดการเมลตีความผิด) และห้ามชนชื่อระบบ
     *
     * @throws \InvalidArgumentException
     */
    public static function assertUsername(string $username): void
    {
        if (preg_match('/^[a-z][a-z0-9_-]{2,31}$/', $username) !== 1) {
            throw new \InvalidArgumentException(
                'ชื่อผู้ใช้ต้องยาว 3-32 ตัว ขึ้นต้นด้วยตัวอักษรพิมพ์เล็ก ใช้ได้เฉพาะ a-z 0-9 _ -',
            );
        }

        if (in_array($username, self::RESERVED_USERNAMES, true)) {
            throw new \InvalidArgumentException("ชื่อ \"{$username}\" ถูกสงวนไว้สำหรับระบบ");
        }
    }

    /**
     * สร้างบัญชีโฮสติ้ง (role=webadmin พร้อมโควตา)
     *
     * @param array<string,int> $quotas ชนิดทรัพยากร → จำนวน (ไม่ระบุ = ใช้ค่าเริ่มต้นของตาราง)
     *
     * @throws \InvalidArgumentException
     */
    public function createHostingAccount(
        string $username,
        string $plainPassword,
        string $displayName,
        string $email,
        array $quotas = [],
        ?int $expiryAt = null,
        bool $mustChangePassword = false,
    ): int {
        self::assertUsername($username);

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('อีเมลไม่ถูกต้อง');
        }

        if ($this->findByUsername($username) !== null) {
            throw new \InvalidArgumentException('มีผู้ใช้ชื่อนี้อยู่แล้ว');
        }

        if ($expiryAt !== null && $expiryAt < time()) {
            throw new \InvalidArgumentException('วันหมดอายุต้องเป็นเวลาในอนาคต');
        }

        $now = time();
        $row = [
            'username' => $username,
            'display_name' => $displayName !== '' ? $displayName : $username,
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
     * @return list<array<string,mixed>> บัญชีโฮสติ้งทั้งหมดพร้อมจำนวนเว็บที่ถือครอง
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

    /** จำนวนบัญชีที่จะหมดอายุภายในกี่วันข้างหน้า */
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
     * แก้ชื่อที่แสดงและอีเมล
     *
     * **อีเมลว่างได้ และหมายถึง "ไม่มีอีเมล" ไม่ใช่ค่าที่ผิด** — บัญชีที่สร้างจาก
     * `phpcp user:create` ไม่มีอีเมล และตารางก็ไม่ได้บังคับไว้ · เดิมตรวจรูปแบบกับ
     * ค่าว่างด้วย ผลคือบัญชีที่ไม่มีอีเมล **แก้ชื่อที่แสดงไม่ได้เลย** เพราะ
     * `UsersController::update()` ส่งอีเมลเดิม (ที่ว่าง) กลับเข้ามาเป็นค่าตั้งต้น
     */
    public function updateProfile(int $userId, string $displayName, string $email): void
    {
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('อีเมลไม่ถูกต้อง');
        }

        $data = ['email' => $email, 'updated_at' => time()];

        if ($displayName !== '') {
            $data['display_name'] = $displayName;
        }

        $this->db->update('users', $data, ['id' => $userId]);
    }

    /**
     * @param array<string,int|null> $quotas ชนิดทรัพยากร → จำนวน (null = ไม่แก้)
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
            throw new \InvalidArgumentException('วันหมดอายุต้องเป็นเวลาในอนาคตหรือ null');
        }

        $this->db->update('users', ['expiry_at' => $expiryAt, 'updated_at' => time()], ['id' => $userId]);
    }

    /**
     * เปลี่ยนสถานะบริการโฮสติ้ง — คนละแกนกับ status ที่คุมสิทธิ์ล็อกอิน
     *
     * ตั้งใจให้ระงับบริการแล้วผู้ใช้ยังล็อกอินเข้ามาดูสาเหตุและต่ออายุได้ ซึ่งของเดิมทำไม่ได้
     * เพราะ setStatus() ไปปิดบัญชีล็อกอินให้ด้วยทุกครั้ง
     *
     * @throws \InvalidArgumentException
     */
    public function setServiceStatus(int $userId, string $status): void
    {
        if (!in_array($status, ['active', 'suspended', 'expired'], true)) {
            throw new \InvalidArgumentException('สถานะบริการไม่ถูกต้อง');
        }

        $this->db->update('users', ['service_status' => $status, 'updated_at' => time()], ['id' => $userId]);
    }

    /**
     * บังคับให้เปลี่ยนรหัสผ่านตอนล็อกอินครั้งถัดไป
     *
     * ใช้เมื่อรหัสผ่านถูกสุ่มโดยระบบแล้วแสดงบนหน้าจอ — รหัสที่ผ่านตาคนกลางมาแล้ว
     * ต้องมีอายุสั้นที่สุด
     */
    public function requirePasswordChange(int $userId): bool
    {
        return $this->db->update(
            'users',
            ['must_change_password' => 1, 'updated_at' => time()],
            ['id' => $userId],
        ) > 0;
    }

    /** @return list<int> id ของเว็บที่ผู้ใช้เป็นเจ้าของ */
    public function siteIds(int $userId): array
    {
        $rows = $this->db->all(
            'SELECT id FROM sites WHERE owner_user_id = :u ORDER BY id',
            ['u' => $userId],
        );

        return array_map(intval(...), array_column($rows, 'id'));
    }

    /**
     * เว็บทั้งหมดของผู้ใช้ พร้อมชื่อบัญชีระบบของเจ้าของ
     *
     * ต้องมีชื่อเจ้าของติดมาด้วยเสมอ เพราะผู้รับผลลัพธ์เอาไปประกอบเป็นเส้นทางไฟล์
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
     * โควตาที่ตั้งไว้ของผู้ใช้
     *
     * @return array<string,int>|null null = ไม่พบผู้ใช้
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
     * จำนวนทรัพยากรที่ผู้ใช้ใช้ไปแล้ว
     *
     * นับจาก sites.owner_user_id โดยตรง — เดิมต้องผ่านตาราง customer_sites ซึ่งเป็นความจริง
     * คนละชุดกับ owner_user_id และในฐานข้อมูลจริงก็ขัดกันเองอยู่
     *
     * @return array<string,int>
     */
    public function usage(int $userId): array
    {
        $siteIds = $this->siteIds($userId);

        $usage = array_fill_keys(array_keys(Quota::TYPES), 0);

        // SFTP นับจากสถานะจริงของบัญชี ไม่ใช่จากตารางแยก — หนึ่งบัญชีโฮสติ้งมีได้บัญชีเดียว
        // เสมอตั้งแต่เฟส E4 · ต้องนับก่อนออกจากฟังก์ชันตอนไม่มีเว็บ เพราะสถานะนี้ไม่ได้ขึ้น
        // กับจำนวนเว็บ (แม้จะเปิด SFTP ได้ต่อเมื่อมีบัญชีระบบ ซึ่งเกิดตอนสร้างเว็บแรกก็ตาม)
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

        // อีเมลยังไม่มีตารางของตัวเอง (mail hosting เต็มรูปแบบไม่อยู่ในขอบเขต — §2.1)
        // ส่วน SFTP นับไปแล้วด้านบนจาก `sftp_enabled`
        return $usage;
    }

    /**
     * บริการของผู้ใช้ยังใช้งานได้อยู่หรือไม่
     *
     * @return array{ok:bool,message:string}
     */
    public function checkService(int $userId): array
    {
        $user = $this->find($userId);

        if ($user === null) {
            return ['ok' => false, 'message' => 'ไม่พบผู้ใช้'];
        }

        if ($user['service_status'] === 'expired') {
            return ['ok' => false, 'message' => 'บัญชีหมดอายุแล้ว'];
        }

        if ($user['service_status'] === 'suspended') {
            return ['ok' => false, 'message' => 'บัญชีถูกระงับชั่วคราว'];
        }

        if ($user['expiry_at'] !== null && (int) $user['expiry_at'] < time()) {
            return ['ok' => false, 'message' => 'วันหมดอายุผ่านไปแล้ว'];
        }

        return ['ok' => true, 'message' => 'ใช้งานได้'];
    }

    /** true = บันทึกการแจ้งเตือนใหม่ · false = เคยแจ้งรอบนี้ไปแล้ว */
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

    /** @return list<array<string,mixed>> บัญชีที่ควรแจ้งเตือนว่าใกล้หมดอายุ */
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
     * ระดับโควตาดิสก์ล่าสุดที่แจ้งเตือนไปแล้ว (0/80/90/100) — 0 = ยังไม่เคยแจ้ง
     *
     * ต่างจาก expiry_notifications ตรงที่การใช้ดิสก์ขึ้นลงได้ จึงเก็บแค่ค่าล่าสุดค่าเดียว
     * ไม่ใช่ประวัติทุกครั้งที่แจ้ง — ดูเหตุผลเต็มในคอมเมนต์ของ migration 0011
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
     * บันทึกระดับที่ตรวจล่าสุด — เรียกทุกครั้งที่ `quota.disk_check` ทำงาน ไม่ว่าระดับ
     * จะขึ้นหรือลง เพื่อให้ `diskQuotaThreshold()` สะท้อนสถานะปัจจุบันเสมอ
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
     * แก้ไขโควตาพื้นที่ดิสก์ — แยกจาก updateQuota() เพราะดิสก์วัดเป็น MB ไม่ใช่จำนวนชิ้น
     * จึงไม่อยู่ใน Quota::TYPES (ซึ่งมีกฎ "ห้ามเป็น 0" เฉพาะบางชนิดที่ไม่เกี่ยวกับดิสก์เลย)
     *
     * @throws \InvalidArgumentException
     */
    public function updateDiskQuota(int $userId, int $quotaMb): void
    {
        if ($quotaMb < Quota::UNLIMITED) {
            throw new \InvalidArgumentException('โควตาพื้นที่ดิสก์ต้องเป็น -1 (ไม่จำกัด) หรือจำนวนเต็มไม่ติดลบ (MB)');
        }

        $this->db->update('users', ['disk_quota_mb' => $quotaMb, 'updated_at' => time()], ['id' => $userId]);
    }
}
