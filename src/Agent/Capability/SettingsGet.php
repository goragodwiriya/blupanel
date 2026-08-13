<?php

declare(strict_types=1);

namespace Phpcp\Agent\Capability;

use Phpcp\Agent\Capability;
use Phpcp\Agent\Context;
use Phpcp\Agent\Executor\Executor;
use Phpcp\Domain\SettingsRepository;
use Phpcp\Driver\PanelCertificate;
use Phpcp\Driver\Mail\MailManager;
use Phpcp\Driver\Template;

/**
 * อ่านค่าตั้งที่แก้ได้จากหน้าเว็บ พร้อมสถานะจริงของเมลบนเครื่อง
 *
 * ค่าที่เป็นความลับถูกปิดบังก่อนส่งออกเสมอ — token ของบอทที่หลุดไปทาง HTML
 * แปลว่าใครก็ส่งข้อความในนามระบบได้ และจะติดอยู่ในแคชของเบราว์เซอร์ไปอีกนาน
 */
final class SettingsGet implements Capability
{
    public static function name(): string
    {
        return 'settings.get';
    }

    public function permission(): string
    {
        return 'settings.view';
    }

    public function isMutating(): bool
    {
        return false;
    }

    public function summary(): string
    {
        return 'อ่านค่าตั้งของระบบ';
    }

    public function validate(array $args): array
    {
        return [];
    }

    public function run(array $args, Executor $executor, Context $context): array
    {
        $settings = new SettingsRepository($context->db);
        $mail = new MailManager(new Template($context->config->paths->templates()));

        /*
         * สถานะใบรับรองของหน้าจัดการอ่านจาก**ไฟล์จริง** ไม่ใช่จากค่าที่บันทึกไว้ —
         * สองอย่างนี้ต่างกันได้ถ้ามีคนสลับไฟล์ด้วยมือ และเวลาที่ต่างกันคือเวลาที่ผู้ดูแล
         * ต้องการคำตอบมากที่สุด
         */
        $panelCert = (new PanelCertificate())->status(
            $executor,
            $settings->get(\Phpcp\Agent\Capability\PanelCertSet::SETTING),
        );

        return [
            'values' => SettingsRepository::mask($settings->all()),
            'mail_status' => $mail->status($executor),
            'panel_cert' => $panelCert,
        ];
    }
}
