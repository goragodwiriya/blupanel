<?php

declare(strict_types=1);

namespace Phpcp\Agent\Capability;

use Phpcp\Agent\Capability;
use Phpcp\Agent\Context;
use Phpcp\Agent\Executor\Executor;
use Phpcp\Agent\ValidationError;
use Phpcp\Domain\BackupFiles;
use Phpcp\Driver\BackupManager;
use Phpcp\Support\Validator;

/**
 * กู้คืนเว็บไซต์จากไฟล์ในโฟลเดอร์สำรองของเจ้าของ — งานที่เสี่ยงที่สุดในระบบนี้
 *
 * เสี่ยงเพราะเป็นการ "เขียนทับของที่ใช้งานอยู่" ด้วยข้อมูลเก่า มาตรการที่ใช้:
 *   1. ตรวจ checksum ก่อน — ไฟล์เสียหายบางส่วนอันตรายกว่าไม่มีไฟล์
 *   2. สำรองสถานะปัจจุบันอัตโนมัติก่อนเขียนทับ — กู้ผิดตัวแล้วยังย้อนได้
 *   3. แตกไฟล์ลงที่ชั่วคราวแล้วค่อยสลับ — ล้มกลางทางไม่เหลือไฟล์ปนกัน
 *   4. ต้องพิมพ์ชื่อโดเมนยืนยัน — กันการกดผิดรายการ
 *
 * ## รับชื่อไฟล์ ไม่ใช่รหัสแถว (PLAN-BACKUP-V2 ข้อ 4.5)
 *
 * เดิมรับ `backup_id` แล้วอ่านเส้นทางกับ checksum จากตาราง · แถวนั้นเพี้ยนได้ทุกเมื่อ
 * เพราะลูกค้าลบไฟล์ของตัวเองได้ผ่าน SFTP — ปุ่มกู้คืนจึงชี้ไปที่ของที่ไม่มีอยู่
 * · ตอนนี้ชี้ที่ไฟล์ในโฟลเดอร์ของเจ้าของโดยตรง และ **checksum คำนวณจากไฟล์นั้นเดี๋ยวนั้น**
 *
 * ค่า checksum ที่คำนวณสด ๆ ยังมีประโยชน์เต็มที่: `restoreSite()` ตรวจซ้ำอีกครั้งหลัง
 * สร้างไฟล์สำรองนิรภัยและก่อนแตกไฟล์ — ด่านนั้นจับกรณีที่ไฟล์ถูกแก้ระหว่างสองจังหวะ
 * ซึ่งเป็นช่วงเดียวที่ระบบเขียนอะไรลงโฟลเดอร์เดียวกันนั้น
 */
final class BackupRestore extends BackupCapability implements Capability
{
    public static function name(): string
    {
        return 'backup.restore';
    }

    public function permission(): string
    {
        return 'backup.restore';
    }

    public function isMutating(): bool
    {
        return true;
    }

    public function summary(): string
    {
        return 'กู้คืนไฟล์เว็บไซต์จากไฟล์สำรองของเจ้าของ (ตรวจ checksum และสำรองของเดิมก่อนเสมอ)';
    }

    public function validate(array $args): array
    {
        return [
            'site_id' => Validator::requireInt($args, 'site_id', 1),
            'file' => BackupFiles::assertName(Validator::requireString($args, 'file', 255)),
            'confirm' => Validator::requireString($args, 'confirm', 253),
        ];
    }

