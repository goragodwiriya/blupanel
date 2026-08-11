<?php

declare(strict_types=1);

namespace Phpcp\Domain;

/**
 * กฎของค่าโควตา — แหล่งความจริงเดียวว่าชนิดทรัพยากรมีอะไรบ้างและตัวเลขแปลว่าอะไร
 *
 * ก่อนหน้านี้กฎเดียวกันถูกเขียนไว้สามที่ (CustomerRepository::assertCanCreateResource ที่ถูกลบไปแล้ว,
 * QuotaChecker::canCreate, และ switch ใน capability) ซึ่งเป็นต้นเหตุของบั๊กที่ทำให้
 * ระบบโควตาใช้ไม่ได้ทั้งระบบ: ผู้เรียกพูดเป็นเอกพจน์ ("จะสร้าง 1 domain") แต่ตาราง
 * ใช้ชื่อพหูพจน์ตามคอลัมน์ (quota_domains) พอจับคู่ไม่ติดก็อ่านค่าได้ 0 แล้วแปลว่า
 * "โควตาถูกปิด" — ลูกค้าที่มีโควตา 10 โดเมนจึงสร้างเว็บไม่ได้แม้แต่เว็บเดียว
 *
 * ที่นี่จึงรับทั้งสองรูปแบบและแปลงให้เป็นชื่อเดียวก่อนเสมอ
 */
final class Quota
{
    /** ไม่จำกัดจำนวน */
    public const UNLIMITED = -1;

    /** ปิดการใช้งานทรัพยากรชนิดนี้ */
    public const DISABLED = 0;

    /**
     * ชนิดทรัพยากรทั้งหมด → [คอลัมน์, ชื่อภาษาไทย, ห้ามตั้งเป็น 0, เป็นสวิตช์เปิด/ปิด]
     *
     * โดเมนห้ามเป็น 0 เพราะบัญชีโฮสติ้งที่สร้างเว็บไม่ได้เลยคือบัญชีที่ไม่มีประโยชน์ —
     * ถ้าอยากปิดบริการให้ใช้ service_status = suspended ซึ่งสื่อความหมายตรงกว่า
     *
     * **ช่องที่สี่ (`toggle`) คือชนิดที่ตัวเลขไม่มีความหมาย มีแค่ "ได้/ไม่ได้"**
     * ตั้งแต่เฟส E4 หนึ่งบัญชีโฮสติ้งมี SFTP ได้เพียงหนึ่งบัญชีเสมอ (หน่วยของการแยกสิทธิ์
     * คือผู้ใช้ ไม่ใช่เว็บ ตั้งแต่ migration 0006) `quota_ftp_users` จึงเป็นสวิตช์:
     * `0` = แพ็กเกจไม่รวม · `-1`/`>0` = เปิดได้หนึ่งบัญชี
     *
     * ประกาศไว้ที่นี่ที่เดียวเพื่อให้หน้าจอ ฟอร์ม และตัวตรวจค่าอ่านกฎเดียวกัน — ก่อนหน้านี้
     * ความหมายถูกเปลี่ยนในเฟส E4 แต่หน้าจอยังแสดงเป็นจำนวนอยู่ กลายเป็นตัวเลขที่สื่อผิด
     * และแก้จากหน้าเว็บไม่ได้เลย
     *
     * @var array<string,array{0:string,1:string,2:bool,3:bool}>
     */
    public const TYPES = [
        'domains'    => ['quota_domains',    'โดเมน',           true,  false],
        'subdomains' => ['quota_subdomains', 'โดเมนย่อย',       false, false],
        'aliases'    => ['quota_aliases',    'โดเมนสำรอง',      false, false],
        'emails'     => ['quota_emails',     'อีเมล',           false, false],
        'databases'  => ['quota_databases',  'ฐานข้อมูล',       false, false],
        'ftp_users'  => ['quota_ftp_users',  'SFTP',            false, true],
    ];

    /** ชนิดนี้เป็นสวิตช์เปิด/ปิด ไม่ใช่จำนวนหรือไม่ */
    public static function isToggle(string $type): bool
    {
        return self::TYPES[self::assertType($type)][3] ?? false;
    }

    /**
     * ชื่อเอกพจน์ที่ผู้เรียกใช้กันอยู่ → ชื่อจริงของชนิดทรัพยากร
     *
     * @var array<string,string>
     */
    private const ALIASES = [
        'domain'    => 'domains',
        'subdomain' => 'subdomains',
        'alias'     => 'aliases',
        // wildcard นับรวมกับโดเมนสำรอง — ไม่มีโควตาของตัวเองเพราะเว็บหนึ่งใส่ได้
        // อย่างมากหนึ่งรายการที่มีความหมาย (`*.example.com`) การแยกช่องนับจึงไม่ให้
        // อะไรเพิ่ม นอกจากคอลัมน์ที่ผู้ดูแลต้องตั้งค่าอีกช่องโดยไม่รู้ว่าต่างจากเดิมยังไง
        'wildcard'  => 'aliases',
        'email'     => 'emails',
        'database'  => 'databases',
        'ftp_user'  => 'ftp_users',
    ];

    /** คืนชื่อชนิดมาตรฐาน หรือ null ถ้าไม่รู้จัก */
    public static function normalize(string $type): ?string
    {
        $name = self::ALIASES[$type] ?? $type;

        return isset(self::TYPES[$name]) ? $name : null;
    }

    /** ชื่อชนิดมาตรฐาน — โยนข้อผิดพลาดถ้าไม่รู้จัก */
    public static function assertType(string $type): string
    {
        $name = self::normalize($type);

        if ($name === null) {
            throw new \InvalidArgumentException("ไม่รู้จักทรัพยากรชนิด: {$type}");
        }

        return $name;
    }

    /** ชื่อคอลัมน์ในตาราง users */
    public static function column(string $type): string
    {
        return self::TYPES[self::assertType($type)][0];
    }

    /** ชื่อภาษาไทยสำหรับแสดงผลและข้อความผิดพลาด */
    public static function label(string $type): string
    {
        return self::TYPES[self::assertType($type)][1];
    }

    /**
     * ตรวจว่าค่าโควตาที่จะบันทึกใช้ได้จริง
     *
     * @throws \InvalidArgumentException
     */
    public static function assertValue(int $value, string $type): void
    {
        $name = self::assertType($type);
        [, $label, $forbidsZero] = self::TYPES[$name];

        if ($value < self::UNLIMITED) {
            throw new \InvalidArgumentException(
                "โควตา{$label}ต้องเป็น -1 (ไม่จำกัด), 0 (ปิด) หรือจำนวนบวก",
            );
        }

        if ($forbidsZero && $value === self::DISABLED) {
            throw new \InvalidArgumentException(
                "โควตา{$label}ต้องไม่เป็น 0 — ขั้นต่ำ 1 หรือ -1 (ไม่จำกัด)",
            );
        }
    }

    public static function isUnlimited(int $quota): bool
    {
        return $quota === self::UNLIMITED;
    }

    public static function isDisabled(int $quota): bool
    {
        return $quota === self::DISABLED;
    }

    /** แปลงค่าโควตาเป็นข้อความสำหรับแสดงผล */
    public static function format(int $quota): string
    {
        return match ($quota) {
            self::UNLIMITED => 'ไม่จำกัด',
            self::DISABLED => 'ปิด',
            default => (string) $quota,
        };
    }
}
