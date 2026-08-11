<?php

declare(strict_types=1);

namespace Phpcp\Driver\WebServer;

use Phpcp\Agent\Executor\Executor;

/**
 * ตัดสินว่าเว็บนี้ให้ nginx ตอบไฟล์ static เองได้แค่ไหน โดยไม่ทำให้กฎใน `.htaccess` หาย
 *
 * **ปัญหาที่ต้องแก้:** ให้ nginx ตอบไฟล์เองคือเหตุผลหลักที่เอา nginx มาวางหน้า Apache
 * แต่ `.htaccess` คุมไฟล์ static ได้ด้วย (`Require all denied`, `AuthType Basic`,
 * `<FilesMatch>` ปฏิเสธ, `Header set`) ถ้า nginx ตอบไปเลยกฎเหล่านั้นจะถูกข้ามเงียบ ๆ —
 * โฟลเดอร์ที่ลูกค้าคิดว่าป้องกันไว้แล้วจะเปิดให้ทุกคนเข้าถึงโดยไม่มีอะไรบอก
 *
 * **วิธีตัดสิน** — แยกสองอย่างที่ต่างกันมาก:
 *
 *   1. `.htaccess` ที่มีแต่ **กฎ rewrite** (WordPress, Laravel, CodeIgniter ที่ส่งทุก
 *      คำขอที่ไม่ตรงไฟล์จริงเข้า index.php) — กฎพวกนี้มีผลเฉพาะตอนไฟล์ **ไม่มีอยู่จริง**
 *      การให้ nginx ตอบไฟล์ที่มีอยู่จริงจึงไม่เปลี่ยนพฤติกรรมอะไรเลย · **ปลอดภัย**
 *   2. `.htaccess` ที่มี **กฎควบคุมการเข้าถึงหรือแก้ response** — ต้องให้ Apache ตอบ
 *      เท่านั้น เพราะ nginx ไม่รู้จักกฎเหล่านั้น
 *
 * โฟลเดอร์ย่อยที่มี `.htaccess` ชนิดที่ 2 จะถูกบังคับให้ผ่าน Apache เป็นราย location
 * ส่วนที่เหลือของเว็บยังได้ความเร็วของ nginx เต็ม ๆ
 *
 * **จงใจตัดสินแบบระแวงไว้ก่อน** — เจอคำสั่งที่ไม่รู้จักถือว่าไม่ปลอดภัย เพราะการเดาผิด
 * ด้านนี้แค่ทำให้ช้าลง แต่เดาผิดอีกด้านคือข้อมูลลูกค้าหลุด
 */
final class HtaccessScan
{
    /** ไม่ไล่ลึกเกินนี้ — เว็บที่มีโฟลเดอร์ซ้อนลึกมากไม่ควรทำให้การเขียน vhost ช้า */
    private const MAX_DEPTH = 3;

    /** จำนวนโฟลเดอร์สูงสุดที่ยอมสแกน กันเว็บที่มีโฟลเดอร์นับหมื่น */
    private const MAX_DIRS = 500;

    /**
     * คำสั่งที่แปลว่า "ไฟล์ในโฟลเดอร์นี้ต้องผ่าน Apache"
     *
     * ครอบคลุมการปฏิเสธการเข้าถึง (Require/Deny/Allow), การขอรหัสผ่าน (Auth*),
     * การกำหนดขอบเขตรายไฟล์ (<Files>/<FilesMatch>) และการแก้ response header
     * (Header/Expires/AddType) ซึ่งทั้งหมดเปลี่ยนผลลัพธ์ของไฟล์ static โดยตรง
     */
    private const UNSAFE_DIRECTIVES = [
        'require', 'deny', 'allow', 'satisfy',
        'authtype', 'authname', 'authuserfile', 'authbasicprovider',
        '<files', '<filesmatch', '<directory', '<location', '<limit',
        'header', 'expiresbytype', 'expiresdefault', 'expiresactive',
        'addtype', 'addhandler', 'sethandler', 'forcetype',
        'errordocument', 'options', 'redirectmatch', 'redirect',
    ];

