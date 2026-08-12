<?php

declare(strict_types=1);

namespace Phpcp\Domain;

use Phpcp\Agent\ValidationError;
use Phpcp\Kernel\Db;

/**
 * ค่าตั้งที่แก้ได้จากหน้าเว็บ — เก็บในตาราง `settings`
 *
 * แยกจาก `/etc/phpcp/config.php` โดยเจตนา สองที่นี้ทำคนละหน้าที่:
 *
 *   config.php  ค่าที่ต้องมีก่อน panel จะทำงานได้ (พอร์ต, secret key, layout)
 *               ผู้ดูแลแก้ที่ไฟล์ ต้องรีสตาร์ตบริการ — และ web tier เขียนไม่ได้เลย
 *
 *   settings    ค่าที่เปลี่ยนได้ระหว่างใช้งานและไม่กระทบการบูต (การแจ้งเตือน, เมล)
 *               แก้จากหน้าเว็บได้ มีผลทันที
 *
 * เหตุผลที่ไม่ยุบรวม: ถ้าให้หน้าเว็บเขียน config.php ได้ ก็เท่ากับให้ web tier
 * เขียนไฟล์ที่ตัวเองอ่านตอนบูต — ช่องโหว่เดียวในหน้าตั้งค่าจะกลายเป็นการรันโค้ดทันที
 * เพราะ config.php เป็นไฟล์ PHP ที่ถูก include
 */
final class SettingsRepository
{
    /**
     * คีย์ที่ยอมให้เก็บ พร้อมชนิดของค่า
     *
     * allowlist ตายตัวเหมือนทะเบียน capability — คีย์ที่ไม่อยู่ในนี้ถูกปฏิเสธ
     * ไม่ใช่เก็บไว้เฉย ๆ ป้องกันการยัดค่าแปลกปลอมเข้าฐานข้อมูลผ่านฟอร์มที่ถูกดัดแปลง
     *
     * @var array<string,string>
     */
    private const KEYS = [
        // การแจ้งเตือนผ่าน Telegram
        'notify.telegram.enabled' => 'bool',
        'notify.telegram.token' => 'secret',
        'notify.telegram.chat_id' => 'string',

        // เลือกว่าเรื่องไหนควรแจ้ง — ไม่ใช่แจ้งทุกอย่างจนคนเลิกอ่าน
        'notify.events.security' => 'bool',
        'notify.events.ssl' => 'bool',
        'notify.events.service' => 'bool',
        'notify.events.backup' => 'bool',
        'notify.events.login' => 'bool',
        'notify.events.quota' => 'bool',
        'notify.events.alert' => 'bool',

        // แจ้งเตือนทางอีเมล — ใช้ Postfix ที่มีอยู่ · ผู้ส่งใช้ `mail.from` ร่วมกับเมลขาออก
        'notify.email.enabled' => 'bool',
        'notify.email.to' => 'string',

        // แจ้งเตือนผ่าน webhook — ต่อเข้า Slack/Discord/ระบบ ticket ที่ผู้ดูแลใช้อยู่แล้ว
        'notify.webhook.enabled' => 'bool',
        'notify.webhook.url' => 'string',
        // ใช้ลงลายเซ็น HMAC ให้ปลายทางตรวจว่าข้อความมาจากเครื่องนี้จริง
        'notify.webhook.secret' => 'secret',

        // เมลขาออก
        'mail.enabled' => 'bool',
        'mail.mode' => 'string',        // 'local' | 'relay'
        'mail.from' => 'string',
        'mail.relay_host' => 'string',
        'mail.relay_port' => 'int',
        'mail.relay_user' => 'string',
        'mail.relay_password' => 'secret',
        'mail.relay_tls' => 'bool',

        // เมลโฮสติ้ง (PLAN-MAIL) — ชื่อโฮสต์ที่ประกาศตัวตอนคุยกับเซิร์ฟเวอร์อื่น และ
        // ใบรับรองของชื่อนั้น · ว่าง = อนุมานจาก mail.from แล้วใช้ใบของดิสโทรไปก่อน
        'mail.hostname' => 'string',
        'mail.tls_cert' => 'string',
        'mail.tls_key' => 'string',

        // เว็บเซิร์ฟเวอร์ที่โฮสต์เว็บของลูกค้า — ย้ายมาจาก config.php เพื่อให้เปลี่ยนได้
        // จากหน้าจอ · ค่าว่าง = ใช้ค่าใน config.php (เครื่องที่ติดตั้งไว้ก่อนหน้านี้)
        'webserver.mode' => 'string',       // 'apache' | 'nginx' | 'nginx-proxy'
        'webserver.static_by_nginx' => 'bool',

        // DNS — ต้องตั้งจากหน้าจอได้ ไม่ใช่ให้ผู้ดูแลไปแก้ไฟล์เอง
        'dns.enabled' => 'bool',
        'dns.nameservers' => 'string',      // คั่นด้วยคอมมา เช่น ns1.example.com,ns2.example.com
        'dns.soa_email' => 'string',
    ];

