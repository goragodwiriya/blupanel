<?php

declare(strict_types=1);

namespace Phpcp\Agent\Capability;

use Phpcp\Agent\Capability;
use Phpcp\Agent\Context;
use Phpcp\Agent\Executor\Executor;
use Phpcp\Agent\ValidationError;
use Phpcp\Domain\SettingsRepository;
use Phpcp\Driver\Mail\MailManager;
use Phpcp\Driver\Template;

/**
 * ส่งเมลทดสอบผ่านเส้นทางเดียวกับที่เว็บไซต์ของผู้ใช้จะใช้
 *
 * ใช้ `sendmail` ของระบบ ไม่ใช่ต่อ SMTP เอง เพราะสิ่งที่ต้องพิสูจน์คือ
 * "เส้นทางที่เว็บไซต์ใช้จริงทำงานหรือไม่" ถ้าทดสอบด้วยเส้นทางอื่นแล้วผ่าน
 * ผู้ใช้จะเข้าใจว่าเมลใช้ได้ ทั้งที่ `mail()` ในเว็บไซต์ยังส่งไม่ออก
 */
final class MailTest implements Capability
{
    public static function name(): string
    {
        return 'mail.test';
    }

    public function permission(): string
    {
        return 'settings.manage';
    }

    public function isMutating(): bool
    {
        return true;
    }

    public function summary(): string
    {
        return 'ส่งเมลทดสอบ';
    }

    public function validate(array $args): array
    {
        return ['to' => MailManager::assertEmail(trim((string) ($args['to'] ?? '')))];
    }

    public function run(array $args, Executor $executor, Context $context): array
    {
        $settings = new SettingsRepository($context->db);
        $from = $settings->get('mail.from');

        if ($from === '') {
            throw new ValidationError('ยังไม่ได้ตั้งที่อยู่ผู้ส่ง — กรอกในหน้าการตั้งค่าก่อน');
        }

        $manager = new MailManager(new Template($context->config->paths->templates()));
        $result = $manager->sendTest($executor, $args['to'], $from);

        return [
            'to' => $result['to'],
            'queued' => $result['queued'],
            'message' => sprintf(
                'ส่งเมลทดสอบไปยัง %s แล้ว%s — ถ้าไม่ได้รับใน 5 นาที ให้ตรวจ log ของ Postfix '
                . 'และตรวจว่าเมลไม่ได้เข้าถังขยะ',
                $result['to'],
                $result['queued'] > 0 ? sprintf(' (มีเมลค้างในคิว %d ฉบับ)', $result['queued']) : '',
            ),
        ];
    }
}
