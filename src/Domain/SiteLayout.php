<?php

declare(strict_types=1);

namespace Phpcp\Domain;

/**
 * รูปทรงของไฟล์ใต้บ้านผู้ใช้ — **ที่เดียวในระบบที่ตอบว่าอะไรอยู่ตรงไหน**
 *
 * ก่อนหน้านี้คำตอบกระจายอยู่ใน `UserAccount` กับ `Site` เป็นสูตรตายตัวสูตรเดียว
 * การเพิ่มทางเลือกจึงต้องแก้ทั้งสองที่ให้ตรงกันเป๊ะ ซึ่งเป็นเงื่อนไขที่ผิดพลาดง่าย
 * และผิดแล้วไม่มีอะไรฟ้อง — vhost ชี้ไปที่หนึ่ง โควตานับอีกที่หนึ่ง
 *
 * ## สองเลย์เอาต์
 *
 * **phpcp** (เดิม · ค่าเริ่มต้น) — ทุกอย่างของเว็บหนึ่งแห่งอยู่ในกล่องเดียวกัน
 * ```
 * <home>/domains/<domain>/public     ไฟล์เว็บ
 * <home>/domains/<domain>/logs       log ของเว็บนี้
 * <home>/domains/<domain>/backups
 * ```
 *
 * **cpanel** — รูปที่ผู้ย้ายมาจาก cPanel/DirectAdmin คุ้นเคย
 * ```
 * <home>/public_html                 ไฟล์เว็บของโดเมนหลัก
 * <home>/<domain>                    ไฟล์เว็บของโดเมนที่เพิ่มทีหลัง
 * <home>/logs/<domain>               log แยกตามโดเมน
 * <home>/backups/<domain>
 * ```
 *
 * ## ทำไม cpanel ต้องรู้ว่าโดเมนไหนคือโดเมนหลัก
 *
 * `public_html` มีได้อันเดียวต่อบัญชี แต่บัญชีหนึ่งมีได้หลายเว็บ · คำถาม "อันไหน
 * ได้ public_html" ตอบจากข้อมูลที่มีอยู่ไม่ได้ — ลำดับ id ไม่ใช่คำสัญญา และการ
 * เดาจากลำดับแปลว่าเส้นทางของเว็บที่ให้บริการอยู่จะเปลี่ยนเองเมื่อมีคนลบเว็บอื่นทิ้ง
 * · จึงเก็บไว้ตรง ๆ ที่ `users.main_domain` (ดู migration 0020)
 *
 * ## สิ่งที่ทั้งสองเลย์เอาต์เหมือนกัน
 *
 * `tmp` อยู่ระดับผู้ใช้เสมอ ไม่ใช่ระดับเว็บ — FPM pool หนึ่งตัวรับหลายเว็บของเจ้าของ
 * คนเดียวกัน การแยก tmp รายเว็บจะได้ไดเรกทอรีที่ pool เขียนลงไม่ได้จริง
 */
enum SiteLayout: string
{
    /** เดิม — `<home>/domains/<domain>/public` */
    case Phpcp = 'phpcp';

    /** แบบ cPanel — `<home>/public_html` สำหรับโดเมนหลัก */
    case Cpanel = 'cpanel';

    /**
     * ค่าที่ใช้เมื่อไม่มีใครเลือกอะไรเลย
     *
     * **เป็น cpanel โดยตั้งใจ** — `<บ้าน>/public_html` คือรูปที่ผู้ดูแลระบบยูนิกซ์และ
     * ลูกค้าโฮสติ้งทุกคนคาดหวัง · `phpcp` ที่ซ้อนลึกสามชั้น (`domains/<โดเมน>/public`)
     * ไม่ตรงกับความคาดหวังของใครเลย และทำให้สคริปต์ deploy ของลูกค้าที่ย้ายมาต้องแก้ทุกตัว
     *
     * ของเดิมยังเลือกได้รายบัญชีสำหรับเครื่องที่ย้ายมาแล้วยังไม่พร้อมขยับไฟล์
     */
    public const DEFAULT = self::Cpanel;

    /**
     * ค่าเริ่มต้นของเครื่องนี้ สำหรับบัญชีที่ยังไม่เคยเลือกเอง
     *
     * ค่าจริงเก็บที่ {@see \Phpcp\Kernel\Paths} ด้วยเหตุผลเดียวกับ `usersDir` — `UserAccount`
     * ถูกสร้างจากหลายสิบจุดทั่วระบบในฐานะ value object การส่ง Config ตามไปทุกจุดจะเปลี่ยน
     * ลายเซ็นทั้งหมดโดยไม่ได้อะไรเพิ่ม · (enum ของ PHP ถือ property เองไม่ได้)
     */
    public static function systemDefault(): self
    {
        return self::tryFrom(\Phpcp\Kernel\Paths::siteLayout()) ?? self::DEFAULT;
    }