    /** ค่าเริ่มต้นเมื่อยังไม่เคยตั้ง */
    private const DEFAULTS = [
        'notify.telegram.enabled' => '0',
        'notify.telegram.token' => '',
        'notify.telegram.chat_id' => '',
        'notify.events.security' => '1',
        'notify.events.ssl' => '1',
        'notify.events.service' => '1',
        'notify.events.backup' => '0',
        'notify.events.login' => '1',
        'notify.events.quota' => '1',
        'notify.events.alert' => '1',
        'notify.email.enabled' => '0',
        'notify.email.to' => '',
        'notify.webhook.enabled' => '0',
        'notify.webhook.url' => '',
        'notify.webhook.secret' => '',
        'mail.enabled' => '0',
        'mail.mode' => 'local',
        'mail.from' => '',
        'mail.relay_host' => '',
        'mail.relay_port' => '587',
        'mail.relay_user' => '',
        'mail.relay_password' => '',
        'mail.relay_tls' => '1',
        'mail.hostname' => '',
        'mail.tls_cert' => '',
        'mail.tls_key' => '',

        // ว่าง = ยังไม่เคยเลือกจากหน้าจอ ให้ถอยไปอ่านค่าใน config.php ตามเดิม
        'webserver.mode' => '',
        // ให้ nginx ตอบไฟล์ static เองเป็นค่าเริ่มต้น — นั่นคือเหตุผลที่มี nginx อยู่
        'webserver.static_by_nginx' => '1',
        'dns.enabled' => '0',
        'dns.nameservers' => '',
        'dns.soa_email' => '',
    ];

    /**
     * คีย์ที่ฟอร์มตั้งค่าทั่วไปแก้ได้ — **ไม่รวม `webserver.*`**
     *
     * ค่าของเว็บเซิร์ฟเวอร์เปลี่ยนแล้วต้องเขียนไฟล์ vhost ใหม่ทั้งเครื่องและรีสตาร์ต
     * บริการตามลำดับที่ถูกต้อง · ถ้ายอมให้ PATCH /settings เขียนค่านี้ได้ตรง ๆ
     * จะได้เครื่องที่ "ค่าตั้งบอกว่า nginx แต่ไฟล์บนดิสก์ยังเป็นของ Apache"
     * ซึ่งเป็นสภาพที่ผู้ดูแลมองไม่ออกว่าอะไรเป็นอะไร — ต้องผ่าน `webserver.apply` เท่านั้น
     *
     * @return array<string,string>
     */
    public static function webEditableKeys(): array
    {
        return array_filter(
            self::keys(),
            static fn (string $key): bool => !str_starts_with($key, 'webserver.'),
            ARRAY_FILTER_USE_KEY,
        );
    }

    public function __construct(private readonly Db $db)
    {
    }

    /** @return array<string,string> ค่าทั้งหมด รวมค่าเริ่มต้นของคีย์ที่ยังไม่เคยตั้ง */
    public function all(): array
    {
        $values = self::DEFAULTS;

        foreach ($this->db->all('SELECT key, value FROM settings') as $row) {
            $key = (string) $row['key'];

            // คีย์ที่ไม่รู้จักถูกข้าม ไม่ใช่ส่งต่อไปให้หน้าจอ — กันค่าเก่าที่เลิกใช้แล้ว
            // หรือค่าที่ถูกยัดเข้าฐานข้อมูลจากทางอื่นโผล่ขึ้นมาบนหน้าเว็บ
            if (isset(self::KEYS[$key])) {
                $values[$key] = (string) $row['value'];
            }
        }

        return $values;
    }

    public function get(string $key, string $default = ''): string
    {
        if (!isset(self::KEYS[$key])) {
            throw new ValidationError("ไม่รู้จักค่าตั้ง {$key}");
        }

        $row = $this->db->first('SELECT value FROM settings WHERE key = :k', ['k' => $key]);

        return $row === null ? (self::DEFAULTS[$key] ?? $default) : (string) $row['value'];
    }

    public function bool(string $key): bool
    {
        return $this->get($key) === '1';
    }

    public function int(string $key): int
    {
        return (int) $this->get($key);
    }

    /**
     * บันทึกหลายค่าพร้อมกัน
     *
     * @param array<string,string> $values
     */
    public function save(array $values): void
    {
        foreach ($values as $key => $value) {
            if (!isset(self::KEYS[$key])) {
                throw new ValidationError("ไม่รู้จักค่าตั้ง {$key}");
            }

            $this->db->run(
                'INSERT INTO settings (key, value, updated_at) VALUES (:k, :v, :t)
                 ON CONFLICT(key) DO UPDATE SET value = :v, updated_at = :t',
                ['k' => $key, 'v' => (string) $value, 't' => time()],
            );
        }
    }

    /** คีย์ที่เป็นความลับ — ห้ามส่งค่าจริงกลับไปแสดงบนหน้าจอ */
    public static function isSecret(string $key): bool
    {
        return (self::KEYS[$key] ?? '') === 'secret';
    }

    /** @return array<string,string> */
    public static function keys(): array
    {
        return self::KEYS;
    }

    /**
     * ปิดบังค่าที่เป็นความลับก่อนส่งไปหน้าจอ
     *
     * ส่งเฉพาะ "มีค่าอยู่หรือไม่" ไม่ส่งตัวค่า — token ของบอทที่หลุดออกไปทาง HTML
     * แปลว่าใครก็ส่งข้อความในนามระบบได้ และมันจะติดอยู่ในแคชของเบราว์เซอร์
     * กับประวัติของ proxy ไปอีกนาน
     *
     * @param array<string,string> $values
     * @return array<string,string>
     */
    public static function mask(array $values): array
    {
        foreach ($values as $key => $value) {
            if (self::isSecret($key)) {
                $values[$key] = $value === '' ? '' : '********';
            }
        }

        return $values;
    }
}
