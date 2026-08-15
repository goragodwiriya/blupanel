<?php

declare(strict_types=1);

namespace Phpcp\Driver\WebServer;

/**
 * เส้นทางที่ไม่มีผู้เข้าชมจริงคนไหนเรียก — ปฏิเสธไปเลย ไม่ต้องรอให้ใครถูกแบน
 *
 * ### ทำไมบล็อกที่เส้นทาง ไม่ใช่แบนที่ IP
 *
 * ลูกค้าบางรายเป็นโรงเรียนที่ทั้งโรงเรียนออกเน็ตผ่าน IP เดียว · การแบน IP เพราะ
 * นักเรียนคนเดียวที่เครื่องติดมัลแวร์แล้วสแกนอัตโนมัติ แปลว่าทั้งโรงเรียนเข้าเว็บ
 * ตัวเองไม่ได้ — และเพราะ fail2ban สั่ง firewall ซึ่งไม่รู้จัก vhost เขาจะเข้าเว็บ
 * ของลูกค้ารายอื่นบนเครื่องเดียวกันไม่ได้ด้วย · **เมื่อ IP ไม่ได้แทนคนคนเดียว
 * การแบน IP ก็ไม่ใช่เครื่องมือที่ถูก** และไม่มีการปรับเกณฑ์ไหนแก้ข้อนี้ได้
 *
 * เส้นทางในรายการนี้ไม่มีเบราว์เซอร์ของคนจริงเรียกเลยสักเส้นทางเดียว การปฏิเสธจึง:
 *
 *   · ไม่ต้องระบุตัวผู้เรียก — NAT ของโรงเรียนไม่เกี่ยวข้องโดยสิ้นเชิง
 *   · ไม่กินหน่วยความจำเพิ่มแม้แต่ไบต์เดียว — เป็นการจับคู่ที่เว็บเซิร์ฟเวอร์ทำอยู่แล้ว
 *     ต่างจาก fail2ban ที่กินราว 55MB และเพิ่มขึ้นตามจำนวน jail
 *   · กันตั้งแต่คำขอแรก ต่างจากการแบนที่ปล่อยครั้งที่ 1..N-1 ผ่านไปก่อน
 *
 * ### เกณฑ์ที่ใช้เลือกว่าอะไรควรอยู่ในรายการนี้
 *
 * **ต้องไม่มีการใช้งานที่ถูกต้องเลย** ไม่ใช่แค่ "ส่วนใหญ่ไม่ใช้" · `xmlrpc.php`
 * จึงไม่อยู่ในรายการ ทั้งที่เป็นเป้ายอดนิยม เพราะ WordPress ที่ใช้แอปมือถือหรือ
 * Jetpack เรียกมันจริง · ผู้ดูแลที่อยากปิดเพิ่มเขียนเองได้ในไฟล์ตั้งค่าของตัวเอง
 * ซึ่งถูกอ่านท้ายสุด
 *
 * ไฟล์บีบอัดทั่วไป (`.zip`, `.tar.gz`) ก็ไม่อยู่ในรายการ — เว็บจำนวนมากแจกไฟล์
 * ดาวน์โหลดจริง ๆ · ปิดไปแล้วลูกค้าโทรมา ซึ่งแย่กว่าปล่อยไว้
 */
final class ProbeBlocklist
{
    /**
     * โฟลเดอร์ของ dependency ที่ไฟล์ PHP ข้างในต้องรันไม่ได้
     *
     * `/vendor/phpunit/phpunit/src/Util/PHP/eval-stdin.php` คือเส้นทางที่ถูกยิงบ่อย
     * ที่สุดในโลกของโฮสติ้ง PHP — เป็น RCE ทันทีถ้าลูกค้า deploy โดยวาง `vendor/`
     * ไว้ใต้ docroot ซึ่งเจอบ่อยมาก · กฎเดิมกันแค่ `vendor/bin/` จึงไม่ครอบเส้นทางนี้
     *
     * **กันเฉพาะไฟล์ `.php` ไม่ใช่ทั้งโฟลเดอร์** — บางธีมอ้างอิง css/js จาก `vendor/`
     * จริง การปิดทั้งก้อนจะทำหน้าเว็บพัง ส่วนการปิดเฉพาะ `.php` ปิดช่องรันโค้ด
     * โดยไม่แตะไฟล์ที่หน้าเว็บใช้
     */
    public const CODE_DIRS = ['vendor', 'node_modules', 'bower_components'];

