<?php

declare(strict_types=1);

namespace Phpcp\Agent\Capability;

use Phpcp\Agent\Capability;
use Phpcp\Agent\Context;
use Phpcp\Agent\Executor\Executor;
use Phpcp\Driver\Mail\MailQueue;

/**
 * คิวเมลขาออก — PLAN-MAIL §5
 *
 * **สิทธิ์เป็น `settings.manage` ไม่ใช่ `mail.view` อย่างที่แผนเขียนไว้ตอนแรก**
 *
 * คิวเป็นของทั้งเครื่อง แต่ละแถวมีที่อยู่ผู้ส่งและผู้รับของ**ลูกค้าทุกราย**ปนกันอยู่ ·
 * `mail.view` เป็นสิทธิ์ที่เจ้าของเว็บมีติดตัว การให้ดูคิวทั้งเครื่องจึงเท่ากับเปิดให้
 * ลูกค้ารายหนึ่งเห็นว่าลูกค้ารายอื่นติดต่อกับใครอยู่ · แผนตอนเขียนยังไม่ได้คิดเรื่อง
 * หลายผู้เช่าในข้อนี้
 *
 * การกรองคิวรายเจ้าของทำได้ (เทียบโดเมนของผู้ส่ง/ผู้รับ) แต่ยังไม่ทำในรอบนี้ —
 * ครึ่ง ๆ กลาง ๆ กับข้อมูลของลูกค้าแย่กว่าไม่เปิดให้เห็นเลย
 */
final class MailQueueList implements Capability
{
    public static function name(): string
    {
        return 'mail.queue';
    }

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
        return 'อ่านคิวเมลขาออก';
    }

    public function validate(array $args): array
    {
        return [];
    }

    public function run(array $args, Executor $executor, Context $context): array
    {
        $queue = (new MailQueue())->list($executor);

        return $queue + [
            'message' => $queue['available']
                ? sprintf('มีเมลในคิว %d ฉบับ (ค้างส่ง %d)', $queue['total'], $queue['deferred'])
                : 'อ่านคิวไม่ได้ — Postfix บนเครื่องนี้เก่ากว่า 3.1 จึงไม่รองรับ postqueue -j',
        ];
    }
}
