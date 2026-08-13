<?php

declare(strict_types=1);

namespace Phpcp\Driver\Mail;

use Phpcp\Agent\ExecutionFailed;
use Phpcp\Agent\Executor\Executor;
use Phpcp\Agent\ValidationError;

/**
 * คิวเมลขาออกของ Postfix — PLAN-MAIL §5 (`mail.queue`)
 *
 * ## ทำไมคิวถึงเป็นที่ที่ต้องดูเวลาเมลไม่ถึง
 *
 * เมลที่ส่งไม่สำเร็จไม่ได้หายไปไหน มันค้างอยู่ในคิวพร้อม**เหตุผลรายฉบับ**ที่ปลายทาง
 * ตอบกลับมา ("Connection refused", "550 spam", "Recipient address rejected") · Postfix
 * ลองส่งใหม่ให้เองไปเรื่อย ๆ หลายวันก่อนจะยอมแพ้ ผู้ดูแลจึงมีเวลาเห็นและแก้
 *
 * ถ้าไม่มีหน้าจอนี้ คำถาม "ทำไมเมลไม่ถึง" ตอบได้ทางเดียวคือ ssh เข้าไปแล้วพิมพ์
 * `postqueue -p` ซึ่งเท่ากับว่าคนที่ไม่มี ssh ตอบคำถามนี้ไม่ได้เลย
 *
 * ## เมลในคิวยังไม่ได้ถูกส่งเข้ากล่องใคร
 *
 * การเปิดดูเนื้อเมลในคิวจึงไม่ได้แตะกล่องจดหมายของใครทั้งสิ้น และไม่มีเรื่อง
 * "มาร์คว่าอ่านแล้ว" ให้ต้องกังวล — ต่างจากการเปิดอ่าน Maildir ซึ่งเป็นคนละเรื่องกัน
 */
final class MailQueue
{
    private const POSTQUEUE = '/usr/sbin/postqueue';
    private const POSTSUPER = '/usr/sbin/postsuper';
    private const POSTCAT = '/usr/sbin/postcat';

    /**
     * รายการในคิวทั้งหมด
     *
     * `postqueue -j` คืน JSON บรรทัดละหนึ่งฉบับ (Postfix 3.1 ขึ้นไป) · เครื่องที่เก่ากว่า
     * นั้นไม่มีตัวเลือกนี้ — คืน `available: false` ไปให้หน้าจอบอกตรง ๆ ดีกว่าโชว์คิวว่าง
     * ซึ่งอ่านได้ว่า "ไม่มีเมลค้าง" ทั้งที่ความจริงคือ "ดูไม่ได้"
     *
     * @return array{available:bool,messages:list<array<string,mixed>>,total:int,deferred:int,oldest:int}
     */
    public function list(Executor $executor): array
    {
        $result = $executor->exec([$executor->path(self::POSTQUEUE), '-j'], timeout: 30);

        if (!$result->ok()) {
            return ['available' => false, 'messages' => [], 'total' => 0, 'deferred' => 0, 'oldest' => 0];
        }

        $messages = [];
        $deferred = 0;
        $oldest = 0;
        $now = time();

        foreach (preg_split('/\R/', $result->stdout) ?: [] as $line) {
            $line = trim($line);

            if ($line === '') {
                continue;
            }

            $row = json_decode($line, true);

            if (!is_array($row) || !isset($row['queue_id'])) {
                continue;
            }

            $queue = (string) ($row['queue_name'] ?? '');
            $arrived = (int) ($row['arrival_time'] ?? 0);

            if ($queue === 'deferred') {
                $deferred++;
            }

            if ($arrived > 0) {
                $oldest = $oldest === 0 ? $arrived : min($oldest, $arrived);
            }

            $recipients = [];
            $reason = '';

            foreach ((array) ($row['recipients'] ?? []) as $recipient) {
                $recipients[] = (string) ($recipient['address'] ?? '');

                // เหตุผลของฉบับนี้ = เหตุผลแรกที่เจอ · หลายผู้รับที่ล้มด้วยเหตุผลต่างกัน
                // พบน้อยมาก และการโชว์เหตุผลเดียวยังดีกว่าไม่โชว์เลย
                if ($reason === '' && ($recipient['delay_reason'] ?? '') !== '') {
                    $reason = (string) $recipient['delay_reason'];
                }
            }

            $messages[] = [
                'id' => (string) $row['queue_id'],
                'queue' => $queue,
                'sender' => (string) ($row['sender'] ?? ''),
                'recipients' => $recipients,
                'size' => (int) ($row['message_size'] ?? 0),
                'arrival_time' => $arrived,
                'age_seconds' => $arrived > 0 ? max(0, $now - $arrived) : 0,
                'reason' => $reason,
            ];
        }

        return [
            'available' => true,
            'messages' => $messages,
            'total' => count($messages),
            'deferred' => $deferred,
            'oldest' => $oldest,
        ];
    }

