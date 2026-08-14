<?php

declare(strict_types=1);

namespace Phpcp\Agent\Capability;

use Phpcp\Agent\Capability;
use Phpcp\Agent\Context;
use Phpcp\Agent\Executor\Executor;
use Phpcp\Agent\PermissionDenied;
use Phpcp\Agent\ValidationError;
use Phpcp\Domain\DiskQuota;
use Phpcp\Domain\Site;
use Phpcp\Driver\BackupManager;
use Phpcp\Driver\Db\MariaDbManager;
use Phpcp\Support\Validator;

/**
 * สร้างไฟล์สำรอง **ลงบ้านของเจ้าของเว็บ** — ไฟล์เว็บหรือฐานข้อมูล
 *
 * ## สิ่งที่เปลี่ยนไปจากของเดิม (PLAN-BACKUP-V2)
 *
 * เดิมเขียนลง `/var/lib/phpcp/backups` ซึ่งเป็นพื้นที่ของ panel แล้วบันทึกแถวลงตาราง
 * `backups` เป็นแหล่งความจริง · ตอนนี้ไฟล์ไปอยู่ที่ `<บ้าน>/backup` ของลูกค้า:
 * เขาดาวน์โหลดเองได้ ลบเองได้ และมันนับในโควตาของเขา — **จึงไม่บันทึกแถวคู่ขนานอีก**
 * (ดู {@see \Phpcp\Domain\BackupFiles} ว่าทำไมแถวที่ลูกค้าลบไฟล์ทิ้งได้จึงเป็นโทษ)
 *
 * ชนิด `config`/`full` ถูกตัดทิ้ง — ค่าตั้งของเครื่องไม่ใช่ของลูกค้าคนไหน จึงไม่มีบ้าน
 * ให้ไปอยู่ · สำรองด้วย snapshot ของ VPS หรือ git ตรงกว่า (ข้อ B2)
 *
 * `destination_id` ยังอยู่ เพราะงานตามเวลาเรียก capability ได้ทีละตัวต่อหนึ่งงาน —
 * ถ้าต้องแยก "สร้าง" กับ "ส่งออก" เป็นสองคำสั่ง จะมีช่วงที่ไฟล์สำรองอยู่บนดิสก์ก้อน
 * เดียวกับข้อมูลจริงโดยไม่มีอะไรพามันออกไป
 */
final class BackupCreate extends BackupCapability implements Capability
{
    private const DU = '/usr/bin/du';

    /** วัดขนาดต้องไม่กลายเป็นตัวที่ทำให้งานสำรองค้าง — เพดานเดียวกับ DiskQuotaCheck */
    private const MEASURE_TIMEOUT = 120;

    public static function name(): string
    {
        return 'backup.create';
    }

    /**
     * **สิทธิ์ของผู้ดูแลเซิร์ฟเวอร์ ไม่ใช่ของลูกค้า**
     *
     * การสร้างไฟล์สำรองหนึ่งครั้งกินพื้นที่เท่าเว็บทั้งเว็บในโควตาของลูกค้า และกิน CPU
     * ของเครื่องที่เว็บทุกรายใช้ร่วมกัน · ผู้ดูแลเป็นคนตัดสินว่าบัญชีไหนถูกสำรองบ้าง
     * (สวิตช์รายบัญชี + รอบเดียวทั้งเครื่อง) ปุ่ม "สำรองเดี๋ยวนี้" จึงต้องอยู่ในมือ
     * คนเดียวกัน ไม่ใช่ให้ลูกค้ากดเองได้ไม่จำกัด
     *
     * ลูกค้ายังมี `backup.manage` สำหรับ **ลบ** สำเนาของตัวเอง — ซึ่งคืนพื้นที่ ไม่ใช่กิน
     */
    public function permission(): string
    {
        return 'backup.offsite';
    }

    public function isMutating(): bool
    {
        return true;
    }

    public function summary(): string
    {
        return 'สร้างไฟล์สำรองของเว็บไซต์หรือฐานข้อมูล ลงในโฟลเดอร์สำรองของเจ้าของ';
    }

