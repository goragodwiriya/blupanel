<?php

declare (strict_types = 1);

namespace Phpcp\Kernel;

/**
 * ค่าตั้งของระบบ อ่านครั้งเดียวตอน bootstrap
 *
 * ลำดับการค้นหาไฟล์ config:
 *   1. ตัวแปรสภาพแวดล้อม PHPCP_CONFIG
 *   2. /etc/phpcp/config.php            (ติดตั้งแบบ system)
 *   3. <root>/etc/config.php            (portable)
 *   ไม่พบเลย → ใช้ค่าเริ่มต้นแบบ portable + sandbox เพื่อให้ clone แล้วรันได้ทันที
 */
final class Config
{
    /** @var list<string> ไฟล์ config ที่มีอยู่แต่อ่านไม่ได้ — ดู locate() */
    private static array $unreadable = [];

    /** @param array<string,mixed> $values */
    private function __construct(
        private readonly array $values,
        public readonly Paths $paths,
        public readonly Mode $mode,
        public readonly ?string $sourceFile,
    ) {
    }

    /**
     * @param string $root
     */
    public static function load(string $root): self
    {
        $file = self::locate($root);
        $values = [];

        if ($file !== null) {
            $loaded = require $file;
            if (!is_array($loaded)) {
                throw new \RuntimeException("ไฟล์ config ต้อง return array: {$file}");
            }
            $values = $loaded;
        }

        $values = array_replace_recursive(self::defaults(), $values);

        // ต้องมาก่อนสร้าง Paths — ทั้ง Paths และ Site อ่านค่านี้จากที่เดียวกัน
        Paths::useSitesDir((string) (($values['sites'] ?? [])['dir'] ?? ''));
        Paths::useUsersDir((string) (($values['sites'] ?? [])['users_dir'] ?? ''));

        $layout = (string) ($values['layout'] ?? '');
        $paths = $layout !== ''
            ? Paths::forLayout($layout, $root)
            : Paths::detect($root);

        // อนุญาตให้ override เฉพาะตอนพัฒนา — production ต้องมาจากไฟล์เท่านั้น
        $modeRaw = (string) ($values['mode'] ?? Mode::Sandbox->value);
        $mode = Mode::tryFrom($modeRaw) ?? throw new \RuntimeException("โหมดไม่ถูกต้อง: {$modeRaw} (ใช้ได้: production, sandbox, dryrun)");

        return new self($values, $paths, $mode, $file);
    }

    /**
     * @param string $root
     * @return mixed
     */
    private static function locate(string $root): ?string
    {
        $candidates = array_filter([
            getenv('PHPCP_CONFIG') ?: null,
            '/etc/phpcp/config.php',
            $root.'/etc/config.php'
        ]);

        foreach ($candidates as $candidate) {
            if (is_file($candidate) && is_readable($candidate)) {
                return $candidate;
            }

            // มีไฟล์อยู่แต่อ่านไม่ได้ = ปัญหาสิทธิ์ ไม่ใช่ "ยังไม่ได้ติดตั้ง" — จำไว้บอกผู้ดูแล
            //
            // ถ้าเงียบไปเฉย ๆ ระบบจะถอยไปใช้ค่าเริ่มต้นซึ่งเป็น sandbox + ไม่มีฐานข้อมูล
            // แล้วทุกหน้าจอจะดู "ว่างเปล่าแต่ปกติ" ทั้งที่ config จริงมีอยู่ครบ
            // (เกิดขึ้นจริงตอน install.sh ลืม chown config.php เป็น root:phpcp)
            if (is_file($candidate)) {
                self::$unreadable[] = $candidate;
            }
        }

        return null;
    }

    /**
     * ไฟล์ config ที่มีอยู่จริงแต่โปรเซสนี้อ่านไม่ได้ — ว่างเมื่อไม่มีปัญหาสิทธิ์
     *
     * @return list<string>
     */
    public static function unreadableCandidates(): array
    {
        return self::$unreadable;
    }

