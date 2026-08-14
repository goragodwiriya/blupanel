<?php

declare (strict_types = 1);

namespace Phpcp\Agent\Capability;

use Phpcp\Agent\Context;
use Phpcp\Agent\Executor\Executor;
use Phpcp\Agent\ValidationError;
use Phpcp\Domain\FileCatalog;
use Phpcp\Support\Validator;

/**
 * ส่งเนื้อไฟล์กลับไปให้ผู้ใช้ดาวน์โหลด — **ทีละก้อน ไม่จำกัดขนาดไฟล์**
 *
 * ## ทำไมต้องเป็นก้อน
 *
 * เนื้อไฟล์ถูกเข้ารหัส base64 เพราะโปรโตคอลเป็น JSON บรรทัดเดียวที่มีเพดาน 4 MB ต่อ
 * เฟรม · เดิมจึงปฏิเสธไฟล์ที่ใหญ่กว่า 2.5 MB ไปเลย พร้อมข้อความว่า "ให้บีบอัดเป็น zip
 * ก่อน" ซึ่งเป็นคำแนะนำที่ใช้ไม่ได้กับสิ่งที่ผู้ใช้อยากดาวน์โหลดมากที่สุด: **ไฟล์สำรอง
 * ของตัวเอง** ที่บีบอัดมาแล้วและใหญ่กว่านั้นเสมอ (ตั้งแต่ PLAN-BACKUP-V2 ไฟล์พวกนั้น
 * อยู่ในบ้านของลูกค้าและเขาต้องหยิบออกมาได้จริง ไม่งั้นการรื้อทั้งครั้งไม่มีความหมาย)
 *
 * ผู้เรียกจึงขอทีละช่วง (`offset`/`length`) แล้วต่อกันเองที่ชั้น HTTP ซึ่งสตรีมออก
 * ไปเรื่อย ๆ โดยไม่เก็บทั้งไฟล์ไว้ในหน่วยความจำ · คำตอบบอก `size` (ขนาดทั้งไฟล์)
 * และ `eof` มาด้วย ผู้เรียกจึงรู้ว่าต้องขอต่ออีกไหมโดยไม่ต้องเดา
 *
 * ## ทำไมอ่านไฟล์ด้วยฟังก์ชันของ PHP ตรง ๆ
 *
 * `Executor` ไม่มีเมธอดอ่านบางส่วน และการเพิ่มเข้าไปแปลว่าต้องแก้ตัวมันทุกตัวรวมถึง
 * ของปลอมในเทสต์อีกเก้าไฟล์ เพื่อความสามารถที่ใช้ที่เดียว · เส้นทางที่ `path()` แปลง
 * ให้แล้วเป็นเส้นทางจริงบนดิสก์ และ agent รันเป็น root จึงอ่านได้เสมอ — รูปแบบเดียว
 * กับที่ `BackupManager` เรียก `hash_file()` บนเส้นทางที่แปลงแล้ว · **ด่านสิทธิ์ยัง
 * อยู่ครบ** เพราะเส้นทางผ่าน `withPath()` ซึ่งตรวจขอบเขตกับ symlink มาแล้ว
 */
final class FileDownload extends FileCapability
{
    public static function name(): string
    {
        return 'file.download';
    }

    public function isMutating(): bool
    {
        return false;
    }

    public function summary(): string
    {
        return 'ดาวน์โหลดไฟล์';
    }

    /**
     * @param array $args
     * @return mixed
     */
    public function validate(array $args): array
    {
        $base = self::baseArgs($args);

        if ($base['path'] === '') {
            throw new ValidationError('ต้องระบุไฟล์ที่จะดาวน์โหลด');
        }

        return $base + [
            'offset' => Validator::optionalInt($args, 'offset', 0, 0),
            // 0 = เท่าที่ส่งได้ในเฟรมเดียว · ค่าที่ใหญ่กว่านั้นถูกบีบลงมา ไม่ใช่ปฏิเสธ
            // เพราะผู้เรียกที่ขอเกินไม่ได้ทำอะไรผิด แค่ไม่รู้เพดานของโปรโตคอล
            'length' => Validator::optionalInt($args, 'length', 0, 0),
        ];
    }

    /**
     * @param array $args
     * @param Executor $executor
     * @param Context $context
     */
    public function run(array $args, Executor $executor, Context $context): array
    {
        $scope = $this->scope($context, $args);
        $relative = $args['path'];

        $offset = $args['offset'];
        $length = $args['length'] > 0
            ? min($args['length'], FileCatalog::MAX_TRANSFER_BYTES)
            : FileCatalog::MAX_TRANSFER_BYTES;

        $result = $this->withPath($executor, $scope, $relative, static function (string $root, string $target) use ($executor, $offset, $length): array {
            $info = $executor->stat($target);

            if ($info === null || $info['type'] !== 'file') {
                throw new ValidationError('ดาวน์โหลดได้เฉพาะไฟล์ธรรมดา (โฟลเดอร์ให้บีบอัดเป็น zip ก่อน)');
            }

            $size = (int) $info['size'];

            // ขอเลยท้ายไฟล์ = จบแล้ว ไม่ใช่ข้อผิดพลาด · ไฟล์ว่างก็เดินทางนี้
            if ($offset >= $size) {
                return ['content' => '', 'size' => $size, 'bytes' => 0];
            }

            $handle = @fopen($target, 'rb');

            if ($handle === false) {
                throw new \Phpcp\Agent\ExecutionFailed('เปิดไฟล์เพื่อดาวน์โหลดไม่ได้');
            }

            try {
                if ($offset > 0 && fseek($handle, $offset) !== 0) {
                    throw new \Phpcp\Agent\ExecutionFailed('เลื่อนตำแหน่งในไฟล์ไม่สำเร็จ');
                }

                $chunk = fread($handle, $length);
            } finally {
                fclose($handle);
            }

            if ($chunk === false) {
                throw new \Phpcp\Agent\ExecutionFailed('อ่านไฟล์เพื่อดาวน์โหลดไม่ได้');
            }

            return ['content' => base64_encode($chunk), 'size' => $size, 'bytes' => strlen($chunk)];
        });

        $end = $offset + $result['bytes'];

        return [
            'root' => $scope->key,
            'site_id' => $scope->siteId,
            'path' => $relative,
            'name' => basename($relative),
            'size' => $result['size'],
            'offset' => $offset,
            'bytes' => $result['bytes'],
            // ผู้เรียกต้องรู้ว่าจบแล้วโดยไม่ต้องคำนวณเอง — คำนวณผิดข้างเดียวคือไฟล์ขาด
            'eof' => $end >= $result['size'],
            'content' => $result['content']
        ];
    }
}
