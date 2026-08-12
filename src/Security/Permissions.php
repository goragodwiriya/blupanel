<?php
declare (strict_types = 1);

namespace Phpcp\Security;

/**
 * แผนที่ role → permission ตาม ARCHITECTURE §8
 *
 * ไฟล์นี้เป็นแหล่งความจริงเดียว ใช้ทั้งที่ middleware (ชั้น 1) และที่ agent (ชั้น 2)

 * ตรวจสองที่โดยเจตนา — ชั้น 1 กันไม่ให้เรนเดอร์ปุ่มและกันเปิด URL ตรง ๆ
 * ส่วนชั้น 2 กันกรณีที่ชั้น 1 มีบั๊กจนหลุดมาได้
 *
 * ข้อสังเกตที่บันทึกไว้ให้ตรงกับความจริง: agent คำนวณ permission จาก role ที่ web tier ส่งมา
 * ถ้า web tier ถูกยึดทั้งตัว ผู้โจมตีปลอม role ได้ ขอบเขตที่แท้จริงจึงคือรายการ capability
 * กับ validator ของมัน (SECURITY §2.4) ซึ่งยังกันอยู่แม้ในกรณีนั้น
 */
final class Permissions
{
    public const SUPERADMIN = 'superadmin';
    public const SYSADMIN = 'sysadmin';
    public const WEBADMIN = 'webadmin';

    /** @return array<string,string> role => ชื่อที่แสดงใน UI */
    public static function roleLabels(): array
    {
        return [
            self::SUPERADMIN => 'ผู้ดูแลระบบ',
            self::SYSADMIN => 'ผู้ดูแลเซิร์ฟเวอร์',
            self::WEBADMIN => 'ผู้ดูแลเว็บไซต์'
        ];
    }

    /**
     * @param string $role
     */
    public static function roleLabel(string $role): string
    {
        return self::roleLabels()[$role] ?? $role;
    }

    /** permission ทั้งหมดที่มีในระบบ พร้อมคำอธิบายไทย */
    public static function all(): array
    {
        return [
            'dashboard.view' => 'ดูแดชบอร์ด',

            // Hosting
            'site.view' => 'ดูเว็บไซต์',
            'site.create' => 'สร้างเว็บไซต์',
            'site.edit' => 'แก้ไขเว็บไซต์',
            'site.suspend' => 'ระงับเว็บไซต์',
            'site.delete' => 'ลบเว็บไซต์',
            'domain.view' => 'ดูโดเมน',
            'domain.manage' => 'จัดการโดเมนและ DNS',
            'ssl.view' => 'ดู SSL Certificates',
            'ssl.manage' => 'ติดตั้งและต่ออายุ SSL',
            'php.view' => 'ดูข้อมูล PHP',
            'php.manage' => 'ติดตั้ง/ถอน PHP extension และตั้งค่า PHP',
            'db.view' => 'ดูฐานข้อมูล',
            'db.manage' => 'จัดการฐานข้อมูลและผู้ใช้ฐานข้อมูล',
            'file.view' => 'ดูไฟล์',
            'file.manage' => 'แก้ไขไฟล์',
            'cron.view' => 'ดูงานอัตโนมัติ',
            'cron.manage' => 'จัดการงานอัตโนมัติ',
            'mail.view' => 'ดูกล่องจดหมาย',
            'mail.manage' => 'จัดการกล่องจดหมายและที่อยู่ส่งต่อ',
            'backup.view' => 'ดูข้อมูลสำรอง',
            'backup.manage' => 'สร้างและลบข้อมูลสำรอง',
            'backup.restore' => 'กู้คืนข้อมูล',

            // แยกจาก backup.manage โดยเจตนา — `backup.manage` เป็นสิทธิ์ของ **หมวด
            // Hosting** ที่ผู้ดูแลเว็บไซต์มี (สร้าง/ลบไฟล์สำรองของเว็บตัวเอง) ส่วนปลายทาง
            // นอกเครื่องกับตารางเวลาเป็นของ **ทั้งเครื่อง**: ปลายทางหนึ่งที่รับไฟล์สำรอง
            // ของลูกค้าทุกราย และตารางเวลารันในนามของ "ระบบ" · ใช้สิทธิ์เดียวกันไม่ได้
            'backup.offsite' => 'จัดการปลายทางสำรองและตารางเวลาสำรองอัตโนมัติ',

            // Customer
            'customer.view' => 'ดูลูกค้า',
            'customer.manage' => 'จัดการลูกค้า (สร้าง/แก้ไข/ลบ)',

            // Server
            'server.view' => 'ดูภาพรวมเซิร์ฟเวอร์',
            'service.view' => 'ดูสถานะบริการ',
            'service.control' => 'สั่งงานบริการ (start/stop/restart/reload)',
            'security.view' => 'ดูศูนย์ความปลอดภัย',
            'security.manage' => 'แก้ไขการตั้งค่าความปลอดภัย',
            'firewall.view' => 'ดู Firewall',
            'firewall.manage' => 'จัดการกฎ Firewall',
            'ssh.view' => 'ดูการตั้งค่า SSH',
            'ssh.manage' => 'แก้ไขการตั้งค่า SSH',
            'log.view' => 'ดู Logs',

            // แยกจาก domain.manage โดยเจตนา — domain.manage เป็นสิทธิ์ของ**หมวด Hosting**
            // ที่ผู้ดูแลเว็บไซต์มี (แก้ DNS record ของโดเมนตัวเอง ซึ่งไปสั่งเขียน zone
            // ของโดเมนนั้นทีละโดเมนอัตโนมัติอยู่แล้ว) ส่วนการ "สั่งซิงก์ทุกโดเมนใหม่ทั้งหมด"
            // (`dns.reload`) กระทบ zone ของลูกค้าทุกรายพร้อมกันและแก้ named.conf.local ทั้งไฟล์
            // จึงเป็นสิทธิ์ของทั้งเครื่องแบบเดียวกับ backup.offsite — ใช้สิทธิ์เดียวกันไม่ได้
            'dns.manage' => 'สั่งซิงก์ DNS zone ทั้งหมดกับ BIND9',
            'user.view' => 'ดูผู้ใช้งานระบบ',
            'user.manage' => 'จัดการผู้ใช้งานระบบ',
            'settings.view' => 'ดูการตั้งค่าเซิร์ฟเวอร์',
            'settings.manage' => 'แก้ไขการตั้งค่าเซิร์ฟเวอร์',
            'audit.view' => 'ดู Audit Log'
        ];
    }

