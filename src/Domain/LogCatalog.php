<?php

declare(strict_types=1);

namespace Phpcp\Domain;

/**
 * แหล่ง log ที่ระบบยอมให้อ่าน — allowlist แบบระบุเส้นทางเต็มตายตัว
 *
 * ครอบคลุมทุกชนิดที่ PROMPT.md กำหนด: Access, Error, PHP, System, Login, Audit
 *
 * ความปลอดภัย: ไม่มีทางระบุ path เองได้เลย ผู้ใช้เลือกได้แค่ "คีย์" จากรายการนี้
 * ซึ่งตัดทั้ง path traversal และการอ่านไฟล์ตามใจชอบออกตั้งแต่ต้นทาง
 * ต่างจากการรับ path แล้วมาตรวจทีหลัง ซึ่งเป็นรูปแบบที่พลาดกันบ่อย
 *
 * **แหล่งมีสองชุด** — `all()` คือ log ระดับเครื่องซึ่งเป็นเส้นทางคงที่ ส่วน log ของ
 * แต่ละเว็บไซต์อยู่ในบ้านของเจ้าของ เส้นทางจึงคำนวณจาก {@see \Phpcp\Domain\Site}
 * ตอนใช้งาน ไม่ใช่เขียนตายตัวไว้ที่นี่ · สิ่งที่คงเดิมคือหลักการ: ผู้ใช้ส่ง "คีย์"
 * มาเสมอ (`site:<id>:<ชนิด>`) ไม่เคยส่งเส้นทาง
 */
final class LogCatalog
{
    /** สิทธิ์ที่ต้องมีจึงจะอ่าน log ของเว็บไซต์ได้ — ขอบเขตว่า "เว็บไหนบ้าง" แยกต่างหาก */
    public const SITE_PERMISSION = 'log.view';

    /**
     * @return array<string,array{label:string,path:string,permission:string,format:string,group:string}>
     */
    public static function all(): array
    {
        return [
            /*
             * **สองรายการนี้ไม่ใช่ทราฟฟิกของลูกค้า** — vhost ทุกตัวที่ panel สร้างเขียน
             * `CustomLog`/`ErrorLog` ลงบ้านของเจ้าของเว็บ (ดู `Site::accessLog()`)
             * ไฟล์ระดับเครื่องจึงเหลือแต่คำขอที่ไม่ตรง vhost ไหนเลยกับข้อความระดับ
             * เซิร์ฟเวอร์ · ป้ายเคยเขียนว่า "Access Log (Apache)" เฉย ๆ ซึ่งอ่านแล้ว
             * เข้าใจว่าเป็นทราฟฟิกทั้งเครื่อง แล้วสรุปผิดว่า "ไม่มีใครเข้าเว็บเลย"
             */
            'access' => [
                'label' => 'Access Log (คำขอที่ไม่เข้าเว็บไซต์ใด)',
                'path' => '/var/log/apache2/access.log',
                'permission' => 'log.view',
                'format' => 'access',
                'group' => 'เว็บเซิร์ฟเวอร์',
            ],
            'error' => [
                'label' => 'Error Log (ระดับเซิร์ฟเวอร์)',
                'path' => '/var/log/apache2/error.log',
                'permission' => 'log.view',
                'format' => 'apache',
                'group' => 'เว็บเซิร์ฟเวอร์',
            ],
            'php' => [
                'label' => 'PHP-FPM Log',
                'path' => '/var/log/php8.4-fpm.log',
                'permission' => 'log.view',
                'format' => 'syslog',
                'group' => 'PHP',
            ],
            'mysql' => [
                'label' => 'MariaDB Log',
                'path' => '/var/log/mysql/error.log',
                'permission' => 'log.view',
                'format' => 'syslog',
                'group' => 'ฐานข้อมูล',
            ],
            'system' => [
                'label' => 'System Log',
                'path' => '/var/log/syslog',
                'permission' => 'log.view',
                'format' => 'syslog',
                'group' => 'ระบบ',
            ],
            'auth' => [
                'label' => 'Login Log (SSH / sudo)',
                'path' => '/var/log/auth.log',
                'permission' => 'log.view',
                'format' => 'syslog',
                'group' => 'ระบบ',
            ],

            // log ของ panel เอง — อ่านได้ แก้ไม่ได้
            // ไม่ขัดกับ SelfProtection เพราะที่นั่นห้าม "แก้ไข" ส่วนที่นี่เป็น allowlist
            // แบบระบุไฟล์เจาะจง อ่านอย่างเดียว และยังต้องผ่าน permission อีกชั้น
            //
            // panel_log บอกให้ LogTail หาเส้นทางจริงจาก Paths แทนค่าคงที่ด้านล่าง
            // เพราะ layout แบบ portable วาง log ไว้ในโฟลเดอร์โปรเจกต์ ไม่ใช่ /var/log
            'panel' => [
                'label' => 'Control Panel Log',
                'path' => '/var/log/phpcp/panel.log',
                'panel_log' => 'panel',
                'permission' => 'log.view',
                'format' => 'phpcp',
                'group' => 'Control Panel',
            ],
            'agent' => [
                'label' => 'Agent Log',
                'path' => '/var/log/phpcp/agent.log',
                'panel_log' => 'agent',
                'permission' => 'log.view',
                'format' => 'phpcp',
                'group' => 'Control Panel',
            ],
            'audit' => [
                'label' => 'Audit Log',
                'path' => '/var/log/phpcp/audit.log',
                'panel_log' => 'audit',
                'permission' => 'audit.view',
                'format' => 'json',
                'group' => 'Control Panel',
            ],
        ];
    }

