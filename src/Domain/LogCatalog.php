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
 */
final class LogCatalog
{
    /**
     * @return array<string,array{label:string,path:string,permission:string,format:string,group:string}>
     */
    public static function all(): array
    {
        return [
            'access' => [
                'label' => 'Access Log (Apache)',
                'path' => '/var/log/apache2/access.log',
                'permission' => 'log.view',
                'format' => 'access',
                'group' => 'เว็บเซิร์ฟเวอร์',
            ],
            'error' => [
                'label' => 'Error Log (Apache)',
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