    public function validate(array $args): array
    {
        return [
            'type' => BackupManager::assertType(Validator::requireString($args, 'type', 16)),
            'site_id' => Validator::requireInt($args, 'site_id', 1),
            'database' => Validator::optionalString($args, 'database', '', 64),
            // 0 = เก็บไว้ในเครื่องอย่างเดียว · ระบุมาแล้วจะส่งออกต่อทันทีหลังสร้างเสร็จ
            'destination_id' => Validator::optionalInt($args, 'destination_id', 0, 0),
        ];
    }

    public function run(array $args, Executor $executor, Context $context): array
    {
        $site = $this->siteFor($context, $args['site_id']);
        $owner = $site->owner;

        // ต้องรู้ว่าจะสำรองฐานไหนก่อนวัดขนาด — และผู้ใช้ควรเห็น "เว็บนี้ไม่มีฐานข้อมูล"
        // ก่อนเห็น "โควตาไม่พอ" เสมอ เพราะข้อแรกเจาะจงกว่าและแก้ได้ตรงกว่า
        $database = $args['type'] === 'database'
            ? $this->database($context, $site->id, $args['database'])
            : '';

        $this->assertQuotaAllows($context, $owner, $this->estimateBytes($executor, $site, $database));

        $manager = new BackupManager();
        $ownerString = self::ownerString($context, $owner);

        $created = $database !== ''
            ? $manager->backupDatabase($executor, $site, $database, $ownerString)
            : $manager->backupSite($executor, $site, $ownerString);

        $file = [
            'type' => $args['type'],
            'domain' => $site->domain,
            'user_id' => $owner->userId,
            'name' => basename($created['path']),
            'path' => $created['path'],
            'bytes' => $created['bytes'],
            'checksum' => $created['checksum'],
        ];

        $offsite = $this->push($file, $args['destination_id'], $executor, $context);

        return [
            'created' => [$file],
            'count' => 1,
            'bytes' => $created['bytes'],
            'offsite' => $offsite,
            'message' => sprintf(
                'สำรอง%s %s แล้ว (%s ไบต์) เก็บที่ %s',
                BackupManager::typeLabel($args['type']),
                $site->domain,
                number_format($created['bytes']),
                $owner->backupDir(),
            ) . ($offsite === [] ? '' : ' · ' . $offsite['message']),
        ];
    }

    /**
     * ขนาดที่ไฟล์สำรองนี้จะกินอย่างมากที่สุด — ตัวเลขที่ด่านโควตาต้องใช้
     *
     * **เป็นขนาดก่อนบีบอัดโดยตั้งใจ** · ไฟล์จริงที่ได้เล็กกว่านี้เกือบเสมอ (ข้อความ
     * ของเว็บและ SQL บีบได้ราว 5-10 เท่า) แต่ด่านโควตาต้องเผื่อไว้ทางที่ปลอดภัย —
     * เดาต่ำแล้วผิดคือดิสก์เต็มจนเว็บของลูกค้ารายอื่นเขียนไฟล์ไม่ได้ ส่วนเดาสูงแล้วผิด
     * คือข้อความที่บอกให้ลบไฟล์เก่าก่อน ซึ่งลูกค้าแก้เองได้ใน 10 วินาที · ข้อความ
     * ของด่านจึงเขียนว่า "ไม่เกิน" ไม่ใช่ "ต้องการ" ({@see DiskQuota::assertFits()})
     *
     * วัดไม่ได้ = คืน UNKNOWN ไม่ใช่ 0 ที่แปลว่า "ไม่กินที่เลย" — บัญชีที่วัดบ้านไม่ได้
     * ต้องตกไปใช้ด่าน "เต็มหรือยัง" ไม่ใช่ผ่านฉลุยเพราะการวัดล้ม
     */
    private function estimateBytes(Executor $executor, Site $site, string $database): int
    {
        try {
            if ($database !== '') {
                return (new MariaDbManager())->sizes($executor)[$database] ?? DiskQuota::UNKNOWN;
            }

            return $this->measureDirectory($executor, $site);
        } catch (\Throwable) {
            // วัดไม่ได้ต้องไม่ทำให้การสำรองล้มทั้งงาน — ด่านที่เหลือยังกัน "โควตาเต็ม" อยู่
            return DiskQuota::UNKNOWN;
        }
    }

