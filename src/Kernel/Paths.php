<?php

declare (strict_types = 1);

namespace Phpcp\Kernel;

/**
 * ที่อยู่ของทุกอย่างในระบบ รวมไว้ที่เดียว
 *
 * รองรับ 2 layout:
 *   system   — ติดตั้งจริงตาม ARCHITECTURE §13 (/etc/phpcp, /var/lib/phpcp, ...)
 *   portable — ทุกอย่างอยู่ใต้โฟลเดอร์โปรเจกต์ ใช้ตอนพัฒนา/ทดสอบโดยไม่ต้องติดตั้งและไม่ต้องใช้ root
 *
 * เหตุผลที่ต้องมี portable: เครื่องนักพัฒนา (เช่นเครื่องที่ออกแบบระบบนี้) ต้องรัน panel
 * ทดสอบได้ครบทุกหน้าโดยไม่แตะ /etc และไม่ต้อง sudo — ดู ARCHITECTURE §6
 */
final class Paths
{
    public const LAYOUT_SYSTEM = 'system';
    public const LAYOUT_PORTABLE = 'portable';

    /** ที่เก็บไฟล์เว็บไซต์เริ่มต้น — เปลี่ยนได้ด้วยค่าตั้ง sites.dir */
    public const DEFAULT_SITES_DIR = '/srv/phpcp/sites';

    /**
     * บ้านของผู้ใช้โฮสติ้ง — เปลี่ยนได้ด้วยค่าตั้ง sites.users_dir
     *
     * ตั้งแต่ migration 0006 ไฟล์เว็บไม่ได้อยู่ที่ `<sites.dir>/<domain>` อีกแล้ว แต่อยู่ใต้
     * บ้านของเจ้าของ เพราะ uid และโควตาดิสก์ผูกกับผู้ใช้ ไม่ใช่ผูกกับเว็บ ·
     * `sitesDir()` ยังอยู่เพื่ออ่านไฟล์ของเลย์เอาต์เดิมที่ยังไม่ได้ย้าย
     *
     * **ค่าเริ่มต้นคือ `/home`** ซึ่งเป็นที่ที่ผู้ดูแลระบบยูนิกซ์ทุกคนคาดว่าจะเจอบ้านผู้ใช้
     * และเป็นที่เดียวกับที่ cPanel/DirectAdmin/Plesk ใช้ · เดิมเป็น `/srv/phpcp/users`
     * ซึ่งลึกและไม่ตรงกับความคาดหวังของใครเลย ทั้งลูกค้าที่ย้ายมาและผู้ดูแลที่ ssh เข้าเครื่อง
     */
    public const DEFAULT_USERS_DIR = '/home';

    /**
     * ที่เก็บไฟล์เว็บไซต์ที่ใช้จริง
     *
     * เป็น static เพราะ Site เป็น value object ที่ถูกสร้างจากหลายสิบจุดทั่วระบบ
     * (capability, controller, CLI, seeder) การส่ง Config ตามไปทุกจุดจะทำให้
     * ลายเซ็นของ Site เปลี่ยนไปทั้งหมดโดยไม่ได้ความปลอดภัยเพิ่มขึ้นเลย
     * รูปแบบเดียวกับ SelfProtection::protectAlso() ที่ใช้อยู่แล้ว
     */
    private static string $sitesDir = self::DEFAULT_SITES_DIR;

    /** บ้านของผู้ใช้ที่ใช้จริง — static ด้วยเหตุผลเดียวกับ $sitesDir */
    private static string $usersDir = self::DEFAULT_USERS_DIR;

    /**
     * รูปทรงของไฟล์ใต้บ้านผู้ใช้ที่ใช้เป็นค่าเริ่มต้น — ดู {@see \Phpcp\Domain\SiteLayout}
     *
     * เก็บเป็นข้อความดิบไม่ใช่ตัว enum เพราะ `Paths` อยู่ชั้น Kernel ซึ่งต้องไม่พึ่ง Domain
     * · ตัว enum เป็นคนแปลค่าและตัดสินว่าค่าที่ไม่รู้จักตกไปที่ไหน
     */
    private static string $siteLayout = '';

    private function __construct(
        public readonly string $layout,
        public readonly string $root,
        public readonly string $etc,
        public readonly string $data,
        public readonly string $log,
        public readonly string $run,
        public readonly string $sandbox,
        public readonly string $sites,
    ) {
    }