    /** @return array<string,mixed> */
    public static function defaults(): array
    {
        return [
            'mode' => Mode::Sandbox->value,
            'layout' => '',
            'panel' => [
                'port' => 8443,
                'base_url' => '',
                // __Host- prefix ต้องใช้คู่กับ Secure เท่านั้น dev บน http จึงต้องปิดได้
                'cookie_secure' => false,
                'session_ttl' => 28800, // 8 ชั่วโมง
                'session_idle' => 1800, // 30 นาที
                'session_rotate' => 900, // หมุน id ทุก 15 นาที
                'ip_allowlist' => [], // ว่าง = ไม่จำกัด
                'trusted_proxies' => []
            ],
            'agent' => [
                'socket' => '', // ว่าง = ใช้ค่าจาก Paths
                'timeout' => 30,
                // uid ที่อนุญาตให้ต่อ socket ได้ ตรวจด้วย SO_PEERCRED (ว่าง = ไม่ตรวจ)
                'allowed_uids' => []
            ],
            'sandbox' => [
                'prefix' => '' // ว่าง = ใช้ค่าจาก Paths
            ],
            'sites' => [
                'dir' => Paths::DEFAULT_SITES_DIR,
                // บ้านของผู้ใช้โฮสติ้ง — ไฟล์เว็บอยู่ที่ <users_dir>/<ผู้ใช้>/domains/<โดเมน>/
                'users_dir' => Paths::DEFAULT_USERS_DIR,
                'shared_owner' => false, // ดูคำอธิบายที่ sharedOwner()
                'pointer_roots' => [] // โฟลเดอร์นอก sites.dir ที่ยอมให้ชี้ docroot เข้าไปได้
            ],
            'dns' => [
                // ปิดไว้เป็นค่าเริ่มต้นโดยตั้งใจ — เปิดเองหลังตรวจสอบว่าเครื่องนี้มี BIND9
                // พร้อมใช้งานจริงแล้วเท่านั้น (PLAN-V2 เฟส E3) ดูคำอธิบายที่ dnsEnabled()
                'enabled' => false,
                'zone_dir' => '/etc/bind/zones',
                'named_conf_local' => '/etc/bind/named.conf.local',
                // ต้องมีอย่างน้อยหนึ่งเครื่องก่อนจะสร้าง zone ได้เลย — BIND9 ปฏิเสธ zone
                // ที่ไม่มี NS record อยู่แล้วโดยธรรมชาติของโปรโตคอล
                'nameservers' => [],
                // อีเมลผู้ดูแล DNS ในฟอร์แมต SOA (จุดแทน @) เช่น hostmaster.example.com
                'soa_email' => '',
            ],
            'security' => [
                'secret_key' => '', // base64 32 byte — ใช้เข้ารหัส TOTP secret
                'require_2fa_roles' => ['superadmin', 'sysadmin'],
                'max_login_attempts' => 5,
                'lockout_seconds' => 900,
                'password_min_length' => 12
            ],
            'log' => [
                'level' => 'info' // debug | info | warn | error
            ]
        ];
    }

    /** อ่านค่าด้วย dot notation เช่น get('panel.port') */
    public function get(string $key, mixed $default = null): mixed
    {
        $value = $this->values;
        foreach (explode('.', $key) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }

        return $value;
    }

    /**
     * @param string $key
     * @param int $default
     */
    public function int(string $key, int $default = 0): int
    {
        $value = $this->get($key, $default);

        return is_numeric($value) ? (int) $value : $default;
    }

    /**
     * @param string $key
     * @param bool $default
     */
    public function bool(string $key, bool $default = false): bool
    {
        $value = $this->get($key, $default);

        return is_bool($value) ? $value : (bool) $value;
    }

    /**
     * @param string $key
     * @param string $default
     */
    public function string(string $key, string $default = ''): string
    {
        $value = $this->get($key, $default);

        return is_scalar($value) ? (string) $value : $default;
    }

