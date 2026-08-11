<?php

declare(strict_types=1);

namespace Phpcp\Agent\Capability;

use Phpcp\Agent\Context;
use Phpcp\Agent\Executor\Executor;
use Phpcp\Agent\ValidationError;
use Phpcp\Domain\DbAccountRepository;
use Phpcp\Domain\UserAccount;
use Phpcp\Domain\UserRepository;
use Phpcp\Security\Permissions;
use Phpcp\Domain\QuotaChecker;
use Phpcp\Driver\Db\MariaDbManager;
use Phpcp\Support\Validator;

/**
 * สร้างฐานข้อมูลพร้อมผู้ใช้เฉพาะของมัน — PROMPT.md
 *
 * สร้างผู้ใช้แยกต่อฐานข้อมูลเสมอ ไม่ใช้ผู้ใช้ร่วมกัน เพราะเว็บไซต์ที่ถูกแฮ็ก
 * แล้วยึด credential ไปต้องอ่านฐานข้อมูลของเว็บอื่นไม่ได้ (SECURITY §2.6)
 *
 * รหัสผ่านถูกสุ่มให้และแสดงครั้งเดียว — panel ไม่เก็บไว้ เพราะไม่มีเหตุผลที่ต้องใช้อีก
 * และการเก็บรหัสผ่านที่ถอดกลับได้เพิ่มความเสียหายตอนฐานข้อมูล panel รั่ว
 */
final class DbCreate extends DbAccountCapability
{
    public static function name(): string
    {
        return 'db.create';
    }

    public function permission(): string
    {
        return 'db.manage';
    }

    public function isMutating(): bool
    {
        return true;
    }

    public function summary(): string
    {
        return 'สร้างฐานข้อมูลใหม่พร้อมผู้ใช้เฉพาะ';
    }

    public function validate(array $args): array
    {
        $name = MariaDbManager::assertDatabaseName(Validator::requireString($args, 'name', 64));

        // ชื่อผู้ใช้เริ่มต้นอนุมานจากชื่อฐานข้อมูล ตัดให้พอดีข้อจำกัด 32 ตัว
        $user = Validator::optionalString($args, 'username', '', 32);
        if ($user === '') {
            $user = substr(preg_replace('/_db$/', '', $name) . '_user', 0, 32);
        }

        return [
            'name' => $name,
            'username' => MariaDbManager::assertUserName($user),
            'host' => Validator::requireEnum(
                ['host' => $args['host'] ?? 'localhost'],
                'host',
                ['localhost', '127.0.0.1', '%'],
            ),
            'privileges' => MariaDbManager::assertPrivilege(
                Validator::optionalString($args, 'privileges', 'readwrite', 16),
            ),
            'site_id' => isset($args['site_id']) ? Validator::requireInt($args, 'site_id', 0) : 0,
            'charset' => Validator::optionalString($args, 'charset', 'utf8mb4', 32),
        ];
    }

    /**
     * บัญชีของเจ้าของเว็บที่ฐานข้อมูลนี้จะผูกอยู่ — null = ไม่ได้ผูกกับเว็บของลูกค้า
     *
     * ใช้ทั้งกำหนดคำนำหน้าชื่อฐานข้อมูลและตัดสินว่าจะ grant ให้ใคร
     */
    private function ownerAccount(Context $context, int $siteId): ?UserAccount
    {
        if ($siteId <= 0) {
            return null;
        }

        $row = $context->db->first(
            'SELECT u.* FROM sites s JOIN users u ON u.id = s.owner_user_id WHERE s.id = :id',
            ['id' => $siteId],
        );

        if ($row === null || $row['role'] !== Permissions::WEBADMIN || $row['system_user'] === null) {
            return null;
        }

        try {
            return UserAccount::fromRow($row);
        } catch (\InvalidArgumentException) {
            return null;
        }
    }

    /**
     * ตรวจสอบโควตาสำหรับลูกค้าก่อนสร้างฐานข้อมูล
     *
     * @throws ValidationError
     */
    private function assertQuota(Context $context, int $siteId): void
    {
        if ($siteId <= 0) {
            return; // ไม่มีเว็บไซต์ = ไม่ต้องตรวจสอบโควตา
        }

        // หาเจ้าของเว็บไซต์
        $site = $context->db->first('SELECT owner_user_id FROM sites WHERE id = :id', ['id' => $siteId]);
        if ($site === null) {
            return; // ไม่พบเจ้าของ
        }

        $ownerUserId = (int) ($site['owner_user_id'] ?? 0);
        if ($ownerUserId <= 0) {
            return; // ไม่มีเจ้าของลูกค้า
        }

        $quota = new QuotaChecker(new UserRepository($context->db));

        $result = $quota->checkOwnerCanCreate($ownerUserId, 'database', 1);
        if (!$result['ok']) {
            throw new ValidationError($result['message']);
        }
    }

