<?php

declare (strict_types = 1);

namespace Phpcp\Support;

use Phpcp\Agent\Executor\Executor;
use Phpcp\Agent\ValidationError;

/**
 * ที่เดียวในระบบที่ตัดสินว่าเส้นทางไฟล์หนึ่ง ๆ ปลอดภัยหรือไม่
 *
 * การป้องกันทำสองชั้นและต้องผ่านทั้งคู่:
 *
 *  1. ชั้นรูปแบบ (clean/join) — ทำงานตอน validate() ของ capability ก่อนแตะดิสก์
 *     ปฏิเสธ `..`, null byte, อักขระควบคุม, เส้นทางสัมบูรณ์ และชื่อที่ยาวเกินขีดจำกัดของ filesystem
 *
 *  2. ชั้นความจริง (resolve) — ทำงานหลังลดสิทธิ์แล้ว เทียบเส้นทางหลังคลาย symlink
 *     กับบ้านของเว็บไซต์ ชั้นนี้จำเป็นเพราะชั้นแรกมองไม่เห็น symlink ที่ชี้ออกนอกบ้าน
 *
 * ชั้นเดียวไม่พอ: ชั้นแรกอย่างเดียวโดน symlink หลอกได้ ชั้นสองอย่างเดียวก็ยอมให้ชื่อพิสดาร
 * อย่าง newline หรือ null byte ไหลลงไปถึง shell/log ได้
 */
final class PathGuard
{
    /** ขีดจำกัดชื่อไฟล์ของ ext4/xfs คือ 255 ไบต์ ไม่ใช่ 255 ตัวอักษร */
    private const MAX_SEGMENT_BYTES = 255;

    /** ความลึกสูงสุดที่ยอมรับ — กัน path ที่ยาวจนชน PATH_MAX ของเคอร์เนล */
    private const MAX_DEPTH = 32;

    /**
     * ตรวจและทำให้เส้นทางสัมพัทธ์อยู่ในรูปมาตรฐาน — คืน '' เมื่อหมายถึงรากของเว็บไซต์
     *
     * รับได้ทั้ง '', '/', 'public', '/public/index.php' และคืนค่าเป็น 'public/index.php'
     *
     * @throws ValidationError เมื่อเส้นทางมีรูปแบบที่ไม่อนุญาต
     */
    public static function clean(string $path, string $label = 'เส้นทาง'): string
    {
        if (str_contains($path, "\0")) {
            throw new ValidationError("{$label} มีอักขระที่ไม่อนุญาต");
        }

        // ตัด / นำหน้าออกแล้วมองทุกอย่างเป็นเส้นทางสัมพัทธ์กับรากของเว็บไซต์เสมอ
        // ผู้ใช้เห็น '/public' บนหน้าจอ แต่ระบบไม่เคยตีความว่าเป็นรากของเครื่อง
        $path = trim($path);
        $path = str_replace('\\', '/', $path);
        $path = ltrim($path, '/');

        if ($path === '' || $path === '.') {
            return '';
        }

        $parts = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            // `..` ถูกปฏิเสธ ไม่ใช่ถูกยุบทิ้ง — การยุบทิ้งทำให้ '/a/../../b' กลายเป็น '/b'
            // ซึ่งซ่อนเจตนาของผู้เรียกและเคยเป็นบ่อเกิดช่องโหว่ traversal ในหลายระบบ
            if ($segment === '..') {
                throw new ValidationError("{$label} ต้องไม่มี ..");
            }

            self::assertSegment($segment, $label);
            $parts[] = $segment;
        }

        if (count($parts) > self::MAX_DEPTH) {
            throw new ValidationError("{$label} ซ้อนลึกเกินกำหนด");
        }

