<?php

declare(strict_types=1);

namespace Phpcp\Domain;

use Phpcp\Agent\Executor\Executor;
use Phpcp\Agent\ValidationError;

/**
 * รายการไฟล์สำรองของบัญชีหนึ่ง — **อ่านจากโฟลเดอร์จริง ไม่ใช่จากตาราง**
 *
 * ## ทำไมตัวไฟล์ถึงเป็นแหล่งความจริง
 *
 * โฟลเดอร์ `<บ้าน>/backup` เปิดให้ลูกค้าเข้าถึงผ่าน SFTP และตัวจัดการไฟล์ **โดยตั้งใจ**
 * — เขาดาวน์โหลดสำเนาของตัวเองได้ และลบทิ้งเองได้ · แถวในตารางที่บันทึกไว้ตอนสร้าง
 * จึงเพี้ยนทันทีที่เขาลบไฟล์: หน้าจอยังโชว์รายการ ปุ่มกู้คืนยังกดได้ แล้วล้มตอนที่
 * ผู้ใช้ต้องการมันที่สุด · ตรงข้ามกัน ไฟล์ที่เขาคัดลอกกลับเข้ามาเองจะไม่มีวันโผล่ในรายการ
 *
 * `backup.json` ที่ฝังอยู่ในไฟล์อยู่แล้วทำให้ไฟล์อธิบายตัวเองได้ครบ (โดเมน · ผู้ใช้ระบบ ·
 * เลย์เอาต์ · เครื่องต้นทาง) จึงไม่ต้องมีแถวคู่ขนานอีก — ดู PLAN-BACKUP-V2 ข้อ B4
 *
 * ## สิ่งที่คลาสนี้ตอบและไม่ตอบ
 *
 * ตอบจาก**ชื่อไฟล์กับ stat เท่านั้น** ไม่แตะข้างในไฟล์ · การอ่าน `backup.json` ต้องเรียก
 * tar หนึ่งครั้งต่อไฟล์ ซึ่งบัญชีที่สำรองทุกคืนมาหนึ่งปีแปลว่า 700 กระบวนการต่อการเปิด
 * หน้าจอหนึ่งครั้ง · ผู้ที่ต้องรู้ที่มาแน่ ๆ (ตอนกู้คืน) อ่านใบแจ้งข้อมูลเองตอนนั้น
 */
final class BackupFiles
{
    /** ไฟล์เว็บ — ต่อท้ายด้วยนามสกุลนี้เสมอ */
    public const SITE_SUFFIX = '.tar.gz';

    /** ฐานข้อมูล — `.sql.gz` ตั้งแต่ PLAN-BACKUP-V2 ข้อ B9 */
    public const DB_SUFFIX = '.sql.gz';

    /**
     * เพดานจำนวนไฟล์ต่อหนึ่งบัญชี
     *
     * โฟลเดอร์นี้เป็นของลูกค้า เขาวางอะไรไว้ในนั้นกี่ไฟล์ก็ได้ · การอ่านทั้งหมดแล้วส่ง
     * ออกจาก agent ทีเดียวจะชนขนาด frame ของโปรโตคอลเหมือนที่ `FileList` เคยเจอ
     */
    public const MAX_FILES = 500;

    /**
     * ไฟล์สำรองทั้งหมดในโฟลเดอร์ของบัญชีนี้ เรียงใหม่สุดขึ้นก่อน
     *
     * `$domains` คือโดเมนของบัญชีนี้ ใช้จับว่าไฟล์เป็นของเว็บไหนจากชื่อไฟล์ — ไฟล์ที่
     * จับคู่ไม่ได้ (ลูกค้าคัดลอกมาเอง หรือมาจากเว็บที่ลบไปแล้ว) ยังแสดงในรายการเสมอ
     * เพราะมันกินโควตาของเขาจริง · แต่จะกู้คืนอัตโนมัติไม่ได้ ต้องบอกให้เห็นชัด
     *
     * @param  list<string> $domains
     * @return list<array{name:string,path:string,type:string,domain:string,bytes:int,modified_at:int,restorable:bool}>
     */
    public static function listFor(Executor $executor, UserAccount $owner, array $domains): array
    {
        $dir = $owner->backupDir();
        $resolved = $executor->path($dir);

        // ยังไม่เคยสำรอง = ยังไม่มีโฟลเดอร์ · ไม่ใช่ข้อผิดพลาด
        if (!$executor->exists($resolved)) {
            return [];
        }

        $files = [];

        foreach ($executor->listDirectory($resolved) as $entry) {
            $name = (string) $entry['name'];

            if (($entry['type'] ?? '') !== 'file' || self::typeOf($name) === null) {
                continue;
            }

            $files[] = [
                'name' => $name,
                'path' => $dir . '/' . $name,
                'type' => (string) self::typeOf($name),
                'domain' => self::domainOf($name, $domains),
                'bytes' => (int) ($entry['size'] ?? 0),
                // เวลาจากไฟล์เอง ไม่ใช่จากชื่อไฟล์ — ไฟล์ที่ถูกคัดลอกกลับเข้ามาหรือ
                // ถูกเปลี่ยนชื่อยังตอบได้ว่ามีมาตั้งแต่เมื่อไร
                'modified_at' => (int) ($entry['mtime'] ?? 0),
                'restorable' => self::typeOf($name) === 'site' && self::domainOf($name, $domains) !== '',
            ];
        }

        usort($files, static fn (array $a, array $b): int => $b['modified_at'] <=> $a['modified_at']);

        return array_slice($files, 0, self::MAX_FILES);
    }

