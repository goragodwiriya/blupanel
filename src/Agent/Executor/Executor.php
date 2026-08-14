<?php

declare (strict_types = 1);

namespace Phpcp\Agent\Executor;

use Phpcp\Kernel\Mode;

/**
 * จุดเดียวในระบบที่โค้ดฝั่ง agent ใช้แตะโลกภายนอก
 *
 * เหตุผลที่ต้องเป็น interface ตั้งแต่บรรทัดแรกของโปรเจกต์ (ARCHITECTURE §6.2):
 * ถ้าปล่อยให้ Capability เรียก proc_open หรือ file_put_contents เองกระจัดกระจาย
 * จะทำโหมด sandbox/dryrun ไม่ได้อีกเลย และจะไม่มีจุดกลางสำหรับบังคับกฎความปลอดภัย
 *
 * ห้ามเพิ่มเมธอดที่รับ "คำสั่งเป็นสตริง" เข้ามาใน interface นี้เด็ดขาด
 */
interface Executor
{
    /**
     * เพดานที่ `exec()` เก็บจากแต่ละ stream — **เกินกว่านี้ถูกตัดทิ้งเงียบ ๆ**
     *
     * ประกาศไว้ในสัญญา ไม่ใช่ซ่อนไว้ใน RealExecutor เพราะเป็นข้อเท็จจริงที่ผู้เรียก
     * ต้องรู้ ไม่ใช่รายละเอียดการทำงานภายใน · ด่านความปลอดภัยที่อ่านผลลัพธ์ของคำสั่ง
     * แล้วตัดสินใจ (เช่นตรวจรายชื่อใน archive ก่อนแตกไฟล์) จะตรวจแค่ส่วนหัวแล้วปล่อย
     * ส่วนที่เหลือผ่านไปโดยไม่มีอะไรฟ้อง ถ้าไม่รู้ว่ามีเพดานนี้อยู่ — ซึ่งแปลว่า
     * archive ที่ตั้งใจร้ายเพียงแค่ต้องยาวพอก็เดินผ่านด่านได้
     *
     * ผู้เรียกที่ต้องการผลลัพธ์**ครบ** ต้องให้คำสั่งเขียนลงไฟล์แทน (เช่น
     * `tar --index-file`) หรือปฏิเสธเมื่อผลลัพธ์ยาวชนเพดานนี้
     */
    public const MAX_OUTPUT_BYTES = 1_048_576;

    public function mode(): Mode;

    /**
     * รันคำสั่ง — argv เป็น array เสมอ ไม่มีการผ่าน shell
     *
     * ผลลัพธ์ของแต่ละ stream ถูกตัดที่ {@see self::MAX_OUTPUT_BYTES}
     *
     * @param list<string> $argv  argv[0] ต้องเป็น absolute path ของ binary
     * @param string|null  $stdin ข้อมูลที่จะป้อนเข้า stdin (null = ปิด stdin ทันที)
     */
    public function exec(
        array $argv,
        int $timeout = 30,
        ?string $cwd = null,
        ?string $stdin = null,
    ): ExecResult;

    /**
     * แปลง absolute path ของระบบจริงเป็น path ที่ควรใช้ในโหมดปัจจุบัน
     *
     * production/dryrun คืนค่าเดิม · sandbox เติม prefix ให้
     * Capability ต้องเรียกเมธอดนี้กับทุก path ที่จะเอาไปใช้ — นี่คือจุดเดียวที่ทำการแมป
     */
    public function path(string $absolutePath): string;

    /**
     * @param string $path
     */
    public function readFile(string $path): string;

    public function writeFile(string $path, string $content, int $mode = 0644): void;

    public function exists(string $path): bool;

    /** สร้างไดเรกทอรี (รวมไดเรกทอรีแม่) — ไม่ล้มถ้ามีอยู่แล้ว */
    public function makeDirectory(string $path, int $mode = 0755): void;

    /**
     * พื้นที่ดิสก์ของ filesystem ที่ path นั้นอยู่
     *
     * @return array{total:int,free:int}
     */
    public function diskSpace(string $path): array;

    /**
     * เส้นทางจริงหลังคลาย symlink และ `..` ทั้งหมด — null เมื่อไม่มีอยู่จริง
     *
     * ตัวจัดการไฟล์ต้องเรียกเมธอดนี้ก่อนตัดสินใจว่า path อยู่ในบ้านของเว็บไซต์หรือไม่
     * เพราะการเทียบสตริงก่อนคลาย symlink หลอกได้ด้วยลิงก์ที่ชี้ออกนอกบ้าน (SECURITY §2.7)
     */
    public function realPath(string $path): ?string;

    /**
     * รายการในไดเรกทอรีหนึ่งชั้น (ไม่ลงลึก) พร้อมข้อมูลจาก lstat
     *
     * ใช้ lstat ไม่ใช่ stat — symlink จึงถูกรายงานตามตัวมันเอง ไม่ใช่ตามปลายทาง
     *
     * @return list<array{name:string,type:string,size:int,mode:int,mtime:int,uid:int,gid:int,link:?string}>
     */
    public function listDirectory(string $path): array;

    /** @return array{type:string,size:int,mode:int,mtime:int,uid:int,gid:int,link:?string}|null */
    public function stat(string $path): ?array;

    public function rename(string $from, string $to): void;

    /** คัดลอกไฟล์หรือทั้งไดเรกทอรี */
    public function copyPath(string $from, string $to): void;

    /** ลบไฟล์หรือทั้งไดเรกทอรี — ไม่เดินตาม symlink */
    public function removePath(string $path): void;

    public function changeMode(string $path, int $mode): void;

    /**
     * บีบอัดรายการที่ระบุเป็นไฟล์ zip
     *
     * ชื่อภายในไฟล์บีบอัดอ้างอิงจาก $base — แตกไฟล์ออกมาจึงได้โครงสร้าง
     * เหมือนที่เห็นบนหน้าจอ ไม่มีเส้นทางเต็มของเครื่องติดไปด้วย
     *
     * @param list<string> $sources
     * @return array{entries:int,bytes:int}
     */
    public function zip(array $sources, string $base, string $archive): array;

    /**
     * แตกไฟล์ zip ลงไดเรกทอรีปลายทาง — ต้องกัน Zip Slip ที่ระดับนี้
     *
     * @return array{entries:int,bytes:int,skipped:int}
     */
    public function unzip(string $archive, string $destination): array;

    /**
     * ทำงานกับไฟล์ในสิทธิ์ของผู้ใช้ระบบที่กำหนด — ARCHITECTURE §4.4
     *
     * root ต้องไม่แตะไฟล์ของเว็บไซต์โดยตรง ทุกงานไฟล์จึง fork แล้วลดสิทธิ์ก่อนเสมอ
     * บั๊กใน path handling ที่แย่ที่สุดจึงหลุดได้แค่ในขอบเขตของเว็บไซต์นั้นเอง
     *
     * @param callable():array<string,mixed> $work ต้องคืนค่าที่ json_encode ได้
     * @return array<string,mixed>
     */
    public function asUser(?string $systemUser, callable $work): array;

    /** true = การเปลี่ยนแปลงถูกจำลอง ไม่ได้เกิดกับระบบจริง */
    public function isSimulated(): bool;

    /**
     * บันทึกคำสั่งที่ถูกจำลอง เอาไว้แสดงในโหมด dryrun
     *
     * @return list<string>
     */
    public function simulatedCommands(): array;
}
