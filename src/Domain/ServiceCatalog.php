<?php

declare (strict_types = 1);

namespace Phpcp\Domain;

use Phpcp\Agent\SelfProtection;

/**
 * รายการ service ที่ panel ยอมให้จัดการ — allowlist ตายตัว
 *
 * นี่คือรายการเดียวกันที่ capability ใช้ตรวจ argument และที่ UI ใช้เรนเดอร์หน้า Services
 * เพิ่ม service ใหม่ต้องมาแก้ที่นี่ที่เดียว และแก้แล้วต้องผ่าน SelfProtection เสมอ
 * — บริการของ panel เองจึงเข้ามาอยู่ในรายการนี้ไม่ได้แม้จะพิมพ์ชื่อลงไป
 */
final class ServiceCatalog
{
    public const KIND_WEBSERVER = 'webserver';
    public const KIND_PHP = 'php';
    public const KIND_DATABASE = 'database';
    public const KIND_SCHEDULER = 'scheduler';
    public const KIND_DNS = 'dns';

    /** เวอร์ชัน PHP ที่ระบบรู้จัก เรียงจากใหม่ไปเก่า */
    public const PHP_VERSIONS = ['8.5', '8.4', '8.3', '8.2', '8.1', '8.0', '7.4'];

    /**
     * เวอร์ชันที่หมดระยะสนับสนุนด้านความปลอดภัยแล้ว — ไม่มีแพตช์ช่องโหว่อีกต่อไป
     *
     * ยังให้เลือกใช้ได้อยู่ เพราะเว็บเก่าจำนวนมากย้ายเวอร์ชันทันทีไม่ได้
     * แต่ศูนย์ความปลอดภัยจะนับเป็นข้อที่ต้องแก้ ไม่ใช่แค่เตือน
     *
     * ต้องปรับรายการนี้ตามปฏิทินของ php.net เมื่อมีเวอร์ชันหมดอายุเพิ่ม
     */
    public const PHP_EOL_VERSIONS = ['7.4', '8.0', '8.1'];

    /**
     * @return array<string,array{label:string,kind:string,critical:bool}>
     */
    public static function all(): array
    {
        $catalog = [
            'apache2' => ['label' => 'Apache', 'kind' => self::KIND_WEBSERVER, 'critical' => true],
            'nginx' => ['label' => 'Nginx', 'kind' => self::KIND_WEBSERVER, 'critical' => true],
            'named' => ['label' => 'BIND9 DNS', 'kind' => self::KIND_DNS, 'critical' => false],
            'bind9' => ['label' => 'BIND9 DNS', 'kind' => self::KIND_DNS, 'critical' => false]
        ];

        foreach (self::PHP_VERSIONS as $version) {
            $catalog[self::fpmUnit($version)] = [
                'label' => 'PHP-FPM '.$version,
                'kind' => self::KIND_PHP,
                'critical' => true
            ];
        }

        $catalog['mariadb'] = ['label' => 'MariaDB', 'kind' => self::KIND_DATABASE, 'critical' => true];
        $catalog['mysql'] = ['label' => 'MySQL', 'kind' => self::KIND_DATABASE, 'critical' => true];
        $catalog['cron'] = ['label' => 'Cron', 'kind' => self::KIND_SCHEDULER, 'critical' => false];

        // กันพลาด: ต่อให้มีใครเผลอเพิ่ม unit ของ panel ลงในรายการข้างบน ก็ถูกตัดทิ้งที่นี่
        return array_filter(
            $catalog,
            static fn(string $unit): bool => !SelfProtection::isProtectedUnit($unit),
            ARRAY_FILTER_USE_KEY,
        );
    }

    /** @return list<string> */
    public static function units(): array
    {
        return array_keys(self::all());
    }

    /**
     * @param string $unit
     */
    public static function isAllowed(string $unit): bool
    {
        return array_key_exists($unit, self::all());
    }

    /**
     * @param string $unit
     */
    public static function label(string $unit): string
    {
        return self::all()[$unit]['label'] ?? $unit;
    }

    /**
     * @param string $unit
     */
    public static function kind(string $unit): string
    {
        return self::all()[$unit]['kind'] ?? 'other';
    }

    /** true = การหยุดบริการนี้ทำให้เว็บไซต์ล่ม ต้องเตือนแรงกว่าปกติ */
    public static function isCritical(string $unit): bool
    {
        return self::all()[$unit]['critical'] ?? false;
    }

    /**
     * @param string $phpVersion
     */
    public static function fpmUnit(string $phpVersion): string
    {
        return 'php'.$phpVersion.'-fpm';
    }

    /** แปลง php8.4-fpm → 8.4 คืน null ถ้าไม่ใช่ unit ของ PHP-FPM */
    public static function phpVersionFromUnit(string $unit): ?string
    {
        return preg_match('/^php(\d\.\d{1,2})-fpm$/', $unit, $m) === 1 ? $m[1] : null;
    }

    /** ชุด service ที่แสดงบนแดชบอร์ดตาม PROMPT.md */
    public static function dashboardUnits(): array
    {
        return ['apache2', 'nginx', 'php8.4-fpm', 'mariadb', 'cron'];
    }
}
