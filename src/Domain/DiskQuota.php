<?php

declare(strict_types=1);

namespace Phpcp\Domain;

use Phpcp\Agent\ValidationError;
use Phpcp\Kernel\Db;
use Phpcp\Security\Permissions;

/**
 * ด่านโควตาดิสก์ของ "การเขียนหนึ่งครั้ง" — แหล่งความจริงเดียวว่าเขียนต่อได้ไหม
 *
 * ## ทำไมต้องมีคลาสนี้ ทั้งที่มี QuotaChecker อยู่แล้ว
 *
 * {@see QuotaChecker::checkOwnerCanCreate()} ตอบคำถามว่า "สร้าง**ทรัพยากร**ใหม่ได้ไหม"
 * (เว็บ ฐานข้อมูล อีเมล) ซึ่งเป็นของนับเป็นชิ้น · ที่นี่ตอบอีกคำถามหนึ่งคือ "เขียนข้อมูล
 * อีก N ไบต์ลงบ้านของบัญชีนี้ได้ไหม" ซึ่งเป็นของนับเป็นขนาด — คำถามคนละข้อ แต่ใช้ตัวเลข
 * ชุดเดียวกัน (`disk_quota_mb`/`disk_used_mb`) จึงต้องตีความเหมือนกันเป๊ะ
 *
 * ก่อนหน้านี้กฎถูกเขียนซ้ำในแต่ละที่แล้วเพี้ยนกันจริง: ด่านของไฟล์สำรองผ่านเมื่อ
 * "เหลือมากกว่า 0" เฉย ๆ ไม่เคยเทียบกับขนาดที่กำลังจะเขียน — บัญชีที่เหลือโควตา 1 MB
 * จึงสร้างไฟล์สำรองขนาด 40 GB ได้ · ส่วนตัวจัดการไฟล์ (อัปโหลด เขียนไฟล์ แตกไฟล์ บีบไฟล์)
 * ไม่มีด่านเลยสักตัว ทั้งที่เป็นทางที่เขียนข้อมูลเข้าเครื่องได้ตรงที่สุด
 *
 * ## ขอบเขตของสิ่งที่ด่านนี้รับประกัน
 *
 * นี่คือการบังคับใช้ระดับ**แอปพลิเคชัน** เหมือนที่ `QuotaChecker` อธิบายไว้ — มันกัน
 * การเขียนที่เดินผ่าน panel เท่านั้น · ไฟล์ที่โค้ด PHP ของลูกค้าเขียนเองไม่ผ่านที่นี่เลย
 * การกันจุดนั้นต้องใช้ project quota ของ filesystem ซึ่งยังไม่ได้ทำ (PLAN-V2 เฟส E2)
 *
 * และ `disk_used_mb` เป็นค่าที่วัดไว้**รอบก่อน** ({@see \Phpcp\Agent\Capability\DiskQuotaCheck})
 * ไม่ใช่ค่าสด — ด่านนี้จึงคลาดเคลื่อนได้เท่ากับข้อมูลที่เขียนไประหว่างสองรอบวัด
 * ซึ่งยอมรับได้สำหรับด่านที่ทำหน้าที่ "กันการเบียดพื้นที่กันจนเว็บคนอื่นล่ม"
 */
final class DiskQuota
{
    /** ขนาดที่ยังไม่รู้ล่วงหน้า — ตรวจได้แค่ว่าตอนนี้ยังไม่เต็ม */
    public const UNKNOWN = 0;

    private const MB = 1_048_576;

    /**
     * บัญชีนี้รับข้อมูลอีก `$bytes` ไบต์ไหวหรือไม่
     *
     * `$bytes = self::UNKNOWN` ใช้กับงานที่รู้ขนาดผลลัพธ์ไม่ได้ก่อนลงมือ (บีบอัดไฟล์,
     * แตกไฟล์ tar) — กรณีนั้นตรวจได้แค่ว่าโควตายังไม่เต็ม ซึ่งยังดีกว่าไม่ตรวจเลย
     *
     * @throws ValidationError
     */
    public static function assertFits(Db $db, int $userId, int $bytes = self::UNKNOWN): void
    {
        $row = $db->first(
            'SELECT username, role, disk_quota_mb, disk_used_mb FROM users WHERE id = :id',
            ['id' => $userId],
        );

        // บัญชีผู้ดูแลไม่ถูกจำกัดโควตา — กฎเดียวกับ QuotaChecker::checkOwnerCanCreate()
        if ($row === null || ($row['role'] ?? '') !== Permissions::WEBADMIN) {
            return;
        }

        $limit = (int) ($row['disk_quota_mb'] ?? Quota::UNLIMITED);

        if (Quota::isUnlimited($limit)) {
            return;
        }

        $used = (int) ($row['disk_used_mb'] ?? 0);
        $free = $limit - $used;
        $need = (int) ceil(max(0, $bytes) / self::MB);

        if ($free > 0 && $need <= $free) {
            return;
        }

        $username = (string) ($row['username'] ?? '');

        // ข้อความต้องบอก "เหลือเท่าไร" ไม่ใช่แค่ "เต็ม" — ลูกค้าต้องตัดสินใจได้ว่าจะลบ
        // ไฟล์เก่ากี่ไฟล์หรือขอขยายโควตาเท่าไร โดยไม่ต้องเดา
        throw new ValidationError($free > 0
            ? sprintf(
                'พื้นที่ของบัญชี %s ไม่พอ — ต้องการอีกไม่เกิน %s MB แต่เหลือ %s MB'
                . ' (ใช้ %s MB จาก %s MB) จึงต้องลบไฟล์เก่าหรือขยายโควตาก่อน',
                $username,
                number_format($need),
                number_format($free),
                number_format($used),
                number_format($limit),
            )
            : sprintf(
                'พื้นที่ของบัญชี %s เต็มแล้ว (ใช้ %s MB จาก %s MB) — ไฟล์สำรองและไฟล์ที่อัปโหลด'
                . ' นับในโควตาด้วย จึงต้องลบไฟล์เก่าหรือขยายโควตาก่อน',
                $username,
                number_format($used),
                number_format($limit),
            ));
    }
}
