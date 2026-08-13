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
    public const KIND_MAIL = 'mail';
    public const KIND_ACCESS = 'access';

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

        /*
         * SSH — ขาดจากรายการนี้มาตลอด ทั้งที่เป็นบริการที่ผู้ดูแลต้องมองเห็นมากที่สุด
         *
         * SFTP ของลูกค้าทุกรายวิ่งบนตัวนี้ (ดู SftpAccessManager) เมื่อมันไม่โผล่ในหน้า
         * Services ผู้ดูแลจึงไม่มีทางรู้จากหน้าเว็บเลยว่า SSH อยู่ในสภาพไหน และไม่มีทาง
         * สั่ง start/restart ได้ — ต้องไปหาเครื่องหรือ ssh เข้าไปเอง ซึ่งเป็นไปไม่ได้พอดี
         * ในกรณีที่ SSH นั่นแหละเป็นตัวที่พัง
         *
         * `critical` = true เพราะนี่คือทางเข้าเครื่องทางเดียวที่เหลือเมื่อ panel ล่ม
         * · `sshd` สำหรับ RHEL, `ssh` สำหรับ Debian/Ubuntu — เครื่องหนึ่งมีตัวเดียว
         * อีกตัวจะรายงานเป็น not_installed แล้วหน้าจอกรองทิ้งเอง (แบบเดียวกับ named/bind9)
         */
        $catalog['ssh'] = ['label' => 'SSH / SFTP', 'kind' => self::KIND_ACCESS, 'critical' => true];
        $catalog['sshd'] = ['label' => 'SSH / SFTP', 'kind' => self::KIND_ACCESS, 'critical' => true];

        /*
         * เมล (PLAN-MAIL) — สามเดมอนนี้เพิ่มเข้ามาในเฟส M1–M3 แต่ไม่เคยโผล่ในหน้าบริการเลย
         * ผู้ดูแลจึงมองไม่เห็นว่ามันมีอยู่ ไม่รู้ว่าตัวไหนทำงาน และสั่งเริ่มใหม่จากหน้าเว็บไม่ได้
         *
         * **critical ไม่เท่ากันโดยตั้งใจ:**
         *   postfix  จริงทุกเครื่อง — เป็นทางออกของเมลแจ้งเตือนด้วย ไม่ใช่แค่เมลโฮสติ้ง
         *            ดับเมื่อไหร่แปลว่าข้อความเตือนทุกฉบับส่งไม่ออกและไม่มีใครรู้
         *   dovecot  ติดมากับ postfix ทุกเครื่อง แต่มีความหมายเฉพาะเครื่องที่เปิดเมลโฮสติ้ง
         *   rspamd   เมลยังส่งได้ปกติเมื่อมันล่ม แค่ไม่มีลายเซ็น DKIM
         *            (`milter_default_action = accept` — ดู hosting.cf.tpl)
         *
         * สองตัวหลังจึงไม่ปลุกใครกลางดึกบนเครื่องที่ไม่ได้ทำเมลโฮสติ้ง — ยังเห็นสถานะ
         * และสั่งงานได้จากหน้าบริการเหมือนกัน
         */
        $catalog['postfix'] = ['label' => 'Postfix (SMTP)', 'kind' => self::KIND_MAIL, 'critical' => true];
        $catalog['dovecot'] = ['label' => 'Dovecot (IMAP/POP3)', 'kind' => self::KIND_MAIL, 'critical' => false];
        $catalog['rspamd'] = ['label' => 'rspamd (สแปม/DKIM)', 'kind' => self::KIND_MAIL, 'critical' => false];

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

    /**
     * ชุด service ที่แสดงบนแดชบอร์ด
     *
     * ชุดเดิมตาม PROMPT.md มีแค่ apache2/nginx/php-fpm/mariadb/cron — เขียนไว้ก่อนเฟสเมล
     * (PLAN-MAIL) จะเพิ่ม postfix/dovecot/rspamd เข้า all() เลยไม่เคยถูกดึงมาแสดงที่นี่
     * ทั้งที่ postfix เป็นทางออกของเมลแจ้งเตือนของ panel เองด้วย จึงต้องอยู่ในหน้าแรก
     */
    public static function dashboardUnits(): array
    {
        return ['apache2', 'nginx', 'php8.4-fpm', 'mariadb', 'cron', 'postfix', 'dovecot', 'rspamd'];
    }
}
