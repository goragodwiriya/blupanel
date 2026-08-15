<?php

declare(strict_types=1);

/**
 * เส้นทางที่สแกนเนอร์ยิง ต้องถูกปฏิเสธที่เว็บเซิร์ฟเวอร์ ไม่ใช่ด้วยการแบน IP
 *
 * **ทำไมไม่แบน IP:** ลูกค้าบางรายเป็นโรงเรียนที่ทั้งโรงเรียนออกเน็ตผ่าน IP เดียว
 * นักเรียนคนเดียวที่เครื่องติดมัลแวร์แล้วสแกนอัตโนมัติ = ทั้งโรงเรียนถูกตัดขาดจาก
 * ทุกเว็บบนเครื่อง (fail2ban สั่ง firewall ซึ่งไม่รู้จัก vhost) · เมื่อ IP ไม่ได้แทน
 * คนคนเดียว การแบน IP ก็ไม่ใช่เครื่องมือที่ถูก
 *
 * เทสต์ชุดนี้ทดสอบด้วย **เส้นทางจริงที่สแกนเนอร์ยิง** เทียบกับ regex ที่จะถูกเขียนลง
 * ไฟล์ตั้งค่าจริง — ไม่ใช่ตรวจว่ามีคำว่า vendor อยู่ในไฟล์ ซึ่งผ่านได้โดยที่กฎใช้ไม่ได้จริง
 */

use Phpcp\Driver\WebServer\ProbeBlocklist;

group('ProbeBlocklist — ปฏิเสธเส้นทางที่ไม่มีใครเรียกจริง');

/**
 * ดึง regex ออกจากบล็อก Apache แล้วลองจับ URL หนึ่งเส้นทาง
 *
 * `(?i)` ที่ต้นรูปแบบเป็นของ PCRE ซึ่งทั้ง Apache และ PHP ใช้ตัวเดียวกัน
 */
function apacheBlocks(string $url): bool
{
    preg_match_all('/<LocationMatch "([^"]+)">/', ProbeBlocklist::apache(), $m);

    foreach ($m[1] as $pattern) {
        if (preg_match('~' . str_replace('~', '\~', $pattern) . '~', $url) === 1) {
            return true;
        }
    }

    return false;
}

/** เหมือนกัน แต่ดึงจากบล็อกของ nginx ซึ่งใช้ `location ~* <regex> {` */
function nginxBlocks(string $url): bool
{
    preg_match_all('/location ~\* (\S+) \{/', ProbeBlocklist::nginx(), $m);

    foreach ($m[1] as $pattern) {
        // `~*` ของ nginx = ไม่สนตัวพิมพ์ใหญ่เล็ก จึงเติม i ให้ตรงกัน
        if (preg_match('~' . str_replace('~', '\~', $pattern) . '~i', $url) === 1) {
            return true;
        }
    }

    return false;
}

test('เส้นทางที่สแกนเนอร์ยิงจริงต้องถูกปฏิเสธทั้งสองเว็บเซิร์ฟเวอร์', static function (): void {
    /*
     * บรรทัดแรกคือ RCE ที่ถูกยิงบ่อยที่สุดในโลกของโฮสติ้ง PHP · กฎเดิมกันแค่
     * `vendor/bin/` จึงปล่อยเส้นทางนี้ผ่านมาตลอด — วัดแล้วก่อนแก้ว่าผ่านได้จริง
     */
    $mustBlock = [
        '/vendor/phpunit/phpunit/src/Util/PHP/eval-stdin.php',
        '/vendor/phpunit/phpunit/phpunit.php',
        '/VENDOR/PhpUnit/eval-stdin.PHP',              // สแกนเนอร์สลับตัวพิมพ์เพื่อเลี่ยงกฎ
        '/node_modules/.bin/anything.php',
        '/bower_components/x/y.php',
        '/backup.sql',
        '/dump.sql.gz',
        '/db/backup.sql.bz2',
        '/wp-config.php.bak',
        '/config.php.old',
        '/index.php.save',
        '/.env.orig',
        '/app/config.php.swp',
        '/server-status',
        '/server-info',
    ];

    foreach ($mustBlock as $url) {
        assertTrue(apacheBlocks($url), "Apache ต้องปฏิเสธ: {$url}");
        assertTrue(nginxBlocks($url), "nginx ต้องปฏิเสธ: {$url}");
    }
});

