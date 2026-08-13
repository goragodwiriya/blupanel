<?php

declare(strict_types=1);

namespace Phpcp\Agent\Capability;

use Phpcp\Agent\Capability;
use Phpcp\Agent\Context;
use Phpcp\Agent\Executor\Executor;
use Phpcp\Driver\Mail\MailQueue;
use Phpcp\Support\Validator;

/**
 * สั่งงานกับคิวเมลขาออก — PLAN-MAIL §5
 *
 * สามคำสั่งที่ผู้ดูแลต้องใช้จริงเวลาเมลค้าง:
 *
 *   flush       ลองส่งใหม่เดี๋ยวนี้ — ใช้หลังแก้ต้นเหตุแล้ว (DNS ถูก, relay กลับมา)
 *               ไม่ต้องรอรอบถัดไปของ Postfix ซึ่งห่างขึ้นเรื่อย ๆ ตามจำนวนครั้งที่ล้ม
 *   delete      ลบฉบับเดียว — เมลที่ส่งผิด หรือเมลสแปมที่ถูกยัดเข้าคิว
 *   delete_all  ล้างทั้งคิว — **ลบเมลของลูกค้าทุกฉบับที่รอส่งอยู่**
 *
 * `delete_all` เป็นค่าที่ต้องส่งมาตรง ๆ ไม่ใช่ `delete` ที่มี id เป็น `ALL` — ดูเหตุผล
 * ที่ `MailQueue::deleteAll()` · ผิดพลาดตรงนี้แปลว่าเมลของลูกค้าหายหมดโดยไม่มีใครรู้
 */
final class MailQueueAction implements Capability
{
    public static function name(): string
    {
        return 'mail.queue_action';
    }

    /** คิวเป็นของทั้งเครื่อง — ดูเหตุผลที่ MailQueueList */
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
        return 'สั่งงานกับคิวเมลขาออก';
    }

    public function validate(array $args): array
    {
        $action = Validator::requireEnum($args, 'action', ['flush', 'delete', 'delete_all', 'release']);
        $id = trim((string) ($args['id'] ?? ''));

        // ตรวจรหัสตั้งแต่ตอนนี้สำหรับคำสั่งที่ต้องใช้ — `ALL` ถูกปฏิเสธใน assertId
        if (in_array($action, ['delete', 'release'], true)) {
            $id = MailQueue::assertId($id);
        }

        return ['action' => $action, 'id' => $id];
    }

    public function run(array $args, Executor $executor, Context $context): array
    {
        $queue = new MailQueue();

        $message = match ($args['action']) {
            'flush' => $this->flush($queue, $executor),
            'delete' => $this->delete($queue, $executor, $args['id']),
            'release' => $this->release($queue, $executor, $args['id']),
            default => $this->deleteAll($queue, $executor),
        };

        return [
            'action' => $args['action'],
            'id' => $args['id'],
            'remaining' => $queue->list($executor)['total'],
            'message' => $message,
        ];
    }

    private function flush(MailQueue $queue, Executor $executor): string
    {
        $queue->flush($executor);

        return 'สั่งให้ลองส่งเมลในคิวใหม่ทั้งหมดแล้ว — ฉบับที่ยังส่งไม่ได้จะกลับเข้าคิวพร้อมเหตุผลใหม่';
    }

    private function delete(MailQueue $queue, Executor $executor, string $id): string
    {
        $queue->delete($executor, $id);

        return sprintf('ลบเมล %s ออกจากคิวแล้ว', $id);
    }

    private function release(MailQueue $queue, Executor $executor, string $id): string
    {
        $queue->release($executor, $id);

        return sprintf('ปล่อยเมล %s กลับเข้าคิวปกติแล้ว', $id);
    }

    private function deleteAll(MailQueue $queue, Executor $executor): string
    {
        $before = $queue->list($executor)['total'];

        $queue->deleteAll($executor);

        return sprintf('ล้างคิวแล้ว — ลบเมลที่รอส่งอยู่ %d ฉบับ ไม่มีการแจ้งผู้ส่ง', $before);
    }
}