    /** @return list<string> */
    public function list(string $key): array
    {
        $value = $this->get($key, []);

        return is_array($value) ? array_values(array_map(strval(...), $value)) : [];
    }

    /**
     * @return mixed
     */
    public function agentSocket(): string
    {
        $configured = $this->string('agent.socket');

        return $configured !== '' ? $configured : $this->paths->agentSocket();
    }

    /**
     * @return mixed
     */
    public function sandboxPrefix(): string
    {
        $configured = $this->string('sandbox.prefix');

        return $configured !== '' ? $configured : $this->paths->sandbox;
    }

    /**
     * ยอมรับว่า filesystem เก็บเจ้าของไฟล์ไม่ได้ จึงข้ามการแยกสิทธิ์ระหว่างเว็บ
     *
     * เปิดได้เฉพาะเมื่อที่เก็บเว็บเป็น NTFS/exFAT/FAT ซึ่งเก็บ uid/gid ไม่ได้
     * agent จะตรวจจริงก่อนทำงานทุกครั้ง ถ้า filesystem รองรับ ownership อยู่แล้ว
     * จะประตูจนคำสั่งทันที ค่านี้จึงหลุดขึ้น production จริงไปโดยไม่มีใครรู้ไม่ได้
     */
    public function sharedOwner(): bool
    {
        return $this->bool('sites.shared_owner');
    }

    /**
     * เชื่อม BIND9 จริงแล้วหรือยัง — ปิดไว้เป็นค่าเริ่มต้นเสมอ (PLAN-V2 เฟส E3)
     *
     * รูปแบบเดียวกับ `sharedOwner()`: เป็นการตัดสินใจเชิงโครงสร้างพื้นฐานที่ต้องเปิดเอง
     * หลังตรวจสอบด้วยมือว่าเครื่องนี้มี BIND9 ทำงานอยู่จริงและมี `dns.nameservers` ที่ถูกต้อง
     * — ไม่ใช่ค่าที่ควรเปิดอัตโนมัติเพียงเพราะแพ็กเกจ bind9 ถูกติดตั้งไว้ (ทุกเครื่องที่ผ่าน
     * `install.sh` มีแพ็กเกจอยู่แล้ว แต่ไม่ได้แปลว่าตั้งค่าให้ panel เขียนทับ named.conf.local
     * ได้อย่างปลอดภัย) ปิดอยู่ = `dns.zone_write` เป็น no-op ที่บอกชัดเจนว่ายังไม่ได้เชื่อม
     */
    /**
     * ค่าที่ผู้ดูแลตั้งจากหน้าจอ — ทับค่าใน config.php
     *
     * ใช้รูปแบบเดียวกับ `Paths::useSitesDir()` คือ setter แบบ static ที่ถูกเรียกครั้งเดียว
     * ตอนที่ฐานข้อมูลพร้อม · จำเป็นเพราะ `Config` ถูกสร้างก่อน `Db` เสมอ (Db ต้องใช้
     * เส้นทางจาก Config) จึงอ่านตารางตั้งค่าตอนสร้างไม่ได้
     *
     * @var array<string,mixed>
     */
    private static array $stored = [];

    /**
     * @param array<string,string> $values ค่าจากตาราง settings
     */
    public static function useStoredSettings(array $values): void
    {
        self::$stored = $values;
    }

    /**
     * เปิดใช้งาน DNS หรือยัง — **ค่าจากหน้าจอมาก่อน config.php เสมอ**
     *
     * ผู้ดูแลที่กดเปิดจากหน้าตั้งค่าต้องได้ผลทันที ไม่ใช่ต้อง ssh เข้าไปแก้ไฟล์ตาม
     */
    public function dnsEnabled(): bool
    {
        if (array_key_exists('dns.enabled', self::$stored)) {
            return self::$stored['dns.enabled'] === '1';
        }

        return $this->bool('dns.enabled');
    }

    public function dnsZoneDir(): string
    {
        return rtrim($this->string('dns.zone_dir', '/etc/bind/zones'), '/');
    }

