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
 * สร้างบัญชีโฮสติ้งใหม่ — ผู้ใช้ role=webadmin พร้อมโควตาของตัวเอง
 *
 * ตั้งแต่ migration 0005 ลูกค้าไม่ใช่ตารางแยกอีกต่อไป แต่คือแถวเดียวกับที่ใช้ล็อกอิน
 * จึงไม่มีรหัสผ่านสองชุดให้หลุด sync และไม่มี id สองระบบให้แปลงไปมาอีก
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
        return 'สร้างลูกค้าใหม่พร้อมโควตา';
    }

    public function validate(array $args): array
    {
        // ชื่อผู้ใช้จะกลายเป็นชื่อบัญชี Linux และโฟลเดอร์บ้านจริง ๆ ในเฟส M3
        // กฎจึงอยู่ที่ UserRepository ที่เดียว ไม่ใช่ regex ซ้ำในแต่ละ capability
        $username = Validator::requireString($args, 'username', 32);
        try {
            UserRepository::assertUsername($username);
        } catch (\InvalidArgumentException $e) {
            throw new ValidationError($e->getMessage());
        }

        // ตรวจสอบ password
        $password = Validator::requireString($args, 'password', 256);
        if (mb_strlen($password) < 8) {
            throw new ValidationError('รหัสผ่านต้องยาวอย่างน้อย 8 ตัวอักษร');
        }

        // ตรวจสอบ email
        $email = Validator::requireString($args, 'email', 256);
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new ValidationError('อีเมลไม่ถูกต้อง');
        }

        // ตรวจสอบ display name (optional)
        $displayName = Validator::optionalString($args, 'display_name', '', 128);

        // ตรวจสอบ quota (optional, มีค่า default ใน database)
        $quotaDomains = Validator::optionalInt($args, 'quota_domains', 10);
        $quotaSubdomains = Validator::optionalInt($args, 'quota_subdomains', 20);
        $quotaAliases = Validator::optionalInt($args, 'quota_aliases', 50);
        $quotaEmails = Validator::optionalInt($args, 'quota_emails', 100);
        $quotaDatabases = Validator::optionalInt($args, 'quota_databases', 10);
        $quotaFtpUsers = Validator::optionalInt($args, 'quota_ftp_users', 5);

        // ตรวจสอบ expiry_at (optional)
        $expiryAt = null;
        if (isset($args['expiry_at']) && $args['expiry_at'] !== null) {
            $expiryAt = Validator::requireInt($args, 'expiry_at', 0);
            if ($expiryAt < time()) {
                throw new ValidationError('วันหมดอายุต้องเป็นเวลาในอนาคต');
            }
        }

        // โควตาใช้กฎเดียวกับที่อื่นทั้งระบบ: -1 = ไม่จำกัด · 0 = ปิด · >0 = จำกัด
        // เดิมตรงนี้ปฏิเสธค่าติดลบทั้งหมด ทำให้ตั้ง "ไม่จำกัด" ผ่านทางนี้ไม่ได้
        // ทั้งที่ CLI และ repository รองรับ — ความไม่สอดคล้องแบบนี้คือที่มาของบั๊กที่หายาก
        self::assertQuota($quotaDomains, 'domains');
        self::assertQuota($quotaSubdomains, 'subdomains');
        self::assertQuota($quotaAliases, 'aliases');
        self::assertQuota($quotaEmails, 'emails');
        self::assertQuota($quotaDatabases, 'databases');
        self::assertQuota($quotaFtpUsers, 'ftp_users');

        // ดิสก์วัดเป็น MB ไม่ใช่จำนวนชิ้น จึงไม่อยู่ใน Quota::TYPES — ตรวจแยกแบบเดียวกับ
        // CustomerQuotaUpdate (PLAN-V2 เฟส E2)
        $diskQuotaMb = Validator::optionalInt($args, 'disk_quota_mb', 10240);
        if ($diskQuotaMb < -1) {
            throw new ValidationError('โควตาพื้นที่ดิสก์ต้องเป็น -1 (ไม่จำกัด) หรือจำนวนเต็มไม่ติดลบ (MB)');
        }

        return [
            'username' => $username,
            'password' => $password,
            'display_name' => $displayName,
            'email' => $email,
            'quota_domains' => $quotaDomains,
            'quota_subdomains' => $quotaSubdomains,
            'quota_aliases' => $quotaAliases,
            'quota_emails' => $quotaEmails,
            'quota_databases' => $quotaDatabases,
            'quota_ftp_users' => $quotaFtpUsers,
            'disk_quota_mb' => $diskQuotaMb,
            'expiry_at' => $expiryAt,
            // รหัสผ่านที่ระบบสุ่มให้ต้องถูกเปลี่ยนตอนเข้าสู่ระบบครั้งแรกเสมอ
            // ผู้เรียกเป็นคนบอกว่าสุ่มมาหรือผู้ดูแลตั้งเอง เพราะที่นี่แยกไม่ออก
            'must_change_password' => (bool) ($args['must_change_password'] ?? false),
        ];
    }

    public function run(array $args, Executor $executor, Context $context): array
    {
        $users = $this->users($context);

        if ($users->findByUsername($args['username']) !== null) {
            throw new ValidationError("มีผู้ใช้ชื่อ {$args['username']} อยู่แล้ว");
        }

        // repository ตรวจซ้ำอีกชั้นและโยน InvalidArgumentException — ต้องแปลงเป็น
        // ValidationError ที่นี่ ไม่งั้น agent จะตอบ "เกิดข้อผิดพลาดภายในระบบ"
        // ให้ผู้ใช้ที่แค่กรอกอีเมลผิด แล้วไม่มีใครรู้ว่าต้องแก้อะไร
        try {
            $userId = $users->createHostingAccount(
                $args['username'],
                $args['password'],
                $args['display_name'],
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

        // audit log บันทึกโดย Dispatcher ให้แล้วรอบ run() ทุกคำสั่ง (ARCHITECTURE §4.1)
        return [
            'id' => $userId,
            'username' => $args['username'],
            'display_name' => $args['display_name'],
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
            'message' => "สร้างบัญชีโฮสติ้ง {$args['username']} แล้ว",
        ];
    }
}