    public function run(array $args, Executor $executor, Context $context): array
    {
        $manager = $this->manager();

        if (!$manager->isInstalled($executor)) {
            throw new ValidationError('เครื่องนี้ยังไม่ได้ติดตั้ง MariaDB หรือ MySQL');
        }

        $this->assertSiteAccess($context, $args['site_id']);
        $this->assertQuota($context, $args['site_id']);

        // ฐานข้อมูลของลูกค้าถูกใส่คำนำหน้าตามชื่อบัญชีเสมอ — MariaDB มี namespace เดียว
        // ทั้งเครื่อง ลูกค้าคนละรายจึงตั้งชื่อ `shop` พร้อมกันไม่ได้ถ้าไม่มีคำนำหน้า
        // (ฐานข้อมูลที่ผู้ดูแลสร้างโดยไม่ผูกกับเว็บของลูกค้าไม่ถูกแตะ)
        $owner = $this->ownerAccount($context, $args['site_id']);

        if ($owner !== null) {
            $qualified = DbAccountRepository::qualify($owner->username, $args['name']);

            // MariaDB จำกัดชื่อฐานข้อมูลที่ 64 ตัว และคำนำหน้ากินโควตานั้นไปด้วย
            // ถ้าปล่อยให้ไปล้มที่ assertDatabaseName ผู้ใช้จะได้ข้อความว่า "ชื่อยาวเกิน"
            // โดยไม่รู้เลยว่ามีคำนำหน้าที่ตัวเองไม่ได้พิมพ์บวกเข้าไปด้วย
            if (strlen($qualified) > 64) {
                $available = 64 - strlen(DbAccountRepository::prefixFor($owner->username));

                throw new ValidationError(
                    "ชื่อฐานข้อมูลยาวเกินไป — ระบบเติมคำนำหน้า "
                    .DbAccountRepository::prefixFor($owner->username)
                    ." ให้อัตโนมัติ จึงเหลือให้ตั้งชื่อได้ {$available} ตัว",
                );
            }

            $args['name'] = MariaDbManager::assertDatabaseName($qualified);
        }

        $existing = $context->db->first('SELECT id FROM databases_ WHERE db_name = :n', ['n' => $args['name']]);
        if ($existing !== null) {
            throw new ValidationError("มีฐานข้อมูลชื่อ {$args['name']} อยู่แล้ว");
        }

        if (in_array($args['name'], $manager->databases($executor), true)) {
            throw new ValidationError("มีฐานข้อมูลชื่อ {$args['name']} อยู่บนเครื่องแล้ว");
        }

        $password = self::randomPassword();

        // สร้างบนเครื่องก่อน แล้วค่อยบันทึกลง panel — ถ้าขั้นตอนบนเครื่องล้ม
        // จะไม่เหลือแถวค้างในฐานข้อมูลของ panel ที่ชี้ไปยังของที่ไม่มีอยู่จริง
        $manager->createDatabase($executor, $args['name'], $args['charset']);

        try {
            $manager->createUser($executor, $args['username'], $args['host'], $password);
            $manager->grant($executor, $args['name'], $args['username'], $args['host'], $args['privileges']);

            // เจ้าของเว็บต้องเห็นฐานข้อมูลนี้ใน phpMyAdmin ทันที ไม่ใช่ตอนเปิดครั้งถัดไป —
            // ถ้า grant ตอนเปิด phpMyAdmin แทน ผู้ใช้ที่เพิ่งสร้างฐานข้อมูลเสร็จจะเปิดไป
            // แล้วไม่เห็นของที่เพิ่งสร้าง ซึ่งดูเหมือนระบบพัง
            //
            // ผู้ใช้เฉพาะของฐานข้อมูล (ที่มีรหัสแสดงครั้งเดียวข้างบน) ยังมีอยู่เหมือนเดิม
            // เพราะแอปของลูกค้าควรต่อฐานข้อมูลด้วยบัญชีที่จำกัดสิทธิ์เฉพาะฐานข้อมูลตัวเอง
            // ไม่ใช่บัญชีที่เห็นทุกฐานข้อมูลของเจ้าของ
            if ($owner !== null) {
                $accounts = $this->dbAccounts($context);
                $accounts->ensure($executor, $owner);
                $accounts->grantDatabase($executor, $owner, $args['name']);
            }
        } catch (\Throwable $e) {
            $manager->dropDatabase($executor, $args['name']);

            throw $e;
        }

        $this->record($context, $args);
        $this->invalidateSizesCache($executor, $context);

        return [
            'name' => $args['name'],
            'username' => $args['username'],
            'host' => $args['host'],
            // แสดงครั้งเดียว — panel ไม่เก็บรหัสผ่านนี้ไว้ที่ใดเลย
            'password' => $password,
            'privileges' => $args['privileges'],
            'message' => "สร้างฐานข้อมูล {$args['name']} พร้อมผู้ใช้ {$args['username']} แล้ว",
        ];
    }

    /** @param array<string,mixed> $args */
    private function record(Context $context, array $args): void
    {
        $context->db->transaction(static function ($db) use ($args): void {
            $databaseId = $db->insert('databases_', [
                'db_name' => $args['name'],
                'site_id' => $args['site_id'] > 0 ? $args['site_id'] : null,
                'charset' => $args['charset'],
                'size_bytes' => 0,
                'created_at' => time(),
            ]);

            $user = $db->first(
                'SELECT id FROM db_users WHERE username = :u AND host = :h',
                ['u' => $args['username'], 'h' => $args['host']],
            );

            $userId = $user !== null
                ? (int) $user['id']
                : $db->insert('db_users', ['username' => $args['username'], 'host' => $args['host']]);

            $db->insert('db_grants', [
                'db_id' => $databaseId,
                'db_user_id' => $userId,
                'privileges' => $args['privileges'],
            ]);
        });
    }
}