    /**
     * แปลงค่าจากฐานข้อมูล/ค่าตั้ง โดยไม่ล้มเมื่อเจอค่าที่ไม่รู้จัก
     *
     * ค่าว่าง = "ตามค่าเริ่มต้นของระบบ" ซึ่งเป็นความหมายที่ต่างจาก 'phpcp' อย่างมี
     * นัยสำคัญ: บัญชีที่ยังไม่เคยเลือกจะขยับตามเมื่อผู้ดูแลเปลี่ยนค่าเริ่มต้นของระบบ
     * ส่วนบัญชีที่เลือก 'phpcp' ไว้ชัดเจนจะไม่ขยับ · ผู้เรียกที่ต้องแยกสองกรณีนี้
     * ต้องดูค่าดิบเอง ไม่ใช่เรียกที่นี่
     */
    public static function parse(string $value, ?self $fallback = null): self
    {
        return self::tryFrom(trim($value)) ?? $fallback ?? self::DEFAULT;
    }

    /** ชื่อที่แสดงบนหน้าจอ */
    public function label(): string
    {
        return match ($this) {
            self::Phpcp => 'phpcp — <บ้าน>/domains/<โดเมน>/public',
            self::Cpanel => 'cPanel — <บ้าน>/public_html',
        };
    }

    /**
     * ไดเรกทอรีที่เว็บเซิร์ฟเวอร์เสิร์ฟ
     *
     * `$isMain` มาจากการเทียบโดเมนกับ `users.main_domain` ไม่ใช่จากลำดับหรือการเดา
     */
    public function docroot(string $home, string $domain, bool $isMain): string
    {
        return match ($this) {
            self::Phpcp => $home . '/domains/' . $domain . '/public',
            self::Cpanel => $isMain ? $home . '/public_html' : $home . '/' . $domain,
        };
    }

    /**
     * ที่เก็บของประจำเว็บที่**ไม่ใช่ไฟล์เว็บ** — log, backup, หน้าระงับบริการ
     *
     * แยกจาก docroot โดยเจตนา · ในเลย์เอาต์ cpanel ไฟล์เว็บอยู่ที่ `public_html`
     * ซึ่งเป็นไดเรกทอรีที่ลูกค้าอัปโหลดไฟล์เข้าไปทั้งก้อน — วาง log ไว้ในนั้นแปลว่า
     * log หลุดออกเว็บได้ทันทีถ้าไม่มีกฎกัน และลูกค้าลบทิ้งเองได้ด้วยความไม่ตั้งใจ
     */
    public function stateDir(string $home, string $domain): string
    {
        return match ($this) {
            self::Phpcp => $home . '/domains/' . $domain,
            // จุดนำหน้าเพื่อไม่ให้ชนกับชื่อโดเมนที่ลูกค้าเพิ่มเองใต้บ้านเดียวกัน
            self::Cpanel => $home . '/.phpcp/' . $domain,
        };
    }

    /** log ของเว็บหนึ่งแห่ง */
    public function logDir(string $home, string $domain): string
    {
        return match ($this) {
            self::Phpcp => $this->stateDir($home, $domain) . '/logs',
            // cPanel วาง log ไว้รวมกันใต้ `logs/` ซึ่งเป็นที่ที่ผู้ย้ายมาจะไปหาก่อน
            self::Cpanel => $home . '/logs/' . $domain,
        };
    }

    /** ที่เก็บสำเนาสำรองของเว็บหนึ่งแห่ง */
    public function backupDir(string $home, string $domain): string
    {
        return match ($this) {
            self::Phpcp => $this->stateDir($home, $domain) . '/backups',
            self::Cpanel => $home . '/backups/' . $domain,
        };
    }

    /**
     * ไดเรกทอรีทั้งหมดที่ต้องมีอยู่จริงก่อนเว็บทำงานได้ พร้อมสิทธิ์ที่ต้องการ
     *
     * รวมไว้ที่นี่เพราะผู้สร้างบัญชีกับผู้สร้างเว็บเป็นคนละที่ และเคยสร้างไม่ครบกัน
     * มาแล้ว — เลย์เอาต์เป็นคนเดียวที่รู้ว่ารูปทรงของตัวเองต้องมีอะไรบ้าง
     *
     * @return array<string,int> path => mode
     */
    public function requiredDirectories(string $home, string $domain, bool $isMain): array
    {
        return [
            $this->docroot($home, $domain, $isMain) => 0750,
            $this->logDir($home, $domain) => 0750,
            $this->backupDir($home, $domain) => 0750,
            $this->stateDir($home, $domain) => 0750,
        ];
    }
}