    /**
     * ชนิดของไฟล์จากนามสกุล — null = ไม่ใช่ไฟล์สำรอง
     *
     * ตัดสินจากนามสกุลอย่างเดียวโดยตั้งใจ · ชื่อที่เหลือเป็นของลูกค้า เขาเปลี่ยนชื่อ
     * ไฟล์สำรองของตัวเองได้และมันก็ยังเป็นไฟล์สำรองอยู่
     */
    public static function typeOf(string $name): ?string
    {
        if (str_ends_with($name, self::DB_SUFFIX)) {
            return 'database';
        }

        return str_ends_with($name, self::SITE_SUFFIX) ? 'site' : null;
    }

    /**
     * โดเมนที่ชื่อไฟล์นี้อ้างถึง — ค่าว่างแปลว่าจับคู่ไม่ได้
     *
     * เทียบกับรายชื่อโดเมนจริงของบัญชี แทนการแยกส่วนชื่อไฟล์ด้วยขีดกลาง เพราะชื่อโดเมน
     * มีขีดกลางได้เอง (`my-shop.example.com`) และมีคำว่า `files` หรือ `db` ในตัวได้ด้วย
     * — กฎที่แยกจากรูปแบบชื่อล้วนจะตัดผิดที่ในกรณีพวกนั้นโดยไม่มีอะไรฟ้อง
     *
     * เลือกโดเมนที่**ยาวที่สุด**ที่เข้าเงื่อนไข: `shop.example.com` กับ `example.com`
     * อยู่บัญชีเดียวกันได้ และชื่อไฟล์ของอันแรกขึ้นต้นด้วยอันหลังไม่ได้ แต่กลับกันได้
     *
     * @param list<string> $domains
     */
    public static function domainOf(string $name, array $domains): string
    {
        $best = '';

        foreach ($domains as $domain) {
            if ($domain !== '' && str_starts_with($name, $domain . '-') && strlen($domain) > strlen($best)) {
                $best = $domain;
            }
        }

        return $best;
    }

    /**
     * ชื่อไฟล์ที่รับจากผู้เรียกได้ — **ชื่อล้วนเท่านั้น**
     *
     * ค่านี้ถูกต่อเข้ากับเส้นทางบ้านของลูกค้าแล้วเอาไปลบหรือแตกไฟล์ทับเว็บ · ยอมให้มี
     * `/` หรือ `..` แม้แต่ตัวเดียวแปลว่าผู้เรียกเลือกได้ว่าจะให้ระบบไปหยิบไฟล์ไหนบน
     * เครื่อง แล้วสั่งลบมันด้วยสิทธิ์ root
     *
     * @throws ValidationError
     */
    public static function assertName(string $name): string
    {
        $name = trim($name);

        if ($name === '' || $name !== basename($name) || str_contains($name, '..') || str_contains($name, "\0")) {
            throw new ValidationError('ชื่อไฟล์สำรองต้องเป็นชื่อล้วน ไม่มีเส้นทางนำหน้า');
        }

        if (self::typeOf($name) === null) {
            throw new ValidationError(
                'ชื่อไฟล์สำรองต้องลงท้ายด้วย ' . self::SITE_SUFFIX . ' หรือ ' . self::DB_SUFFIX,
            );
        }

        return $name;
    }

    /**
     * เส้นทางเต็มของไฟล์ในโฟลเดอร์ของบัญชีนี้ — ผ่าน {@see assertName()} แล้วเท่านั้น
     *
     * @throws ValidationError
     */
    public static function resolve(UserAccount $owner, string $name): string
    {
        return $owner->backupDir() . '/' . self::assertName($name);
    }
}
