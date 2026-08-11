<?php

declare(strict_types=1);

namespace Phpcp\Agent\Capability;

use Phpcp\Agent\Capability;
use Phpcp\Agent\Context;
use Phpcp\Agent\Executor\Executor;
use Phpcp\Domain\SettingsRepository;
use Phpcp\Driver\Notify\EmailNotifier;
use Phpcp\Driver\Notify\TelegramNotifier;
use Phpcp\Driver\Notify\WebhookNotifier;
use Phpcp\Support\Validator;

/**
 * ส่งข้อความทดสอบไปยังช่องทางที่ระบุ — Telegram · อีเมล · webhook (PLAN-V2 เฟส E6)
 *
 * ต่างจากการแจ้งเตือนอัตโนมัติตรงที่ **ความล้มเหลวต้องดัง** — ผู้ใช้เพิ่งกดปุ่มทดสอบ
 * ถ้าเงียบไปเฉย ๆ จะเข้าใจว่าตั้งค่าถูกแล้ว ทั้งที่ token ผิด
 * แล้วจะไม่ได้รับการแจ้งเตือนจริงในวันที่เซิร์ฟเวอร์มีปัญหา
 *
 * **ทดสอบทีละช่องทาง ไม่ใช่ยิงทุกช่องพร้อมกัน** — ผู้ดูแลต้องรู้ว่าช่องไหนพังกันแน่
 * ถ้ายิงรวมแล้วบอกว่า "ส่งสำเร็จ 2 จาก 3" ก็ยังต้องเดาต่อว่าอันไหนไม่ผ่าน
 */
final class NotifyTest implements Capability
{
    public static function name(): string
    {
        return 'notify.test';
    }

    public function permission(): string
    {
        return 'settings.manage';
    }

    public function isMutating(): bool
    {
        // ไม่เปลี่ยนสถานะระบบ แต่ส่งข้อความออกไปภายนอก จึงควรอยู่ใน audit log
        return true;
    }

    public function summary(): string
    {
        return 'ส่งข้อความทดสอบการแจ้งเตือน';
    }

    public function validate(array $args): array
    {
        // ไม่ระบุ = Telegram เพื่อความเข้ากันได้กับผู้เรียกเดิมที่มีช่องทางเดียว
        return [
            'channel' => Validator::optionalEnum($args, 'channel', ['telegram', 'email', 'webhook'], 'telegram'),
        ];
    }

    public function run(array $args, Executor $executor, Context $context): array
    {
        $settings = new SettingsRepository($context->db);

        return match ($args['channel']) {
            'email' => $this->testEmail($executor, $settings),
            'webhook' => $this->testWebhook($settings),
            default => $this->testTelegram($settings),
        };
    }

    /** @return array<string,mixed> */
    private function testTelegram(SettingsRepository $settings): array
    {
        $result = (new TelegramNotifier(
            $settings->get('notify.telegram.token'),
            $settings->get('notify.telegram.chat_id'),
        ))->test();

        return [
            'channel' => 'telegram',
            'message_id' => $result['message_id'],
            'message' => 'ส่งข้อความทดสอบไปยัง Telegram แล้ว — ตรวจดูในแชทว่าได้รับหรือไม่',
        ];
    }

    /** @return array<string,mixed> */
    private function testEmail(Executor $executor, SettingsRepository $settings): array
    {
        $result = (new EmailNotifier(
            $executor,
            $settings->get('notify.email.to'),
            $settings->get('mail.from'),
        ))->test();

        return [
            'channel' => 'email',
            'to' => $result['to'],
            // Postfix รับเข้าคิวแล้วจบ — "ส่งสำเร็จ" ที่นี่ยังไม่ได้แปลว่าถึงกล่องจดหมาย
            // ต้องบอกให้ชัด ไม่งั้นผู้ดูแลจะเข้าใจว่าเรียบร้อยแล้วทั้งที่อาจติดที่ปลายทาง
            'message' => sprintf(
                'ส่งอีเมลทดสอบไปที่ %s แล้ว — Postfix รับเข้าคิวสำเร็จ ตรวจกล่องจดหมายว่าได้รับจริงหรือไม่',
                $result['to'],
            ),
        ];
    }

    /** @return array<string,mixed> */
    private function testWebhook(SettingsRepository $settings): array
    {
        $result = (new WebhookNotifier(
            $settings->get('notify.webhook.url'),
            $settings->get('notify.webhook.secret'),
        ))->test();

        return [
            'channel' => 'webhook',
            'status' => $result['status'],
            'message' => sprintf('ปลายทาง webhook ตอบรหัส %d — ส่งสำเร็จ', $result['status']),
        ];
    }
}
