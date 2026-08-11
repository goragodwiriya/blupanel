<?php

declare(strict_types=1);

/**
 * NginxDriver — เกณฑ์รับงานเฟส 5
 *
 * ทั้งสอง driver ต้องให้ "ผลด้านความปลอดภัยเท่ากัน" แม้เขียนคนละภาษา
 * ถ้าย้ายจาก Apache ไป nginx แล้วกฎกันไฟล์ลับหายไปเงียบ ๆ นั่นคือช่องโหว่
 * ที่เกิดจากการเปลี่ยนค่าตั้งหนึ่งบรรทัด ซึ่งเป็นสิ่งที่เทสต์ชุดนี้กันไว้
 */

use Phpcp\Agent\Executor\SandboxExecutor;
use Phpcp\Domain\Site;
use Phpcp\Domain\UserAccount;
use Phpcp\Driver\Template;
use Phpcp\Driver\WebServer\ApacheDriver;
use Phpcp\Driver\WebServer\NginxDriver;

group('NginxDriver — ต้องปลอดภัยเท่ากับ Apache');

function ngx(): NginxDriver
{
    return new NginxDriver(new Template(PHPCP_ROOT . '/templates'));
}

function ngxExecutor(): SandboxExecutor
{
    return new SandboxExecutor(sys_get_temp_dir() . '/phpcp-ngx-' . getmypid());
}

function ngxSite(string $mode = 'off', array $aliases = [], string $status = 'active'): Site
{
    return new Site(1, 'ทดสอบ', 'example.test', new UserAccount(7, 'sitefiles'), '8.4', $mode, $status, $aliases);
}

test('vhost แบบไม่มี SSL ต้องไม่มีบล็อก 443', static function (): void {
    $conf = ngx()->renderVhost(ngxSite('off'), ngxExecutor());

    assertTrue(!str_contains($conf, 'ssl_certificate'), 'เว็บที่ปิด SSL ต้องไม่มี ssl_certificate');
    assertTrue(!str_contains($conf, 'listen 443'), 'เว็บที่ปิด SSL ต้องไม่ฟังพอร์ต 443');
});

test('กฎกันไฟล์ลับต้องอยู่ในบล็อก HTTPS ด้วย', static function (): void {
    $conf = ngx()->renderVhost(ngxSite('on'), ngxExecutor());

    // แยกที่ ssl_certificate เพราะบรรทัด IPv6 เขียนว่า `listen [::]:443` ไม่ใช่ `listen 443`
    $at = strpos($conf, 'ssl_certificate ');
    assertTrue($at !== false, 'ต้องมีบล็อก HTTPS');

    $ssl = substr($conf, $at);

    foreach (['location ~ /\\.', 'composer\\.', '\\.git', 'fastcgi_hide_header X-Powered-By'] as $needle) {
        assertTrue(str_contains($ssl, $needle), "บล็อก HTTPS ต้องมีกฎ {$needle}");
    }
});

test('ต้องกัน path info ปลอมของ fastcgi', static function (): void {
    // /image.gif/x.php หลอกให้ nginx ส่งไฟล์ที่ไม่ใช่ PHP เข้า FPM แล้วถูกรันเป็นโค้ด
    // เป็นช่องโหว่คลาสสิกของ nginx + fastcgi ที่ Apache ไม่มีเพราะใช้ FilesMatch
    $conf = ngx()->renderVhost(ngxSite('on'), ngxExecutor());

    $at = strpos($conf, 'location ~ \.php$');
    assertTrue($at !== false, 'ต้องมี location ของ .php');

    $block = substr($conf, $at, 400);
    assertTrue(str_contains($block, 'try_files $uri =404;'), 'location ของ .php ต้องมี try_files $uri =404');
});

test('HSTS ต้องมีเฉพาะตอนบังคับ HTTPS', static function (): void {
    assertTrue(
        !str_contains(ngx()->renderVhost(ngxSite('on'), ngxExecutor()), 'Strict-Transport-Security'),
        'โหมด on ต้องไม่มี HSTS',
    );
    assertTrue(
        str_contains(ngx()->renderVhost(ngxSite('forced'), ngxExecutor()), 'Strict-Transport-Security'),
        'โหมด forced ต้องมี HSTS',
    );
});

