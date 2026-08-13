<?php

declare(strict_types=1);

namespace Phpcp\Agent\Capability;

use Phpcp\Agent\Capability;
use Phpcp\Agent\Context;
use Phpcp\Agent\Executor\Executor;
use Phpcp\Driver\Mail\MailQueue;
use Phpcp\Support\Validator;

/**
 * เปิดดูเมลหนึ่งฉบับ **ที่ยังอยู่ในคิว** — PLAN-MAIL §5
 *
 * ## ทำไมถึงไม่ใช่การอ่านเมลของลูกค้า
 *
 * เมลในคิวคือเมลที่ยังส่งไม่ออก ยังไม่ได้ถูกส่งเข้ากล่องของใครทั้งสิ้น · การเปิดดูจึง
 * ไม่แตะ Maildir ของใคร ไม่มีแฟล็ก "อ่านแล้ว" ให้เปลี่ยน และไม่มีใครรู้สึกว่าเมลของตัวเอง
 * ถูกเปิด เพราะยังไม่มีเจ้าของกล่องคนไหนเห็นมันเลย
 *
 * นี่คือขอบเขตที่ตั้งใจ: ตอบคำถาม "ทำไมเมลฉบับนี้ส่งไม่ออก" ได้เต็มที่ โดยไม่กลายเป็น
 * เครื่องมืออ่านกล่องจดหมายของลูกค้า ซึ่งเป็นคนละเรื่องและต้องตัดสินใจกันต่างหาก
 */
final class MailMessage implements Capability
{
    public static function name(): string
    {
        return 'mail.message';
    }

    /** เมลในคิวเป็นของลูกค้ารายไหนก็ได้ — ผู้ดูแลเครื่องเท่านั้น */
    public function permission(): string
    {
        return 'settings.manage';
    }

    public function isMutating(): bool
    {
        return false;
    }

    public function summary(): string
    {
        return 'อ่านเมลหนึ่งฉบับที่ค้างอยู่ในคิว';
    }

    public function validate(array $args): array
    {
        return ['id' => MailQueue::assertId(Validator::requireString($args, 'id', 40))];
    }

    public function run(array $args, Executor $executor, Context $context): array
    {
        return [
            'id' => $args['id'],
            'content' => (new MailQueue())->message($executor, $args['id']),
        ];
    }
}
