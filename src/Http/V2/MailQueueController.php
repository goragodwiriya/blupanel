<?php

declare(strict_types=1);

namespace Phpcp\Http\V2;

use Phpcp\Http\ApiController;
use Phpcp\Http\ApiProblem;
use Phpcp\Kernel\Request;
use Phpcp\Kernel\Response;

/**
 * คิวเมลขาออก — `/api/v2/mail/queue` (PLAN-MAIL §5)
 *
 * ที่นี่แปลงคำขอเป็น argument ของ capability แล้วแปลงผลกลับเป็น JSON เท่านั้น ·
 * การตัดสินใจว่าคำสั่งไหนอันตรายแค่ไหนอยู่ที่ capability ซึ่งเป็นที่เดียวที่แตะเครื่องได้
 */
final class MailQueueController extends ApiController
{
    /** รายการในคิว พร้อมสรุปที่หน้าจอใช้ตัดสินว่าต้องลงมือไหม */
    public function index(Request $request): Response
    {
        $result = $this->agent()->data('mail.queue', [], $this->ctx->actor($request));
        $now = time();

        $rows = array_map(
            fn (array $row): array => $row + [
                'row_id' => $row['id'],
                'recipient' => implode(', ', (array) ($row['recipients'] ?? [])),
                // ค้างนานแค่ไหนคือสิ่งที่บอกว่าเรื่องนี้ด่วนหรือยัง — Postfix ยอมแพ้ที่ 5 วัน
                'age' => max(0, $now - (int) ($row['arrival_time'] ?? $now)),
                'tone' => ($row['queue'] ?? '') === 'deferred' ? 'danger' : 'ok',
                'reason_short' => mb_substr((string) ($row['reason'] ?? ''), 0, 120),
            ],
            (array) ($result['messages'] ?? []),
        );

        /*
         * **คืนเป็นรายการล้วน เพราะตารางเป็นคนดึงข้อมูลเอง (`data-source`)**
         *
         * รูปแบบเดียวกับหน้าฐานข้อมูลและหน้าเว็บไซต์ที่ใช้งานได้อยู่แล้ว · ตารางที่ดึง
         * ข้อมูลเองเท่านั้นที่สั่ง "โหลดใหม่" ได้จริงหลังลบสำเร็จ — ตารางที่ผูกกับข้อมูล
         * ของหน้าต้องให้ทั้งหน้าโหลดใหม่ ซึ่งเป็นทางที่ยังไม่มีกลไกรองรับ
         *
         * อ่านคิวไม่ได้ = ข้อผิดพลาด ไม่ใช่คิวว่าง · ตอบเป็น problem ไปเลยดีกว่าโชว์
         * ตารางเปล่าซึ่งอ่านได้ว่า "ไม่มีเมลค้าง" ทั้งที่ความจริงคือ "ดูไม่ได้"
         */
        if (($result['available'] ?? false) !== true) {
            return $this->problem(
                ApiProblem::AgentUnavailable,
                'Cannot read the queue on this server.',
                ['postqueue' => (string) ($result['message'] ?? '')],
            );
        }

        return $this->ok($rows);
    }

    /** เนื้อเมลหนึ่งฉบับในคิว — เปิดใน Modal ไม่ใช่หน้าใหม่ */
    public function show(Request $request): Response
    {
        $result = $this->agent()->data(
            'mail.message',
            ['id' => $request->param('id')],
            $this->ctx->actor($request),
        );

        return $this->done(
            'Queued message',
            [[
                'type' => 'modal',
                'action' => 'show',
                'title' => $this->t('Queued message') . ' ' . (string) ($result['id'] ?? ''),
                'titleClass' => 'icon-email',
                // ข้อความดิบ — ต้องหนี HTML ทั้งก้อน เนื้อเมลมาจากคนนอกทั้งหมด
                'html' => '<pre class="mono selectable scroll">'
                    . htmlspecialchars((string) ($result['content'] ?? ''), ENT_QUOTES, 'UTF-8')
                    . '</pre>',
            ]],
            is_array($result) ? $result : [],
        );
    }

    /** ลองส่งใหม่ทั้งคิวเดี๋ยวนี้ */
    public function flush(Request $request): Response
    {
        return $this->act($request, ['action' => 'flush']);
    }

    /** ลบฉบับเดียว */
    public function destroy(Request $request): Response
    {
        return $this->act($request, ['action' => 'delete', 'id' => $request->param('id')]);
    }

    /**
     * ล้างทั้งคิว
     *
     * เส้นทางแยกจากการลบฉบับเดียวโดยเจตนา — ไม่มีทางที่ค่า id แปลก ๆ จะกลายเป็น
     * การลบทั้งคิวได้ เพราะสองอย่างนี้ไม่ได้ใช้เส้นทางเดียวกันเลย
     */
    public function destroyAll(Request $request): Response
    {
        return $this->act($request, ['action' => 'delete_all']);
    }

    /**
     * @param array<string,mixed> $args
     *
     * **สั่งให้ตารางโหลดใหม่ด้วย `completed()` ไม่ใช่เหตุการณ์ของหน้า**
     *
     * `completed($message, 'ชื่อตาราง')` ส่งคำสั่ง `{type:"redirect", url:"reload"}` กลับไป
     * ซึ่งเป็นชนิดที่เฟรมเวิร์กมีตัวรับให้อยู่แล้ว และ `TableManager` ส่งฟังก์ชัน
     * `reloadTable` มาให้ตอนเรียก `ResponseHandler.process()` หลังปุ่มในแถวทำงาน —
     * เป็นเส้นทางเดียวกับหน้าฐานข้อมูลที่ลบแล้วตารางรีเฟรชถูกต้องมาตลอด
     *
     * ที่เคยส่ง `{type:"event"}` ไปนั้น **ไม่มีตัวรับชนิดนั้นอยู่เลย** ·
     * `ResponseHandler.executeAction` ค้นไม่เจอก็เตือนใน console แล้วผ่านไปเงียบ ๆ
     * ผลคือลบสำเร็จจริงบนเซิร์ฟเวอร์ แต่ตารางยังโชว์แถวเดิมเหมือนไม่มีอะไรเกิดขึ้น
     */
    private function act(Request $request, array $args): Response
    {
        $result = $this->agent()->data('mail.queue_action', $args, $this->ctx->actor($request));

        return $this->completed(
            (string) ($result['message'] ?? 'Queue updated'),
            'mailQueue',
            is_array($result) ? $result : [],
        );
    }
}
