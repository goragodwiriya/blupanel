<?php

declare(strict_types=1);

namespace Phpcp\Kernel;

use Phpcp\Agent\Actor;
use Phpcp\Agent\Client;
use Phpcp\Agent\SelfProtection;
use Phpcp\Security\AuditLog;

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

    private function __construct(public readonly Config $config)
    {
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

        return $app;
    }

    public function db(): Db
    {
        return $this->db ??= new Db($this->config->paths->database());
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