test('เส้นทางปกติของเว็บจริงต้องไม่ถูกปฏิเสธ', static function (): void {
    /*
     * **ข้อนี้สำคัญเท่าข้อบน** — กฎที่กันของจริงด้วยคือกฎที่ผู้ดูแลจะปิดทิ้งทั้งชุด
     * ภายในสัปดาห์เดียว แล้วเครื่องจะเหลือการป้องกันเป็นศูนย์
     *
     * `vendor/` ที่เป็น css/js ต้องผ่าน — บางธีมอ้างอิงไฟล์จากที่นั่นจริง เรากัน
     * เฉพาะ `.php` ซึ่งเป็นช่องรันโค้ด ไม่ใช่ทั้งโฟลเดอร์
     */
    $mustPass = [
        '/',
        '/index.php',
        '/wp-login.php',
        '/xmlrpc.php',                                  // WordPress ที่ใช้แอปมือถือเรียกจริง
        '/vendor/bootstrap/css/bootstrap.min.css',
        '/vendor/jquery/jquery.min.js',
        '/node_modules/chart.js/dist/chart.umd.js',
        '/downloads/manual.zip',                        // เว็บแจกไฟล์ดาวน์โหลดจริง
        '/files/release.tar.gz',
        '/uploads/photo.jpg',
        '/api/v1/users',
        '/wp-content/themes/x/style.css',
        '/backup/index.php',                            // โฟลเดอร์ชื่อ backup ไม่ใช่ไฟล์ .sql
    ];

    foreach ($mustPass as $url) {
        assertTrue(!apacheBlocks($url), "Apache ต้องไม่ปฏิเสธเส้นทางปกติ: {$url}");
        assertTrue(!nginxBlocks($url), "nginx ต้องไม่ปฏิเสธเส้นทางปกติ: {$url}");
    }
});

test('กฎของ nginx ต้องมาก่อนบล็อกที่เสิร์ฟไฟล์ static', static function (): void {
    /*
     * nginx เลือก location แบบ regex ตามลำดับที่เขียน อันแรกที่ตรงชนะ
     *
     * ในโหมด nginx ชั้นหน้า รายการนามสกุลที่เสิร์ฟตรงมี `gz` อยู่ด้วย — ถ้ากฎกัน
     * อยู่ทีหลัง `backup.sql.gz` จะถูกส่งออกไปโดยไม่เคยผ่าน Apache ที่มีกฎกันอยู่เลย
     */
    $body = (string) file_get_contents(PHPCP_ROOT . '/templates/nginx/proxy-body.conf.tpl');

    $deny = strpos($body, '{{PROBE_DENY}}');
    $static = strpos($body, '{{STATIC_SECTION}}');

    assertTrue($deny !== false && $static !== false, 'ต้องมีทั้งสองตัวแปรในเทมเพลต');
    assertTrue($deny < $static, 'กฎปฏิเสธต้องอยู่ก่อนบล็อก static ไม่งั้น .sql.gz หลุดออกไปทาง nginx');
});

test('ทุกโหมดของเว็บเซิร์ฟเวอร์ต้องได้กฎชุดเดียวกัน', static function (): void {
    /*
     * เคยมีบทเรียนแล้วว่ากฎที่ถูกคัดลอกไปหลายเทมเพลตจะถูกแก้ที่เดียวแล้วลืมอีกที่
     * (คอมเมนต์ใน ApacheDriver::renderVhost อธิบายไว้) · ที่นี่จึงตรวจว่าทั้งสาม
     * เส้นทางการเรนเดอร์อ้าง ProbeBlocklist ตัวเดียวกัน ไม่ใช่ต่างคนต่างเขียน
     */
    foreach ([
        'src/Driver/WebServer/ApacheDriver.php',
        'src/Driver/WebServer/NginxDriver.php',
        'src/Driver/WebServer/NginxProxyDriver.php',
    ] as $file) {
        $source = (string) file_get_contents(PHPCP_ROOT . '/' . $file);

        assertTrue(
            str_contains($source, 'ProbeBlocklist::'),
            basename($file) . ' ต้องใช้รายการกลาง ไม่ใช่เขียนกฎเอง',
        );
    }

    // โหมด nginx ชั้นหน้าใช้ Apache เป็นชั้นหลัง จึงต้องได้กฎของ Apache ด้วย
    $proxy = (string) file_get_contents(PHPCP_ROOT . '/src/Driver/WebServer/NginxProxyDriver.php');

    assertTrue(str_contains($proxy, 'ProbeBlocklist::apache()'), 'ชั้นหลังต้องได้กฎของ Apache');
    assertTrue(str_contains($proxy, 'ProbeBlocklist::nginx()'), 'ชั้นหน้าต้องได้กฎของ nginx');
});