    /**
     * @param string $layout
     * @param string $root
     */
    public static function forLayout(string $layout, string $root): self
    {
        if ($layout === self::LAYOUT_SYSTEM) {
            return new self(
                layout: self::LAYOUT_SYSTEM,
                root: $root,
                etc: '/etc/phpcp',
                data: '/var/lib/phpcp',
                log: '/var/log/phpcp',
                run: '/run/phpcp',
                sandbox: '/opt/phpcp-sandbox',
                sites: self::sitesDir(),
            );
        }

        $var = $root.'/var';

        return new self(
            layout: self::LAYOUT_PORTABLE,
            root: $root,
            etc: $root.'/etc',
            data: $var.'/lib',
            log: $var.'/log',
            run: $var.'/run',
            sandbox: $var.'/sandbox',
            sites: $var.'/sites',
        );
    }

    /**
     * เดา layout จากสภาพเครื่อง — มี /etc/phpcp/config.php แปลว่าติดตั้งแบบ system แล้ว
     */
    public static function detect(string $root): self
    {
        return is_file('/etc/phpcp/config.php')
            ? self::forLayout(self::LAYOUT_SYSTEM, $root)
            : self::forLayout(self::LAYOUT_PORTABLE, $root);
    }

    /** ที่เก็บไฟล์เว็บไซต์ที่ใช้อยู่จริง — เส้นทางเชิงตรรกะ โหมด sandbox เติม prefix ให้ทีหลัง */
    public static function sitesDir(): string
    {
        return self::$sitesDir;
    }

    /** บ้านของผู้ใช้ที่ใช้อยู่จริง — เส้นทางเชิงตรรกะ โหมด sandbox เติม prefix ให้ทีหลัง */
    public static function usersDir(): string
    {
        return self::$usersDir;
    }

    /** เปลี่ยนบ้านของผู้ใช้ — เรียกจาก Config::load() จุดเดียว กฎเดียวกับ useSitesDir() */
    public static function useUsersDir(string $dir): void
    {
        self::$usersDir = self::assertHostingDir($dir, 'sites.users_dir') ?? self::DEFAULT_USERS_DIR;
    }

    /** ค่าเริ่มต้นของรูปทรงไฟล์ — ค่าว่างแปลว่ายังไม่ได้ตั้ง ให้ SiteLayout เลือกเอง */
    public static function siteLayout(): string
    {
        return self::$siteLayout;
    }

    /** เรียกจาก Config::load() จุดเดียว — ไม่ตรวจค่าที่นี่ ตัว enum เป็นคนตัดสิน */
    public static function useSiteLayout(string $value): void
    {
        self::$siteLayout = trim($value);
    }

    /**
     * เส้นทางที่จะถูกเอาไปประกอบเป็น vhost, FPM pool และ open_basedir ต้องสะอาดตั้งแต่ต้นทาง
     *
     * กัน `..` และเส้นทางสัมพัทธ์ที่นี่ ไม่ใช่ปล่อยไปโผล่ที่ปลายทางซึ่งไม่มีใครตรวจแล้ว
     *
     * @return string|null null = ไม่ได้ตั้งค่า ให้ผู้เรียกใช้ค่าเริ่มต้น
     */
    private static function assertHostingDir(string $dir, string $setting): ?string
    {
        $dir = rtrim(trim($dir), '/');

        if ($dir === '') {
            return null;
        }

        if (!str_starts_with($dir, '/') || str_contains($dir, "\0")) {
            throw new \RuntimeException("{$setting} ต้องเป็นเส้นทางสัมบูรณ์: {$dir}");
        }

        if (in_array('..', explode('/', $dir), true)) {
            throw new \RuntimeException("{$setting} ต้องไม่มี .. : {$dir}");
        }

        return $dir;
    }

    /**
     * เปลี่ยนที่เก็บไฟล์เว็บไซต์ — เรียกจาก Config::load() จุดเดียว
     *
     * ค่านี้ถูกนำไปประกอบเป็นเส้นทางของ vhost, FPM pool และ open_basedir
     * จึงต้องกัน `..` และเส้นทางสัมพัทธ์ตั้งแต่ตรงนี้ ไม่ใช่ปล่อยไปโผล่ที่ปลายทาง
     */
    public static function useSitesDir(string $dir): void
    {
        self::$sitesDir = self::assertHostingDir($dir, 'sites.dir') ?? self::DEFAULT_SITES_DIR;
    }

    /**
     * @return mixed
     */
    public function configFile(): string
    {
        return $this->etc.'/config.php';
    }

    /**
     * @return mixed
     */
    public function database(): string
    {
        return $this->data.'/panel.db';
    }

    /**
     * @return mixed
     */
    public function agentSocket(): string
    {
        return $this->run.'/agent.sock';
    }

    /**
     * @return mixed
     */
    public function agentPidFile(): string
    {
        return $this->run.'/agentd.pid';
    }

    /**
     * @param string $name
     * @return mixed
     */
    public function logFile(string $name): string
    {
        return $this->log.'/'.$name.'.log';
    }