    public static function has(string $key): bool
    {
        return array_key_exists($key, self::all());
    }

    /**
     * log ที่แต่ละเว็บไซต์มีเป็นของตัวเอง — คีย์ → ป้ายและรูปแบบ
     *
     * `php` เป็นของ **บัญชี × เวอร์ชัน PHP** ไม่ใช่ของเว็บเดียว (pool ใช้ร่วมกันตั้งแต่
     * migration 0006) เว็บพี่น้องของเจ้าของคนเดียวกันที่ใช้ PHP รุ่นเดียวกันจึงชี้ไป
     * ไฟล์เดียวกัน · ป้ายบอกไว้ตรง ๆ ดีกว่าปล่อยให้งงว่าทำไม log ของเว็บ ก. มีของเว็บ ข.
     *
     * @return array<string,array{label:string,format:string}>
     */
    public static function siteKinds(): array
    {
        return [
            'access' => ['label' => 'Access Log', 'format' => 'access'],
            'error' => ['label' => 'Error Log', 'format' => 'apache'],
            'php' => ['label' => 'PHP Error Log (ทั้งบัญชี)', 'format' => 'syslog'],
        ];
    }

    /** คีย์ของ log รายเว็บ — รูปแบบเดียวที่ {@see self::parseSiteKey()} ยอมรับ */
    public static function siteKey(int $siteId, string $kind): string
    {
        return 'site:'.$siteId.':'.$kind;
    }

    /**
     * แกะคีย์ของ log รายเว็บ — คืน null ถ้าไม่ใช่รูปแบบนี้
     *
     * ชนกับคีย์ระดับเครื่องไม่ได้เลยเพราะคีย์เหล่านั้นถูกบังคับเป็น `^[a-z][a-z0-9_]+$`
     * ซึ่งไม่มี `:` (มีเทสต์เฝ้าอยู่ใน tests/security/ServerBoundaryTest.php)
     *
     * **การแกะได้ไม่ได้แปลว่ามีเว็บนั้นอยู่จริงหรืออ่านได้** — ที่นี่ตรวจแค่รูปทรง
     * ส่วนตัวตนกับสิทธิ์เป็นเรื่องของผู้เรียกซึ่งมีฐานข้อมูลอยู่ในมือ
     *
     * @return array{site_id:int,kind:string}|null
     */
    public static function parseSiteKey(string $key): ?array
    {
        if (preg_match('/^site:([1-9][0-9]{0,8}):([a-z]+)$/', $key, $matches) !== 1) {
            return null;
        }

        if (!array_key_exists($matches[2], self::siteKinds())) {
            return null;
        }

        return ['site_id' => (int) $matches[1], 'kind' => $matches[2]];
    }

    /** @return array{label:string,path:string,permission:string,format:string,group:string}|null */
    public static function get(string $key): ?array
    {
        return self::all()[$key] ?? null;
    }

    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys(self::all());
    }

    /**
     * แหล่ง log ที่บทบาทนี้เปิดดูได้
     *
     * @return array<string,array{label:string,path:string,permission:string,format:string,group:string}>
     */
    public static function forRole(string $role): array
    {
        return array_filter(
            self::all(),
            static fn (array $source): bool => \Phpcp\Security\Permissions::roleHas($role, $source['permission']),
        );
    }

    /**
     * ระดับความรุนแรงของบรรทัด log — ใช้ระบายสีใน viewer
     * ตรวจจากคำที่ปรากฏจริงในรูปแบบ log ของแต่ละบริการ
     */
    public static function levelOf(string $line): string
    {
        $lower = strtolower($line);

        return match (true) {
            str_contains($lower, '[error]') || str_contains($lower, 'error')
                || str_contains($lower, 'fatal') || str_contains($lower, 'critical')
                || str_contains($lower, 'denied') || str_contains($lower, ' 500 ') => 'error',

            str_contains($lower, '[warn]') || str_contains($lower, 'warning')
                || str_contains($lower, 'deprecated') || str_contains($lower, ' 404 ')
                || str_contains($lower, ' 403 ') => 'warn',

            str_contains($lower, '[notice]') || str_contains($lower, 'notice')
                || str_contains($lower, '[info]') => 'info',

            str_contains($lower, ' 200 ') || str_contains($lower, '"ok"')
                || str_contains($lower, 'started') => 'ok',

            default => '',
        };
    }

    /** @return list<string> ระดับที่ให้เลือกกรองใน UI */
    public static function levels(): array
    {
        return ['error', 'warn', 'info', 'ok'];
    }

    public static function levelLabel(string $level): string
    {
        return match ($level) {
            'error' => 'ข้อผิดพลาด',
            'warn' => 'คำเตือน',
            'info' => 'ข้อมูล',
            'ok' => 'สำเร็จ',
            default => 'ทั้งหมด',
        };
    }
}