    public function run(array $args, Executor $executor, Context $context): array
    {
        $site = $this->siteFor($context, $args['site_id']);

        if (BackupFiles::typeOf($args['file']) !== 'site') {
            // การกู้คืนฐานข้อมูลมีขั้นตอนต่างออกไปมาก (ต้องหยุดเว็บ ล้างตาราง แล้ว
            // นำเข้าใหม่ทั้งชุด) ทำครึ่ง ๆ กลาง ๆ อันตรายกว่าไม่ทำ · ลูกค้าดาวน์โหลด
            // ไฟล์ .sql.gz ของตัวเองไปนำเข้าผ่าน phpMyAdmin ได้อยู่แล้ว
            throw new ValidationError(
                'ตอนนี้กู้คืนได้เฉพาะไฟล์เว็บไซต์ — ไฟล์ฐานข้อมูลให้ดาวน์โหลดไปนำเข้าเองผ่าน phpMyAdmin',
            );
        }

        if ($args['confirm'] !== $site->domain) {
            throw new ValidationError('ชื่อโดเมนที่ยืนยันไม่ตรงกับเว็บไซต์ปลายทาง — ยกเลิกเพื่อความปลอดภัย');
        }

        $archive = BackupFiles::resolve($site->owner, $args['file']);

        $this->assertFileExists($executor, $archive);
        $this->assertBelongsTo($executor, $archive, $site->domain);

        /*
         * เจ้าของที่ไฟล์ต้องเป็นหลังกู้คืน — ไม่ส่งค่านี้ไปแปลว่าไฟล์ที่กู้มาจะเป็นของ
         * root (ไดเรกทอรี) และของ uid ต้นทาง (ไฟล์ข้างใน) ซึ่งพังทันทีเมื่อกู้ข้ามเครื่อง
         * · ดูเหตุผลเต็มใน BackupManager::restoreSite()
         */
        $owner = self::ownerString($context, $site->owner);

        $checksum = @hash_file('sha256', $executor->path($archive));

        if ($checksum === false) {
            throw new ValidationError('อ่านไฟล์สำรองเพื่อตรวจสอบไม่ได้');
        }

        $result = (new BackupManager())->restoreSite($executor, $site, $archive, $checksum, $owner);

        return [
            'site_id' => $site->id,
            'domain' => $site->domain,
            'file' => $args['file'],
            'entries' => $result['entries'],
            'safety_file' => basename($result['safety']),
            'message' => sprintf(
                'กู้คืน %s จาก %s แล้ว (%d รายการ) — สำรองสถานะก่อนกู้คืนไว้ที่ %s',
                $site->domain,
                $args['file'],
                $result['entries'],
                basename($result['safety']),
            ),
        ];
    }

    /**
     * ไฟล์นี้เป็นของโดเมนนี้จริงหรือไม่ — ถามใบแจ้งข้อมูลข้างในไฟล์ ไม่ใช่ดูจากชื่อ
     *
     * **ด่านนี้ขาดไม่ได้ตั้งแต่โฟลเดอร์เป็นของลูกค้า** — เขาเปลี่ยนชื่อไฟล์เองได้และ
     * คัดลอกไฟล์จากที่อื่นเข้ามาวางได้ · ชื่อที่ขึ้นต้นด้วย `shop.example.com-files-`
     * จึงไม่ใช่คำสัญญาว่าข้างในเป็นไฟล์ของ shop.example.com · กู้ผิดตัวคือการเขียนทับ
     * เว็บที่ให้บริการอยู่ด้วยไฟล์ของเว็บอื่น ซึ่งย้อนได้แค่จากไฟล์สำรองนิรภัยเท่านั้น
     *
     * ไฟล์รุ่นเก่าที่ยังไม่มีใบแจ้งข้อมูล (`readManifest()` คืน null) ถูกปฏิเสธ —
     * "บอกไม่ได้ว่าเป็นของใคร" ต้องไม่แปลว่า "เดาว่าเป็นของเว็บที่ผู้ใช้กำลังเปิดอยู่"
     */
    private function assertBelongsTo(Executor $executor, string $archive, string $domain): void
    {
        $manifest = (new BackupManager())->readManifest($executor, $archive);

        if ($manifest === null) {
            throw new ValidationError(
                'ไฟล์นี้ไม่มี ' . BackupManager::MANIFEST . ' อยู่ข้างใน จึงบอกไม่ได้ว่าเป็นไฟล์สำรองของเว็บไหน'
                . ' — กู้คืนให้ไม่ได้เพราะอาจเขียนทับเว็บนี้ด้วยไฟล์ของเว็บอื่น',
            );
        }

        $inside = (string) ($manifest['domain'] ?? '');

        if ($inside !== $domain) {
            throw new ValidationError(sprintf(
                'ไฟล์นี้เป็นไฟล์สำรองของ %s ไม่ใช่ของ %s — กู้คืนข้ามเว็บผ่านหน้านี้ไม่ได้',
                $inside === '' ? 'เว็บที่ระบุไม่ได้' : $inside,
                $domain,
            ));
        }
    }
}