    public function dnsNamedConfLocal(): string
    {
        return $this->string('dns.named_conf_local', '/etc/bind/named.conf.local');
    }

    /** @return list<string> */
    public function dnsNameservers(): array
    {
        $stored = trim((string) (self::$stored['dns.nameservers'] ?? ''));

        if ($stored !== '') {
            return array_values(array_filter(array_map('trim', explode(',', $stored))));
        }

        return $this->list('dns.nameservers');
    }

    public function dnsSoaEmail(): string
    {
        $stored = trim((string) (self::$stored['dns.soa_email'] ?? ''));

        return $stored !== '' ? $stored : $this->string('dns.soa_email');
    }

    /**
     * เส้นทางทั้งหมดที่ docroot ของเว็บไซต์ชี้เข้าไปได้
     *
     * จำกัดไว้เพราะ docroot ที่กำหนดเองได้อิสระ เท่ากับการเปิดเสิร์ฟ /etc หรือ /root
     * ออกสู่อินเทอร์เน็ตได้ด้วยการกดปุ่มเดียว
     *
     * @return list<string>
     */
    public function docrootRoots(): array
    {
        $roots = [Paths::sitesDir()];

        foreach ($this->list('sites.pointer_roots') as $root) {
            $root = rtrim(trim($root), '/');
            if ($root !== '' && str_starts_with($root, '/') && !in_array('..', explode('/', $root), true)) {
                $roots[] = $root;
            }
        }

        return array_values(array_unique($roots));
    }

    /**
     * โฟลเดอร์ที่เสิร์ฟที่ http://localhost — ว่าง = ปิดฟีเจอร์
     *
     * ฟีเจอร์ของเครื่องพัฒนา · ปิดไว้เป็นค่าเริ่มต้นเพราะการเสิร์ฟโฟลเดอร์รวมงาน
     * ทุกโปรเจกต์ผ่านเว็บไม่ใช่สิ่งที่เครื่องให้บริการจริงควรทำ
     *
     * ต้องเป็นพาธสัมบูรณ์และไม่มี `..` — ค่านี้กลายเป็น DocumentRoot ตรง ๆ
     */
    public function localhostDocroot(): string
    {
        $value = rtrim(trim($this->string('sites.localhost_docroot')), '/');

        if ($value === '' || !str_starts_with($value, '/') || in_array('..', explode('/', $value), true)) {
            return '';
        }

        return $value;
    }

    /**
     * เวอร์ชัน PHP ของ localhost — ว่างในไฟล์ตั้งค่า = เวอร์ชันที่ panel รันอยู่
     *
     * ค่าเริ่มต้นมาจากโปรเซสตัวเอง เพราะ pool มาตรฐานของดิสโทรที่ติดตั้งมาพร้อมกัน
     * ก็เป็นเวอร์ชันนั้น — เดาถูกโดยไม่ต้องให้ใครมากรอก
     */
    public function localhostPhp(): string
    {
        $value = trim($this->string('sites.localhost_php'));

        return preg_match('/^\d+\.\d+$/', $value) === 1
            ? $value
            : PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
    }

    /**
     * คีย์สำหรับเข้ารหัสข้อมูลอ่อนไหวใน DB (TOTP secret)
     * ไม่มีคีย์ = ยอมให้ระบบเดินต่อไม่ได้ เพราะจะเก็บ secret แบบ plaintext
     */
    public function secretKey(): string
    {
        $raw = $this->string('security.secret_key');
        if ($raw === '') {
            throw new \RuntimeException(
                'ยังไม่ได้ตั้ง security.secret_key ใน config — รัน `phpcp key:generate` ก่อน'
            );
        }

        $key = base64_decode($raw, true);
        if ($key === false || strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            throw new \RuntimeException('security.secret_key ต้องเป็น base64 ของข้อมูล 32 ไบต์');
        }

        return $key;
    }

    /**
     * @return mixed
     */
    public function hasSecretKey(): bool
    {
        return $this->string('security.secret_key') !== '';
    }
}
