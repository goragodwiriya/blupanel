<?php

declare(strict_types=1);

namespace Phpcp\Domain;

use Phpcp\Security\Permissions;

/**
 * ตัวตัดสินว่าผู้ใช้สร้างทรัพยากรเพิ่มได้อีกหรือไม่
 *
 * ตั้งแต่ migration 0005 โควตาอยู่บนแถว users เดียวกับที่ใช้ล็อกอิน จึงไม่มีทาง
 * เกิดสภาพ "ผู้ใช้ที่ไม่มีโควตา" อีกต่อไป — เดิมโควตาอยู่ตาราง customers แยกต่างหาก
 * และ webadmin ที่สร้างจาก CLI/API จะไม่มีแถวลูกค้า ทำให้ตัวตรวจหาไม่เจอแล้วปล่อยผ่าน
 * ทุกคำขอแบบไม่จำกัด ซึ่งเป็นช่องโหว่ที่หายไปพร้อมกับการยุบตาราง ไม่ใช่ด้วยการเพิ่ม if
 *
 * กฎของตัวเลขอยู่ที่คลาส Quota ที่เดียว: -1 = ไม่จำกัด · 0 = ปิด · >0 = จำกัดตามจำนวน
 */
final class QuotaChecker
{
    public function __construct(private readonly UserRepository $users)
    {
    }

    /**
     * ผู้ใช้สร้างทรัพยากรชนิดนี้เพิ่มอีก $amount ชิ้นได้หรือไม่
     *
     * รับชื่อชนิดได้ทั้งเอกพจน์ ('domain') และพหูพจน์ ('domains') เพราะผู้เรียกในระบบ
     * ใช้คนละแบบกันมาแต่ไหนแต่ไร และความไม่ตรงกันนั้นเคยทำให้โควตาปฏิเสธทุกคำขอ
     *
     * @return array{ok:bool,message:string,used:int,limit:int}
     */
    public function canCreate(int $userId, string $type, int $amount = 1): array
    {
        if ($amount <= 0) {
            return ['ok' => false, 'message' => 'จำนวนต้องมากกว่า 0', 'used' => 0, 'limit' => 0];
        }

        $name = Quota::normalize($type);

        // ทรัพยากรที่ไม่มีคอลัมน์โควตาเลย (เช่น redirect) คือ "ไม่ได้จำกัดไว้"
        // ต่างจาก "จำกัดเป็น 0" — ถ้าแยกไม่ออก การเพิ่มชนิดใหม่จะทำให้ของเดิมพังเงียบ ๆ
        if ($name === null) {
            return [
                'ok' => true,
                'message' => 'ไม่ได้จำกัดจำนวนของทรัพยากรชนิดนี้',
                'used' => 0,
                'limit' => Quota::UNLIMITED,
            ];
        }

        $quotas = $this->users->quotas($userId);

        if ($quotas === null) {
            return ['ok' => false, 'message' => 'ไม่พบผู้ใช้', 'used' => 0, 'limit' => 0];
        }

        $limit = $quotas[$name];
        $used = $this->users->usage($userId)[$name];
        $label = Quota::label($name);

        if (Quota::isUnlimited($limit)) {
            return ['ok' => true, 'message' => 'สามารถสร้างได้', 'used' => $used, 'limit' => $limit];
        }

        if (Quota::isDisabled($limit)) {
            return [
                'ok' => false,
                'message' => "โควตา{$label}ถูกปิดการใช้งาน",
                'used' => $used,
                'limit' => $limit,
            ];
        }

        if ($used + $amount > $limit) {
            return [
                'ok' => false,
                'message' => "โควตา{$label}เต็ม (ใช้ {$used} จาก {$limit})",
                'used' => $used,
                'limit' => $limit,
            ];
        }

        return ['ok' => true, 'message' => 'สามารถสร้างได้', 'used' => $used, 'limit' => $limit];
    }