        return implode('/', $parts);
    }

    /**
     * ตรวจชื่อไฟล์หรือโฟลเดอร์เดี่ยว — ห้ามมี / อยู่ข้างใน
     *
     * @throws ValidationError
     */
    public static function name(string $name, string $label = 'ชื่อ'): string
    {
        $name = trim($name);

        if ($name === '') {
            throw new ValidationError("ต้องระบุ{$label}");
        }
        if (str_contains($name, '/') || str_contains($name, '\\')) {
            throw new ValidationError("{$label}ต้องไม่มีเครื่องหมาย /");
        }
        if ($name === '.' || $name === '..') {
            throw new ValidationError("{$label}นี้ใช้ไม่ได้");
        }

        self::assertSegment($name, $label);

        return $name;
    }

    /** ต่อเส้นทางสัมพัทธ์ที่ผ่าน clean() แล้วเข้ากับราก */
    public static function join(string $root, string $relative): string
    {
        return $relative === '' ? $root : rtrim($root, '/').'/'.$relative;
    }

    /**
     * เส้นทางแม่ของเส้นทางสัมพัทธ์ — คืน '' เมื่ออยู่ชั้นบนสุดแล้ว
     */
    public static function parent(string $relative): string
    {
        $at = strrpos($relative, '/');

        return $at === false ? '' : substr($relative, 0, $at);
    }

    /**
     * ยืนยันว่าเส้นทางจริงหลังคลาย symlink ยังอยู่ใต้รากที่อนุญาต
     *
     * ต้องเรียกหลังลดสิทธิ์แล้วเท่านั้น เพราะ realpath() จะเห็นเฉพาะสิ่งที่ผู้ใช้นั้นเข้าถึงได้
     *
     * @param bool $mustExist false เมื่อเป็นปลายทางที่กำลังจะถูกสร้าง — จะตรวจไดเรกทอรีแม่แทน
     * @throws ValidationError เมื่อเส้นทางหลุดออกนอกราก หรือไม่มีอยู่ทั้งที่ต้องมี
     */
    public static function resolve(Executor $executor, string $root, string $target, bool $mustExist = true): string
    {
        $realRoot = $executor->realPath($root);
        if ($realRoot === null) {
            throw new ValidationError('ไม่พบไดเรกทอรีของเว็บไซต์');
        }

        $real = $executor->realPath($target);

        if ($real === null) {
            if ($mustExist) {
                throw new ValidationError('ไม่พบไฟล์หรือโฟลเดอร์ที่ระบุ');
            }

            // ปลายทางยังไม่มีจริง จึงตรวจไดเรกทอรีแม่ซึ่งต้องมีอยู่แล้วแทน
            // แล้วประกอบเส้นทางกลับจากแม่ที่ตรวจแล้ว — ชื่อสุดท้ายผ่าน clean() มาก่อนหน้านี้แล้ว
            $parentReal = $executor->realPath(dirname($target));
            if ($parentReal === null) {
                throw new ValidationError('ไม่พบโฟลเดอร์ปลายทาง');
            }

            self::assertInside($realRoot, $parentReal);

            return $parentReal.'/'.basename($target);
        }

        self::assertInside($realRoot, $real);

        return $real;
    }

    /** ตรวจว่าเส้นทางที่คลาย symlink แล้วอยู่ใต้รากจริง */
    private static function assertInside(string $realRoot, string $real): void
    {
        $realRoot = rtrim($realRoot, '/');

        // ต้องเทียบกับ "$realRoot/" ไม่ใช่ "$realRoot" เปล่า ๆ
        // ไม่อย่างนั้น /srv/sites/example.com-evil จะผ่านการตรวจของ /srv/sites/example.com
        if ($real !== $realRoot && !str_starts_with($real, $realRoot.'/')) {
            throw new ValidationError('เส้นทางอยู่นอกขอบเขตของเว็บไซต์');
        }
    }

    /** @throws ValidationError */
    private static function assertSegment(string $segment, string $label): void
    {
        if (strlen($segment) > self::MAX_SEGMENT_BYTES) {
            throw new ValidationError("{$label}ยาวเกิน ".self::MAX_SEGMENT_BYTES.' ไบต์');
        }

        // อักขระควบคุมทำให้ชื่อไฟล์ปลอมตัวใน log และ terminal ได้ จึงห้ามทั้งช่วง
        if (preg_match('/[\x00-\x1f\x7f]/', $segment) === 1) {
            throw new ValidationError("{$label}มีอักขระควบคุมที่ไม่อนุญาต");
        }

        if (!mb_check_encoding($segment, 'UTF-8')) {
            throw new ValidationError("{$label}ไม่ใช่ข้อความ UTF-8 ที่ถูกต้อง");
        }
    }
}