    /**
     * โปรแกรมช่วยของ panel ที่โปรแกรมอื่นบนเครื่องเรียกได้ — `bin/<ชื่อ>`
     *
     * **ไม่ใช่ `/usr/local/bin`** · ตัวติดตั้งทำ symlink ไว้ที่นั่นให้แค่ `phpcp`
     * ตัวเดียว ที่เหลืออยู่ในโฟลเดอร์ติดตั้ง · เดาเส้นทางผิดแล้วผู้เรียกจะล้มเหลว
     * เงียบ ๆ (fail2ban ที่เรียก action ไม่เจอไฟล์ก็แค่บันทึกลง log ของตัวเอง
     * แล้วเดินต่อ ผู้ดูแลจึงไม่มีทางรู้ว่าการแจ้งเตือนไม่เคยถูกส่งเลย)
     */
    public function binary(string $name): string
    {
        return $this->root.'/bin/'.$name;
    }

    /**
     * @return mixed
     */
    public function migrations(): string
    {
        return $this->root.'/db/migrations';
    }

    /**
     * @return mixed
     */
    public function templates(): string
    {
        return $this->root.'/templates';
    }

    /**
     * ที่เก็บไฟล์ของ SPA (Now.js)
     *
     * อยู่ใต้ `public/assets/` **ไม่ใช่ `public/app/`** ทั้งที่ URL ของหน้าจอคือ `/app/*` —
     * เพราะเส้นทางของ SPA ต้องไม่ตรงกับไดเรกทอรีจริงบนดิสก์ ไม่งั้น `mod_dir` ของ Apache
     * จะเข้ามาจัดการเองก่อน แล้ว `FallbackResource` จะไม่ทำงาน (มันข้าม URL ที่ชี้ไปยัง
     * ไฟล์หรือไดเรกทอรีที่มีอยู่จริง) ผลคือ `/app/` ได้ 404 ของ Apache โดยที่ PHP
     * ไม่เคยเห็นคำขอนั้นเลย · ดู `SpaController` ประกอบ
     */
    public function spa(): string
    {
        return $this->root.'/public/assets/spa';
    }

    /**
     * สำเนา phpMyAdmin ที่ตัวติดตั้งผูกไว้ใต้เว็บรูทของ panel
     *
     * เป็น symlink ชี้ไป /usr/share/phpmyadmin ของระบบ ไม่ใช่สำเนาที่ดาวน์โหลดเอง
     * จึงได้อัปเดตความปลอดภัยตาม apt ของดิสทริบิวชัน (ดู install.sh §5.1)
     */
    public function phpMyAdmin(): string
    {
        return $this->root.'/public/phpmyadmin';
    }

    /** phpMyAdmin ใช้งานได้จริงหรือไม่ — ใช้ตัดสินว่าจะแสดงปุ่มเข้าใช้งานไหม */
    public function hasPhpMyAdmin(): bool
    {
        return is_file($this->phpMyAdmin().'/index.php');
    }

    /**
     * ที่พักของไฟล์สำรองที่ดึงมาจากปลายทางนอกเครื่อง — **ไม่ใช่ที่เก็บถาวรอีกแล้ว**
     *
     * ไฟล์สำรองของลูกค้าอยู่ที่ `<บ้าน>/backup` ของเขาเอง (PLAN-BACKUP-V2 ข้อ B1) ·
     * ที่นี่เหลือไว้สำหรับช่วงเวลาสั้น ๆ ระหว่างที่ `backup.import` ดึงไฟล์ลงมาแล้วยัง
     * ไม่รู้ว่าเป็นของบ้านไหน จนกว่าจะอ่าน `backup.json` ข้างในเสร็จ · หลังจากนั้นไฟล์
     * ถูกย้ายเข้าบ้านทันที ที่นี่จึงว่างเสมอในสภาพปกติ
     */
    public function backups(): string
    {
        return $this->data.'/backups';
    }

    /**
     * ไดเรกทอรีที่ต้องมีอยู่จริงก่อนระบบทำงานได้ พร้อมสิทธิ์ที่ต้องการ
     *
     * @return array<string,int> path => mode
     */
    public function required(): array
    {
        return [
            $this->etc => 0750,
            $this->data => 0750,
            $this->backups() => 0750,
            $this->log => 0750,
            $this->run => 0750
        ];
    }

    public function ensureDirectories(): void
    {
        foreach ($this->required() as $dir => $mode) {
            if (!is_dir($dir) && !@mkdir($dir, $mode, true) && !is_dir($dir)) {
                throw new \RuntimeException("สร้างไดเรกทอรีไม่สำเร็จ: {$dir}");
            }
        }
    }
}