    /**
     * ตรวจทั้งสถานะบริการและโควตา — ด่านเดียวที่ capability ควรเรียก
     *
     * เจ้าของเป็น 0 หมายถึงทรัพยากรที่ไม่มีเจ้าของ จึงไม่ต้องคิดโควตา
     *
     * @return array{ok:bool,message:string,used:int,limit:int}
     */
    public function checkOwnerCanCreate(int $ownerUserId, string $type, int $amount = 1): array
    {
        if ($ownerUserId <= 0) {
            return [
                'ok' => true,
                'message' => 'ไม่มีเจ้าของ จึงไม่คิดโควตา',
                'used' => 0,
                'limit' => Quota::UNLIMITED,
            ];
        }

        // โควตาเป็นข้อจำกัดเชิงธุรกิจของ**ลูกค้า** ไม่ใช่ของผู้ดูแลเซิร์ฟเวอร์
        //
        // ต้องตัดสินจากบทบาท ไม่ใช่จากตัวเลขที่เก็บไว้ เพราะการเปลี่ยนบทบาททีหลัง
        // ไม่ได้ล้างตัวเลขเดิม — ลูกค้าที่ถูกเลื่อนเป็นผู้ดูแลจะยังติดโควตาเดิมค้างอยู่
        // แล้วแก้ไม่ได้ด้วย เพราะเส้นทางจัดการโควตารับเฉพาะบัญชี webadmin (ตั้งใจให้เป็นแบบนั้น
        // เพื่อกัน sysadmin ไปยุ่งกับบัญชีผู้ดูแล) · เจอจากการทดสอบบนเครื่องจริง
        $owner = $this->users->find($ownerUserId);

        if ($owner !== null && $owner['role'] !== Permissions::WEBADMIN) {
            return [
                'ok' => true,
                'message' => 'บัญชีผู้ดูแลไม่ถูกจำกัดโควตา',
                'used' => 0,
                'limit' => Quota::UNLIMITED,
            ];
        }

        $service = $this->users->checkService($ownerUserId);

        if (!$service['ok']) {
            return ['ok' => false, 'message' => $service['message'], 'used' => 0, 'limit' => 0];
        }

        // โควตาดิสก์กันทรัพยากรใหม่ทุกชนิด ไม่ใช่แค่ชนิดที่ตัวเองมีคอลัมน์นับ — เว็บไซต์
        // ใหม่ ฐานข้อมูลใหม่ หรือโดเมนใหม่ ล้วนต้องเขียนไฟล์ลงบ้านของบัญชีนี้ทั้งนั้น
        // (PLAN-V2 เฟส E2 — ทางที่ทำได้จริงในชั้นแอปโดยไม่ต้องมี project quota ของ OS)
        $diskMessage = $this->diskQuotaExceeded($owner);

        if ($diskMessage !== null) {
            $used = (int) ($owner['disk_used_mb'] ?? 0);
            $limit = (int) ($owner['disk_quota_mb'] ?? Quota::UNLIMITED);

            return ['ok' => false, 'message' => $diskMessage, 'used' => $used, 'limit' => $limit];
        }

        return $this->canCreate($ownerUserId, $type, $amount);
    }

    /**
     * ข้อความบอกเหตุผลถ้าโควตาดิสก์เต็มแล้ว — null ถ้ายังไม่เต็มหรือไม่จำกัด
     *
     * **ข้อจำกัดที่ต้องรู้:** นี่บล็อกได้แค่การ "สร้างทรัพยากรใหม่ผ่าน panel" เท่านั้น
     * ไฟล์ที่แอปของลูกค้าเขียนเองผ่านโค้ด PHP ของเขาไม่ผ่านด่านนี้เลย — การบล็อกที่จุดนั้น
     * จริง ๆ ต้องใช้ project quota ของ filesystem (XFS/ext4) ซึ่งยังไม่ได้ทำในเฟสนี้
     * (ดู "ที่ยังเหลือ" ของเฟส E2 ใน PLAN-V2.md) ข้อความนี้จึงต้องไม่สื่อว่า "เขียนไฟล์ไม่ได้
     * แล้ว" แต่บอกแค่ว่า "สร้างของใหม่ไม่ได้" ซึ่งเป็นสิ่งที่ระบบรับประกันได้จริง
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
            'พื้นที่ดิสก์เต็ม (ใช้ %d MB จาก %d MB) — ต้องลบไฟล์หรือขยายโควตาก่อนสร้างทรัพยากรใหม่',
            $used,
            $limit,
        );
    }

    /**
     * สรุปโควตาทุกชนิดของผู้ใช้สำหรับแสดงผลและสำหรับ REST
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
                // ชนิดที่ตัวเลขไม่มีความหมาย (เช่น SFTP ที่มีได้บัญชีเดียวเสมอ) — หน้าจอ
                // แสดงเป็น "เปิด/ปิด" แทน "0 / 5" ซึ่งสื่อผิด · กฎอยู่ที่คลาส Quota ที่เดียว
                'toggle' => Quota::isToggle($type),
                'enabled' => !Quota::isDisabled($limit),
                'in_use' => $usage[$type] > 0,
            ];
        }

        return $summary;
    }
}