    /**
     * @return array{static_ok:bool,proxy_dirs:list<string>}
     *   static_ok  — nginx ตอบไฟล์ static ของเว็บนี้เองได้ไหม
     *   proxy_dirs — เส้นทาง URL ของโฟลเดอร์ที่ต้องบังคับผ่าน Apache
     */
    public static function inspect(Executor $executor, string $docroot): array
    {
        $root = $executor->path($docroot);

        if (!$executor->exists($root)) {
            // ยังไม่มีไฟล์เว็บ (เพิ่งสร้าง) — ปลอดภัยที่จะให้ nginx ตอบ static
            // ครั้งถัดไปที่เขียน vhost จะสแกนใหม่อยู่แล้ว
            return ['static_ok' => true, 'proxy_dirs' => []];
        }

        $rootFile = $root . '/.htaccess';
        $staticOk = !$executor->exists($rootFile) || self::isRewriteOnly($executor->readFile($rootFile));

        return [
            'static_ok' => $staticOk,
            'proxy_dirs' => $staticOk ? self::scan($executor, $root, '', 1) : [],
        ];
    }

    /**
     * ไฟล์นี้มีแต่กฎที่ไม่กระทบไฟล์ static ที่มีอยู่จริงหรือไม่
     *
     * ยอมรับเฉพาะบรรทัดที่รู้จักแน่ ๆ ว่าไม่เป็นอันตราย — บรรทัดว่าง คอมเมนต์
     * บล็อก `<IfModule mod_rewrite.c>` และตระกูล Rewrite* ทั้งหมด
     */
    public static function isRewriteOnly(string $contents): bool
    {
        foreach (explode("\n", $contents) as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            $lower = strtolower($line);

            // บล็อก IfModule ของ mod_rewrite และตัวปิดบล็อก ถือว่าไม่มีผลในตัวเอง
            if (str_starts_with($lower, '<ifmodule mod_rewrite')
                || str_starts_with($lower, '</ifmodule')
                || str_starts_with($lower, 'rewrite')
                || str_starts_with($lower, 'directoryindex')) {
                continue;
            }

            foreach (self::UNSAFE_DIRECTIVES as $directive) {
                if (str_starts_with($lower, $directive)) {
                    return false;
                }
            }

            // คำสั่งที่ไม่รู้จัก — ระแวงไว้ก่อน ให้ Apache ตอบทั้งเว็บ
            return false;
        }

        return true;
    }

    /**
     * ไล่หาโฟลเดอร์ย่อยที่มี `.htaccess` ซึ่งต้องผ่าน Apache
     *
     * @return list<string> เส้นทางแบบ URL ขึ้นต้นและลงท้ายด้วย /
     */
    private static function scan(Executor $executor, string $absolute, string $urlPath, int $depth): array
    {
        static $seen = 0;

        if ($depth === 1) {
            $seen = 0;
        }

        if ($depth > self::MAX_DEPTH || $seen >= self::MAX_DIRS) {
            return [];
        }

        $found = [];

        foreach ($executor->listDirectory($absolute) as $entry) {
            $name = is_array($entry) ? (string) ($entry['name'] ?? '') : (string) $entry;
            $isDir = is_array($entry) ? (($entry['type'] ?? '') === 'dir') : false;

            if ($name === '' || !$isDir || str_starts_with($name, '.')) {
                continue;
            }

            $seen++;
            $childUrl = $urlPath . '/' . $name;
            $childAbs = $absolute . '/' . $name;

            if ($executor->exists($childAbs . '/.htaccess')
                && !self::isRewriteOnly($executor->readFile($childAbs . '/.htaccess'))) {
                // เจอกฎที่ nginx ทำแทนไม่ได้ — ส่งทั้งโฟลเดอร์ให้ Apache แล้วไม่ต้องไล่ลึกต่อ
                $found[] = $childUrl . '/';
                continue;
            }

            $found = [...$found, ...self::scan($executor, $childAbs, $childUrl, $depth + 1)];
        }

        return $found;
    }
}