    /**
     * permission ของแต่ละ role
     *
     * @return list<string>
     */
    public static function forRole(string $role): array
    {
        return match ($role) {
            self::SUPERADMIN => array_keys(self::all()),

            // Server ทั้งหมด + Hosting แบบดูอย่างเดียว + Customer
            self::SYSADMIN => [
                'dashboard.view',
                'site.view', 'domain.view', 'ssl.view', 'php.view', 'php.manage',
                'db.view', 'file.view', 'cron.view', 'backup.view', 'mail.view',
                'customer.view', 'customer.manage',
                'server.view',
                'service.view', 'service.control',
                'security.view', 'security.manage',
                'firewall.view', 'firewall.manage',
                'ssh.view', 'ssh.manage',
                'log.view',
                'user.view',
                'settings.view',
                'audit.view',
                'backup.offsite',
                'dns.manage'
            ],

            // เฉพาะเว็บไซต์ของตัวเอง — ไม่มี permission ของหมวด SERVER แม้แต่ตัวเดียว
            self::WEBADMIN => [
                'dashboard.view',
                'site.view', 'site.edit',
                'domain.view', 'domain.manage',
                'ssl.view', 'ssl.manage',
                'php.view',
                'db.view', 'db.manage',
                'file.view', 'file.manage',
                'cron.view', 'cron.manage',
                'mail.view', 'mail.manage',
                'backup.view', 'backup.manage'
            ],

            default => [],
        };
    }

    /**
     * @param string $role
     * @param string $permission
     */
    public static function roleHas(string $role, string $permission): bool
    {
        return in_array($permission, self::forRole($role), true);
    }

    /**
     * @param string $role
     */
    public static function isValidRole(string $role): bool
    {
        return array_key_exists($role, self::roleLabels());
    }

    /**
     * role ที่เห็นเมนูหมวด SERVER — ใช้ตัดสินใจตอนเรนเดอร์ sidebar
     * ให้ผลตรงกับ permission เสมอเพราะคำนวณจากแหล่งเดียวกัน
     */
    public static function seesServerSection(string $role): bool
    {
        return self::roleHas($role, 'server.view');
    }
}