    /**
     * นามสกุลที่เป็นข้อมูลหลังบ้านหลุดออกมา ไม่ใช่ของที่ตั้งใจแจก
     *
     * `.sql` คือ dump ของฐานข้อมูลทั้งก้อน · ที่เหลือคือไฟล์ที่ editor กับ
     * `cp file file.bak` ทิ้งไว้ ซึ่งเสิร์ฟเป็นข้อความล้วนพร้อมรหัสผ่านข้างใน
     * (ไฟล์ `.php.bak` ไม่ถูกส่งไป FPM เพราะนามสกุลไม่ใช่ `.php`)
     */
    public const LEAK_EXTENSIONS = ['sql', 'sql.gz', 'sql.bz2', 'bak', 'old', 'orig', 'save', 'swp', 'swo', 'rej'];

    /** เส้นทางเดี่ยว ๆ ที่ไม่ควรเปิดสู่ภายนอกเลย */
    public const EXACT_PATHS = ['server-status', 'server-info'];

    /**
     * กฎสำหรับ Apache — ใช้ทั้งโหมด apache ล้วนและโหมด nginx ชั้นหน้า
     *
     * ใช้ `LocationMatch` (เทียบกับ URL) ไม่ใช่ `DirectoryMatch` (เทียบกับเส้นทางไฟล์)
     * เพราะสิ่งที่ต้องกันคือ**คำขอ** · เส้นทางไฟล์จริงเปลี่ยนไปตาม docroot ของแต่ละเว็บ
     * แต่ URL ที่สแกนเนอร์ยิงเหมือนกันทุกเครื่องในโลก
     */
    public static function apache(): string
    {
        $dirs = implode('|', self::CODE_DIRS);
        $extensions = implode('|', array_map(
            static fn (string $ext): string => str_replace('.', '\.', $ext),
            self::LEAK_EXTENSIONS,
        ));
        $exact = implode('|', self::EXACT_PATHS);

        return <<<CONF
            # ไฟล์ PHP ในโฟลเดอร์ dependency — /vendor/phpunit/.../eval-stdin.php คือ RCE
            <LocationMatch "(?i)^/({$dirs})/.*\.php$">
                Require all denied
            </LocationMatch>

            # ข้อมูลหลังบ้านที่หลุดออกมา — dump ฐานข้อมูลและไฟล์ที่ editor ทิ้งไว้
            <LocationMatch "(?i)\.({$extensions})$">
                Require all denied
            </LocationMatch>

            <LocationMatch "(?i)^/({$exact})$">
                Require all denied
            </LocationMatch>
            CONF;
    }

    /**
     * กฎสำหรับ nginx — ต้องอยู่**ก่อน**บล็อกที่เสิร์ฟไฟล์ static
     *
     * nginx เลือก location แบบ regex ตามลำดับที่เขียน อันแรกที่ตรงชนะ · ในโหมด
     * nginx ชั้นหน้า รายการนามสกุลที่เสิร์ฟตรงมี `gz` อยู่ด้วย ถ้ากฎนี้อยู่ทีหลัง
     * `backup.sql.gz` จะถูกส่งออกไปโดยไม่เคยผ่าน Apache ที่มีกฎกันอยู่เลย
     */
    public static function nginx(): string
    {
        $dirs = implode('|', self::CODE_DIRS);
        $extensions = implode('|', array_map(
            static fn (string $ext): string => str_replace('.', '\.', $ext),
            self::LEAK_EXTENSIONS,
        ));
        $exact = implode('|', self::EXACT_PATHS);

        return <<<CONF
            # ไฟล์ PHP ในโฟลเดอร์ dependency — /vendor/phpunit/.../eval-stdin.php คือ RCE
            location ~* ^/({$dirs})/.*\.php$ {
                deny all;
            }

            # ข้อมูลหลังบ้านที่หลุดออกมา — ต้องมาก่อนบล็อก static ที่เสิร์ฟ .gz ตรง ๆ
            location ~* \.({$extensions})$ {
                deny all;
            }

            location ~* ^/({$exact})$ {
                deny all;
            }
            CONF;
    }
}