    /** สั่งให้ Postfix ลองส่งทุกฉบับในคิวใหม่เดี๋ยวนี้ ไม่ต้องรอรอบถัดไป */
    public function flush(Executor $executor): void
    {
        $result = $executor->exec([$executor->path(self::POSTQUEUE), '-f'], timeout: 60);

        if (!$result->ok()) {
            throw new ExecutionFailed('สั่งส่งคิวใหม่ไม่สำเร็จ: ' . trim($result->stderr ?: $result->stdout));
        }
    }

    /** ลบเมลฉบับเดียวออกจากคิว — เมลฉบับนั้นหายถาวร ไม่มีการแจ้งผู้ส่ง */
    public function delete(Executor $executor, string $id): void
    {
        $this->postsuper($executor, '-d', self::assertId($id));
    }

    /**
     * ลบทั้งคิว — แยกเป็นเมธอดของตัวเอง **ไม่ใช่ delete('ALL')**
     *
     * `postsuper -d ALL` ล้างคิวทั้งเครื่อง · ถ้าปล่อยให้เดินทางเดียวกับการลบฉบับเดียว
     * ค่า `ALL` ที่หลุดมาจากพารามิเตอร์ (หรือผู้เรียกที่พิมพ์ผิด) จะกลายเป็นการลบ
     * เมลของลูกค้าทุกฉบับที่รอส่งอยู่ทันที โดยที่ทุกด่านตรวจมองว่าเป็นรหัสที่ถูกต้อง
     */
    public function deleteAll(Executor $executor): void
    {
        $this->postsuper($executor, '-d', 'ALL');
    }

    /** ปล่อยเมลที่ถูกพักไว้กลับเข้าคิวปกติ */
    public function release(Executor $executor, string $id): void
    {
        $this->postsuper($executor, '-H', self::assertId($id));
    }

    /**
     * เนื้อเมลในคิวหนึ่งฉบับ — หัวจดหมายกับเนื้อความ
     *
     * ตัดความยาวไว้ เพราะไฟล์แนบขนาดใหญ่ที่ถูกเข้ารหัส base64 ไม่มีประโยชน์กับการ
     * ไล่ปัญหา แต่ทำให้คำตอบใหญ่จนเบราว์เซอร์อืดได้จริง
     */
    public function message(Executor $executor, string $id, int $limit = 60000): string
    {
        $result = $executor->exec(
            [$executor->path(self::POSTCAT), '-q', self::assertId($id)],
            timeout: 30,
        );

        if (!$result->ok()) {
            throw new ExecutionFailed(
                'อ่านเมลฉบับนี้ไม่ได้ — อาจถูกส่งออกไปแล้วหรือถูกลบไปแล้ว: '
                . trim($result->stderr ?: $result->stdout),
            );
        }

        return mb_substr($result->stdout, 0, $limit);
    }

    private function postsuper(Executor $executor, string $flag, string $target): void
    {
        $result = $executor->exec(
            [$executor->path(self::POSTSUPER), $flag, $target],
            timeout: 60,
        );

        if (!$result->ok()) {
            throw new ExecutionFailed('คำสั่งกับคิวเมลไม่สำเร็จ: ' . trim($result->stderr ?: $result->stdout));
        }
    }

    /**
     * รหัสในคิวต้องเป็นรหัสจริงเท่านั้น
     *
     * **`ALL` ต้องถูกปฏิเสธที่นี่** — มันผ่านกติกาตัวอักษร/ตัวเลขได้สบาย ๆ แต่สำหรับ
     * `postsuper` มันคือคำสั่งลบทั้งคิว · การลบทั้งคิวมีเมธอดของตัวเองที่ผู้เรียกต้อง
     * ตั้งใจเรียกเท่านั้น
     */
    public static function assertId(string $id): string
    {
        $id = trim($id);

        if (strtoupper($id) === 'ALL') {
            throw new ValidationError('ต้องระบุรหัสของเมลฉบับที่ต้องการ — ล้างทั้งคิวเป็นคำสั่งแยกต่างหาก');
        }

        // รหัสของ Postfix เป็นเลขฐานสิบหก หรือ base52 เมื่อเปิด long queue id
        if (preg_match('/^[A-Za-z0-9]{4,40}$/', $id) !== 1) {
            throw new ValidationError('รหัสเมลในคิวไม่ถูกต้อง: ' . $id);
        }

        return $id;
    }
}