    /**
     * ขนาดของ docroot เป็นไบต์ — เดินไฟล์ด้วยสิทธิ์เจ้าของตาม ARCHITECTURE §4.4
     *
     * เดินต้นไม้ไฟล์เพิ่มอีกรอบก่อน tar จะเดินซ้ำ ซึ่งยอมรับได้: `du` อ่านแต่ metadata
     * ส่วน tar อ่านเนื้อไฟล์ทั้งหมดแล้วบีบอัด — ต้นทุนต่างกันคนละระดับ
     */
    private function measureDirectory(Executor $executor, Site $site): int
    {
        $path = $executor->path($site->docroot());

        if (!$executor->exists($path)) {
            return DiskQuota::UNKNOWN;
        }

        $result = $executor->asUser($site->systemUser(), static function () use ($executor, $path): array {
            $run = $executor->exec([self::DU, '-sk', '-x', '--', $path], timeout: self::MEASURE_TIMEOUT);

            return ['ok' => $run->ok(), 'out' => $run->output()];
        });

        if (($result['ok'] ?? false) !== true
            || preg_match('/^(\d+)/', (string) ($result['out'] ?? ''), $m) !== 1) {
            return DiskQuota::UNKNOWN;
        }

        return ((int) $m[1]) * 1024;
    }

    /**
     * ฐานข้อมูลที่จะสำรอง — ต้องเป็นของเว็บนี้จริง
     *
     * เว็บที่มีฐานเดียวไม่ต้องระบุ · การเดาให้เมื่อมีหลายฐานคือการสำรองผิดฐานแล้ว
     * รายงานว่าสำเร็จ ซึ่งรู้ตัวตอนกู้คืนเท่านั้น
     *
     * @throws ValidationError|PermissionDenied
     */
    private function database(Context $context, int $siteId, string $requested): string
    {
        $owned = array_map(
            static fn (array $row): string => (string) $row['db_name'],
            $context->db->all('SELECT db_name FROM databases_ WHERE site_id = :id ORDER BY db_name', ['id' => $siteId]),
        );

        if ($owned === []) {
            throw new ValidationError('เว็บไซต์นี้ยังไม่มีฐานข้อมูลให้สำรอง');
        }

        if ($requested === '') {
            if (count($owned) > 1) {
                throw new ValidationError(
                    'เว็บไซต์นี้มีหลายฐานข้อมูล (' . implode(', ', $owned) . ') — ต้องเลือกว่าจะสำรองฐานไหน',
                );
            }

            return $owned[0];
        }

        if (!in_array($requested, $owned, true)) {
            throw new PermissionDenied('ฐานข้อมูลนี้ไม่ได้เป็นของเว็บไซต์ที่เลือก');
        }

        return $requested;
    }

    /**
     * ส่งไฟล์ที่เพิ่งสร้างออกไปยังปลายทาง — ขั้นที่ทำให้ "สำรองอัตโนมัติ" มีความหมายจริง
     *
     * **ส่งไม่สำเร็จไม่ทำให้ทั้งคำสั่งล้ม** โดยตั้งใจ · ไฟล์ในเครื่องสร้างเสร็จแล้วและ
     * ใช้กู้คืนได้อยู่ · การโยน error ทิ้งทั้งงานจะทำให้ผู้ใช้เข้าใจว่าไม่มีไฟล์สำรองเลย
     * ทั้งที่มี — ผลการส่งจึงกลับไปกับคำตอบให้เห็นแทน
     *
     * @param  array<string,mixed> $file
     * @return array<string,mixed>
     */
    private function push(array $file, int $destinationId, Executor $executor, Context $context): array
    {
        if ($destinationId < 1) {
            return [];
        }

        try {
            $result = (new BackupPush())->run([
                'user_id' => $file['user_id'],
                'file' => $file['name'],
                'destination_id' => $destinationId,
            ], $executor, $context);

            return ['ok' => true, 'message' => (string) ($result['message'] ?? 'ส่งออกนอกเครื่องแล้ว')];
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => 'ส่งออกนอกเครื่องไม่สำเร็จ: ' . $e->getMessage()];
        }
    }
}
