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
 * อัปเดตโควตาของบัญชีโฮสติ้ง — ผู้ดูแลปรับโควตาเฉพาะรายบุคคลได้
 *
 * ใช้กรณี:
 * - ลูกค้าขอเพิ่มโควตา (เช่นต้องการโดเมนเพิ่ม)
 * - ลูกค้าลดโควตา (เพื่อลดค่าใช้จ่าย)
 * - ทดสอบหรือชั่วคราวให้โควตาพิเศษ
 */
final class CustomerQuotaUpdate extends CustomerCapability implements Capability
{
    /** ชื่อ arg (ตรงกับชื่อคอลัมน์) => ชนิดทรัพยากรตามที่คลาส Quota รู้จัก */
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
        return 'อัปเดตโควตาของลูกค้า';
    }

    /**
     * @param array $args
     */
    public function validate(array $args): array
    {
        $clean = ['user_id' => Validator::requireInt($args, 'user_id', 1)];
        $given = 0;

        foreach (self::QUOTA_FIELDS as $field => $type) {
            // null = ไม่ได้ส่งมา จึงไม่เปลี่ยน — ต่างจาก 0 ที่แปลว่า "ปิดการใช้งาน"
            $value = Validator::nullableInt($args, $field);

            // กฎเดียวกับทั้งระบบ: -1 = ไม่จำกัด · 0 = ปิด · >0 = จำกัด (โดเมนห้ามเป็น 0)
            self::assertQuota($value, $type);

            $clean[$field] = $value;
            $given += $value === null ? 0 : 1;
        }

        // โควตาดิสก์ไม่อยู่ใน Quota::TYPES (วัดเป็น MB ไม่ใช่จำนวนชิ้น ไม่มีกฎห้ามเป็น 0)
        // จึงตรวจแยกแล้วส่งผ่าน user_id ไปให้ UserRepository::updateDiskQuota() ตอน run()
        $diskQuotaMb = Validator::nullableInt($args, 'disk_quota_mb');

        if ($diskQuotaMb !== null && $diskQuotaMb < -1) {
            throw new ValidationError('โควตาพื้นที่ดิสก์ต้องเป็น -1 (ไม่จำกัด) หรือจำนวนเต็มไม่ติดลบ (MB)');
        }

        $clean['disk_quota_mb'] = $diskQuotaMb;
        $given += $diskQuotaMb === null ? 0 : 1;

        if ($given === 0) {
            throw new ValidationError('ต้องระบุอย่างน้อย 1 โควตาที่ต้องการอัปเดต');
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

        // **ตัดสิทธิ์ SFTP ตามแพ็กเกจแล้ว การเข้าถึงที่เปิดค้างอยู่ต้องถูกปิดตามทันที**
        //
        // ไม่งั้นบัญชีที่เคยเปิด SFTP ไว้ยังล็อกอินเข้าเครื่องได้ต่อไปทั้งที่แพ็กเกจไม่รวมแล้ว
        // และหน้าจอก็ซ่อนปุ่มปิดไปด้วย (`sftp_available` เป็น false) จึงปิดจากหน้าเว็บไม่ได้เลย
        // — กลายเป็นสิทธิ์ที่ถอนไม่ได้ · เจอตอนไล่ตรวจความสอดคล้องของโควตา FTP (2026-08-10)
        $sftpRevoked = false;

        if ($args['quota_ftp_users'] !== null
            && Quota::isDisabled($args['quota_ftp_users'])
            && (int) ($before['sftp_enabled'] ?? 0) === 1) {
            (new SftpDisable())->run(['user_id' => $args['user_id']], $executor, $context);
            $sftpRevoked = true;
        }

        $updated = $users->find($args['user_id']);

        // สรุปว่าอะไรเปลี่ยนจากอะไรเป็นอะไร — ค่านี้ไปอยู่ใน audit log ผ่าน Dispatcher
        // "โควตาโดเมนจาก 10 เป็น 3" มีประโยชน์ตอนสอบสวนย้อนหลังกว่าค่าใหม่เพียว ๆ มาก
        $changes = [];
        foreach (array_keys(self::QUOTA_FIELDS) as $field) {
            if ($args[$field] !== null) {
                $changes[$field] = ['from' => (int) $before[$field], 'to' => $args[$field]];
            }
        }
        if ($args['disk_quota_mb'] !== null) {
            // disk_quota_mb เป็น NULL ได้ (คอลัมน์ไม่มี DEFAULT) — ต้องอ่านเป็น "ไม่จำกัด"
            // เหมือนที่ QuotaChecker ตีความ ไม่ใช่ให้ (int) null กลายเป็น 0 ใน audit log
            $changes['disk_quota_mb'] = ['from' => (int) ($before['disk_quota_mb'] ?? -1), 'to' => $args['disk_quota_mb']];
        }

        // audit log บันทึกโดย Dispatcher ให้แล้วรอบ run() ทุกคำสั่ง (ARCHITECTURE §4.1)
        return [
            'id' => $updated['id'],
            'username' => $updated['username'],
            'display_name' => $updated['display_name'],
            'quota_domains' => (int) $updated['quota_domains'],
            'quota_subdomains' => (int) $updated['quota_subdomains'],
            'quota_aliases' => (int) $updated['quota_aliases'],
            'quota_emails' => (int) $updated['quota_emails'],
            'quota_databases' => (int) $updated['quota_databases'],
            'quota_ftp_users' => (int) $updated['quota_ftp_users'],
            'disk_quota_mb' => (int) ($updated['disk_quota_mb'] ?? -1),
            'sftp_revoked' => $sftpRevoked,
            'changes' => $changes,
            // ต้องบอกให้ชัดว่าการเข้าถึงถูกตัดไปด้วย ไม่ใช่แค่ "อัปเดตโควตาแล้ว" เฉย ๆ
            // — ผู้ดูแลต้องรู้ว่าลูกค้าจะเข้า SFTP ไม่ได้อีกตั้งแต่วินาทีนี้
            'message' => $sftpRevoked
                ? "อัปเดตโควตาของ {$before['username']} แล้ว และปิดการเข้าถึง SFTP ที่เปิดค้างอยู่"
                : "อัปเดตโควตาของ {$before['username']} แล้ว",
        ];
    }
}
