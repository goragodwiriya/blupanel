<?php

declare(strict_types=1);

namespace Phpcp\Agent\Capability;

use Phpcp\Agent\Capability;
use Phpcp\Agent\Context;
use Phpcp\Agent\Executor\Executor;
use Phpcp\Domain\Notifier;
use Phpcp\Domain\SettingsRepository;
use Phpcp\Driver\Mail\MailManager;
use Phpcp\Driver\Notify\TelegramNotifier;
use Phpcp\Driver\Notify\WebhookNotifier;
use Phpcp\Support\Validator;

/**
 * บันทึกค่าตั้ง
 *
 * ค่าที่เป็นความลับ (token, รหัสผ่าน) ถ้าส่งมาเป็นค่าที่ปิดบังไว้ (********)
 * แปลว่าผู้ใช้ไม่ได้แก้ช่องนั้น ต้องเก็บค่าเดิมไว้ ไม่ใช่เขียนทับด้วยดอกจัน —
 * ถ้าเขียนทับ การกดบันทึกเพื่อแก้ค่าอื่นเพียงค่าเดียวจะทำลาย token ทิ้งทุกครั้ง
 */
final class SettingsSet implements Capability
{
    public static function name(): string
    {
        return 'settings.set';
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
        return 'บันทึกค่าตั้งของระบบ';
    }

    public function validate(array $args): array
    {
        $out = [];

        foreach (SettingsRepository::keys() as $key => $type) {
            if (!array_key_exists($key, $args)) {
                continue;
            }

            $value = (string) $args[$key];

            $out[$key] = match ($type) {
                'bool' => $value === '1' || $value === 'on' || $value === 'true' ? '1' : '0',
                'int' => (string) (int) $value,
                default => Validator::pattern(
                    trim($value),
                    // กันอักขระควบคุมและขึ้นบรรทัดใหม่ — ค่าเหล่านี้ถูกนำไปเขียนลงไฟล์ config
                    '/^[^\x00-\x1F\x7F]{0,255}$/u',
                    "ค่าของ {$key} มีอักขระที่ใช้ไม่ได้",
                ),
            };
        }

        // ตรวจรูปแบบเฉพาะทางหลังจากล้างค่าพื้นฐานแล้ว
        if (isset($out['notify.telegram.token']) && $out['notify.telegram.token'] !== '********') {
            TelegramNotifier::assertToken($out['notify.telegram.token']);
        }

        if (isset($out['notify.telegram.chat_id'])) {
            TelegramNotifier::assertChatId($out['notify.telegram.chat_id']);
        }

        if (($out['mail.from'] ?? '') !== '') {
            MailManager::assertEmail($out['mail.from']);
        }

        if (($out['notify.email.to'] ?? '') !== '') {
            MailManager::assertEmail($out['notify.email.to']);
        }

        // บังคับ https และรูปแบบ URL ตั้งแต่ตอนบันทึก — จับความผิดพลาดตอนกรอก
        // ไม่ใช่ตอนที่การแจ้งเตือนสำคัญส่งไม่ออกในจังหวะที่ต้องการที่สุด
        if (isset($out['notify.webhook.url'])) {
            WebhookNotifier::assertUrl($out['notify.webhook.url']);
        }

        if (($out['mail.relay_host'] ?? '') !== '') {
            MailManager::assertHost($out['mail.relay_host']);
        }

        if (isset($out['mail.relay_port'])) {
            MailManager::assertPort((int) $out['mail.relay_port']);
        }

        return ['values' => $out];
    }

    public function run(array $args, Executor $executor, Context $context): array
    {
        $settings = new SettingsRepository($context->db);
        $values = $args['values'];

        foreach ($values as $key => $value) {
            // ค่าที่ยังเป็นดอกจัน = ผู้ใช้ไม่ได้แตะช่องนั้น เก็บของเดิมไว้
            if (SettingsRepository::isSecret($key) && $value === '********') {
                unset($values[$key]);
            }
        }

        $settings->save($values);

        return [
            'saved' => count($values),
            'notify_active' => (new Notifier($context->db, $executor))->isActive(),
            'notify_channels' => (new Notifier($context->db, $executor))->activeChannels(),
            'message' => sprintf('บันทึกค่าตั้ง %d รายการแล้ว', count($values)),
        ];
    }
}