test('บังคับ HTTPS ต้องยกเว้นเส้นทางตรวจสอบของ Let\'s Encrypt', static function (): void {
    $conf = ngx()->renderVhost(ngxSite('forced'), ngxExecutor());

    // ^~ ทำให้ location นี้ชนะ location / เสมอ จึงไม่ต้องเขียนเงื่อนไขยกเว้นซ้ำ
    assertTrue(
        str_contains($conf, 'location ^~ /.well-known/acme-challenge/'),
        'ต้องใช้ ^~ เพื่อให้ชนะ location / ที่ redirect',
    );
    assertTrue(str_contains($conf, 'return 301 https://'), 'ต้อง redirect ไป HTTPS');

    $acmeAt = strpos($conf, 'acme-challenge');
    $redirectAt = strpos($conf, 'return 301');
    assertTrue($acmeAt < $redirectAt, 'บล็อก acme ต้องมาก่อนกฎ redirect');
});

test('เว็บที่ถูกระงับต้องไม่ส่งคำขอไปยัง PHP เลย', static function (): void {
    $conf = ngx()->renderVhost(ngxSite('off', [], 'suspended'), ngxExecutor());

    assertTrue(!str_contains($conf, 'fastcgi_pass'), 'เว็บที่ถูกระงับต้องไม่มี fastcgi_pass');
    assertTrue(str_contains($conf, 'return 503'), 'ต้องตอบ 503');
});

test('ใช้ไวยากรณ์ http2 ที่ nginx ทุกรุ่นที่รองรับเข้าใจ', static function (): void {
    // `http2 on;` แยกบรรทัดมีตั้งแต่ 1.25.1 แต่ระบบที่ v1 รองรับส่ง nginx เก่ากว่านั้นหมด
    // (Ubuntu 22.04 = 1.18, Debian 12 = 1.22, Ubuntu 24.04 = 1.24) — เจอตอนทดสอบกับ nginx จริง
    $conf = ngx()->renderVhost(ngxSite('on'), ngxExecutor());

    assertTrue(!preg_match('/^\s*http2\s+on\s*;/m', $conf), 'ห้ามใช้ directive http2 แบบแยกบรรทัด');
    assertTrue(str_contains($conf, 'listen 443 ssl http2;'), 'ต้องใช้พารามิเตอร์ http2 ของ listen');
});

test('โดเมนสำรองต้องอยู่ใน server_name ของทุกบล็อก', static function (): void {
    $conf = ngx()->renderVhost(ngxSite('on', ['www.example.test']), ngxExecutor());

    assertSame(
        2,
        substr_count($conf, 'server_name example.test www.example.test;'),
        'โดเมนสำรองต้องอยู่ทั้งบล็อก HTTP และ HTTPS',
    );
});

test('ค่าที่มีอักขระอันตรายต้องแทรกเข้า config ไม่ได้', static function (): void {
    // ; และขึ้นบรรทัดใหม่ใน nginx คือการจบ directive — แทรก directive ใหม่ได้ทันที
    assertRejects(
        \Phpcp\Agent\ValidationError::class,
        static fn () => Template::assertValue('server_name', "evil.test;\n    root /etc;"),
        'ค่าที่มีขึ้นบรรทัดใหม่ต้องถูกปฏิเสธ',
    );
});

test('ทั้งสอง driver ต้องเห็นตรงกันว่าเว็บไหนใช้ SSL', static function (): void {
    // ถ้าสอง driver ตัดสินใจไม่ตรงกัน การสลับเว็บเซิร์ฟเวอร์จะทำให้บางเว็บเสีย SSL เงียบ ๆ
    $apache = new ApacheDriver(new Template(PHPCP_ROOT . '/templates'));
    $executor = ngxExecutor();

    foreach (['off', 'on', 'forced'] as $mode) {
        $site = ngxSite($mode);
        $a = $apache->renderVhost($site, $executor);
        $n = ngx()->renderVhost($site, $executor);

        assertSame(
            str_contains($a, 'SSLEngine on'),
            str_contains($n, 'ssl_certificate '),
            "โหมด {$mode}: ทั้งสอง driver ต้องเปิด SSL ตรงกัน",
        );

        assertSame(
            str_contains($a, 'Strict-Transport-Security'),
            str_contains($n, 'Strict-Transport-Security'),
            "โหมด {$mode}: ทั้งสอง driver ต้องใส่ HSTS ตรงกัน",
        );
    }
});
