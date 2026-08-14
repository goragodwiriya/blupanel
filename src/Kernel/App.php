<?php

declare(strict_types=1);

namespace Phpcp\Kernel;

use Phpcp\Agent\Actor;
use Phpcp\Agent\Client;
use Phpcp\Agent\SelfProtection;
use Phpcp\Security\AuditLog;
use Phpcp\Support\Translator;

/**
 * ที่รวมของทุกบริการในระบบ สร้างแบบ lazy
 *
 * จงใจใช้คลาสที่มีเมธอดระบุชนิดชัดเจน แทน DI container แบบทั่วไปที่ผูกด้วยสตริง
 * โปรเจกต์ขนาดนี้ไม่ได้ประโยชน์จาก container แบบนั้น แต่เสียความชัดเจนไปมาก
 * และ IDE ตามชนิดข้อมูลไม่ได้ ซึ่งสำคัญกับโค้ดที่ต้องตรวจสอบด้านความปลอดภัย
 */
final class App
{
    private ?Db $db = null;
    private ?Logger $logger = null;
    private ?Client $agent = null;
    private ?AuditLog $audit = null;

    /**
     * ภาษาของคำขอที่กำลังทำงานอยู่ — ตั้งโดย HttpKernel จากคุกกี้ที่หน้าเว็บเขียนไว้
     *
     * ค่าตั้งต้นเป็นภาษาของเครื่อง (config `locale`) เพราะงานที่ไม่ได้มาจากเบราว์เซอร์
     * — อีเมลแจ้งเตือน คำสั่งบรรทัดคำสั่ง งานตามเวลา — ไม่มีผู้ใช้ให้ถาม
     */
    private ?string $locale = null;

    private function __construct(public readonly Config $config)
    {
    }

    /** ภาษาที่ใช้ตอบคำขอนี้ */
    public function locale(): string
    {
        return $this->locale ?? $this->config->string('locale', 'en');
    }

    /**
     * ตั้งภาษาของคำขอ — ค่าที่ไม่รู้จักกลับไปใช้ภาษาของเครื่อง ไม่ใช่ทำให้คำขอล้ม
     *
     * ค่านี้มาจากคุกกี้ที่ผู้ใช้เป็นคนกำหนดได้ จึงต้องผ่านตัวกรองรูปแบบก่อนเสมอ
     * ก่อนถูกเอาไปประกอบเป็นชื่อไฟล์คลังคำ
     *
     * **เขียนทับทุกครั้งแม้ค่าจะใช้ไม่ได้** — ในโปรเซสที่รับหลายคำขอ (ชุดทดสอบ หรือ
     * worker ในอนาคต) การเงียบ ๆ ไม่เขียนทับแปลว่าคำขอถัดไปได้ภาษาของคำขอก่อนหน้า
     */
    public function setLocale(string $locale): void
    {
        $this->locale = preg_match('/^[a-z]{2}$/', $locale) === 1 ? $locale : null;
    }

    /** คลังคำของภาษาที่ใช้อยู่ — ไฟล์เดียวกับที่หน้าเว็บใช้ */
    public function translator(): Translator
    {
        return Translator::load($this->locale(), $this->config->paths->spa() . '/lang');
    }

    /**
     * แปลข้อความที่จะส่งออกไปให้คนอ่าน
     *
     * @param array<string,string|int|float> $params
     */
    public function t(string $key, array $params = []): string
    {
        return $this->translator()->get($key, $params);
    }

    public static function boot(string $root = PHPCP_ROOT): self
    {
        $config = Config::load($root);
        $app = new self($config);

        // layout แบบ portable วางไฟล์ของ panel ไว้ในโฟลเดอร์โปรเจกต์
        // ต้องบอก SelfProtection ด้วย ไม่อย่างนั้น file manager จะเข้าไปแก้ไฟล์ของ panel เองได้
        SelfProtection::protectAlso(
            $config->paths->etc,
            $config->paths->data,
            $config->paths->log,
            $config->paths->run,
            $root . '/src',
            $root . '/bin',
            $root . '/views',
            $root . '/db',
        );

        /*
         * ไม่มีข้อยกเว้นให้ `/var/lib/phpcp/backups` อีกแล้ว
         *
         * ไฟล์สำรองย้ายไปอยู่ `<บ้าน>/backup` ของลูกค้าตั้งแต่ PLAN-BACKUP-V2 §4.1 ·
         * ที่นี่เหลือเป็นแค่ **ที่พักชั่วคราวของไฟล์ที่ดึงมาจากปลายทางนอกเครื่อง**
         * ก่อนรู้ว่าเป็นของบ้านไหน (ดู `BackupImport`) ซึ่ง agent เข้าถึงได้อยู่แล้ว
         * โดยไม่ต้องเปิดให้ตัวจัดการไฟล์เห็น — การกันของ panel จึงกลับไปไม่มีเงื่อนไข
         */

        return $app;
    }

    public function db(): Db
    {
        if ($this->db === null) {
            $this->db = new Db($this->config->paths->database());

            // ค่าที่ตั้งจากหน้าจอต้องทับค่าใน config.php — ทำตรงนี้เพราะเป็นจุดแรกสุด
            // ที่ฐานข้อมูลพร้อมใช้ · ตารางยังไม่มี (ก่อน migrate) ก็ข้ามไปเงียบ ๆ
            // แล้วใช้ค่าจากไฟล์ตามเดิม ไม่ใช่ล้มทั้งระบบ
            try {
                Config::useStoredSettings(
                    (new \Phpcp\Domain\SettingsRepository($this->db))->all(),
                );
            } catch (\Throwable) {
                // ยังไม่มีตาราง settings — ปกติสำหรับเครื่องที่เพิ่งติดตั้ง
            }
        }

        return $this->db;
    }

    public function logger(string $channel = 'panel'): Logger
    {
        return $this->logger ??= new Logger(
            $this->config->paths->logFile($channel),
            $this->config->string('log.level', 'info'),
        );
    }

    public function agent(): Client
    {
        return $this->agent ??= new Client(
            $this->config->agentSocket(),
            $this->config->int('agent.timeout', 30),
        );
    }

    public function audit(): AuditLog
    {
        return $this->audit ??= new AuditLog(
            $this->db(),
            $this->config->paths->logFile('audit'),
        );
    }

    /** ผู้สั่งงานภายในระบบ ใช้ตอน CLI หรือ cron ที่ไม่มีผู้ใช้ล็อกอิน */
    public function systemActor(string $reason): Actor
    {
        return Actor::system($reason);
    }
}
