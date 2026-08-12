<?php

declare(strict_types=1);

/**
 * http://localhost บนเครื่องพัฒนา — panel เป็นคนดูแลไฟล์ ไม่ใช่ผู้ดูแลเขียนเอง
 *
 * **เจอจากการใช้งานจริง (2026-08-12):** เครื่องพัฒนามี vhost ของ localhost ที่เขียน
 * ด้วยมือไว้ พอสลับไปโหมด nginx-proxy Apache ถอยไปฟัง 127.0.0.1:8080 และ nginx
 * ไม่มี server block ของ localhost เลย — http://localhost หายไปโดยไม่มีอะไรฟ้อง
 * เพราะไม่มีใครแปลงไฟล์ที่ไม่ได้อยู่ในระบบให้
 *
 * สิ่งที่ชุดนี้เฝ้า:
 *   - ปิดเป็นค่าเริ่มต้น เครื่องที่ให้บริการจริงต้องไม่มีไฟล์นี้โผล่มาเอง
 *   - เปิดแล้วต้องได้ไฟล์ครบตามโหมด (โหมด proxy ต้องมีทั้งชั้นหน้าและชั้นหลัง)
 *   - ต้องไม่กลืน vhost ของเว็บอื่นที่ชี้มาที่ 127.0.0.1 ผ่าน /etc/hosts
 *   - ต้องเรียกได้เฉพาะจากเครื่องตัวเอง
 */

use Phpcp\Agent\Executor\SandboxExecutor;
use Phpcp\Driver\Template;
use Phpcp\Driver\WebServer\ApacheDriver;
use Phpcp\Driver\WebServer\LocalhostSite;
use Phpcp\Driver\WebServer\NginxDriver;
use Phpcp\Driver\WebServer\NginxProxyDriver;

group('http://localhost — vhost ของเครื่องพัฒนาที่ panel ดูแลให้');

function localhostTemplates(): Template
{
    return new Template(PHPCP_ROOT . '/templates');
}

function localhostExecutor(): SandboxExecutor
{
    return new SandboxExecutor(sys_get_temp_dir() . '/phpcp-localhost-' . getmypid());
}

function localhostSite(): LocalhostSite
{
    return new LocalhostSite('/mnt/Server/htdocs', '8.4');
}

test('ไม่ตั้งค่า = ไม่มีไฟล์ของ localhost เลยสักโหมด', static function (): void {
    // เครื่องที่ให้บริการจริงไม่ตั้ง sites.localhost_docroot — การเสิร์ฟโฟลเดอร์รวม
    // งานทุกโปรเจกต์ผ่านเว็บไม่ใช่สิ่งที่เครื่องแบบนั้นควรทำ
    $executor = localhostExecutor();

    $drivers = [
        'apache' => new ApacheDriver(localhostTemplates()),
        'nginx' => new NginxDriver(localhostTemplates()),
        'nginx-proxy' => new NginxProxyDriver(localhostTemplates()),
    ];

    foreach ($drivers as $mode => $driver) {
        foreach (array_keys($driver->globalFiles($executor)) as $path) {
            assertTrue(
                !str_contains($path, 'localhost'),
                "โหมด {$mode} เขียนไฟล์ของ localhost ทั้งที่ไม่ได้เปิดไว้: {$path}",
            );
        }
    }
});

test('โหมด apache — vhost ผูก *:80 ไม่ใช่ 127.0.0.1:80', static function (): void {
    /*
     * Apache เลือก vhost จาก **ที่อยู่ก่อน** แล้วค่อยดู ServerName · ถ้าไฟล์นี้เป็น
     * vhost เดียวที่ประกาศ 127.0.0.1:80 คำขอทุกอันที่เข้าทาง loopback จะตกมาที่นี่หมด
     * รวมถึงโดเมนทดสอบใน /etc/hosts ที่ชี้มาที่ 127.0.0.1 — เว็บทดสอบทั้งเครื่องพัง
     */
    $files = (new ApacheDriver(localhostTemplates(), localhostSite()))
        ->globalFiles(localhostExecutor());

    $vhost = $files[ApacheDriver::LOCALHOST_FILE] ?? '';

    assertTrue($vhost !== '', 'ต้องมีไฟล์ vhost ของ localhost');
    assertTrue(str_contains($vhost, '<VirtualHost *:80>'), 'ต้องผูก *:80: ' . $vhost);
    assertTrue(str_contains($vhost, 'ServerName localhost'), 'ต้องเลือกด้วยชื่อโฮสต์');
    assertTrue(str_contains($vhost, 'AllowOverride All'), 'โปรเจกต์บนเครื่องพัฒนาพึ่ง .htaccess');
});

test('เรียกได้เฉพาะจากเครื่องตัวเอง', static function (): void {
    // เครื่องอื่นในวงเน็ตที่ส่ง Host: localhost มาต้องไม่ได้อ่านโฟลเดอร์งานทั้งก้อน
    $apache = (new ApacheDriver(localhostTemplates(), localhostSite()))
        ->globalFiles(localhostExecutor())[ApacheDriver::LOCALHOST_FILE] ?? '';

    assertTrue(str_contains($apache, 'Require ip 127.0.0.1'), 'Apache ต้องจำกัดที่ loopback: ' . $apache);

    $nginx = (new NginxDriver(localhostTemplates(), localhostSite()))
        ->globalFiles(localhostExecutor())[NginxDriver::LOCALHOST_FILE] ?? '';

    assertTrue(str_contains($nginx, 'allow 127.0.0.1;'), 'nginx ต้องอนุญาตเฉพาะ loopback');
    assertTrue(str_contains($nginx, 'deny  all;'), 'nginx ต้องปฏิเสธที่เหลือ');
});

test('โหมด nginx-proxy ต้องได้ทั้งชั้นหน้าและชั้นหลัง', static function (): void {
    // ขาดไฟล์ชั้นหลัง = 502 · ขาดไฟล์ชั้นหน้า = ไม่มีใครรับคำขอเลย
    $files = (new NginxProxyDriver(localhostTemplates(), true, localhostSite()))
        ->globalFiles(localhostExecutor());

    $front = $files[NginxDriver::LOCALHOST_FILE] ?? '';
    $back = $files[ApacheDriver::LOCALHOST_FILE] ?? '';

    assertTrue($front !== '', 'ต้องมี server block ของ nginx');
    assertTrue($back !== '', 'ต้องมี vhost ชั้นหลังของ Apache');

    assertTrue(str_contains($front, 'proxy_pass http://127.0.0.1:8080'), 'ชั้นหน้าต้องส่งต่อให้ชั้นหลัง');
    assertTrue(
        str_contains($back, '<VirtualHost 127.0.0.1:8080>'),
        'ชั้นหลังต้องผูกกับพอร์ตของ backend ไม่งั้น Apache ที่ไม่ได้ฟัง 80 แล้วจะไม่มีใครรับ: ' . $back,
    );
});

test('PHP ใช้ pool ของดิสโทร ไม่ใช่ pool ของบัญชีลูกค้า', static function (): void {
    /*
     * ถ้ายืม pool ของลูกค้ามาใช้ โฟลเดอร์งานต้องอยู่ใน open_basedir ของบัญชีนั้น
     * และไฟล์ที่ PHP สร้างจะกลายเป็นของบัญชีนั้น — ที่แย่กว่านั้นคือขั้นตอนสร้างเว็บ
     * จะ `chown -R` โฟลเดอร์งานทั้งก้อนไปเป็นของลูกค้า
     */
    $socket = localhostSite()->fpmSocket();

    assertSame('/run/php/php8.4-fpm.sock', $socket, 'ต้องเป็น pool มาตรฐานของดิสโทร');
    assertTrue(!str_contains($socket, 'phpcp-'), 'ต้องไม่ใช่ pool ที่ panel สร้างให้บัญชีใด');
});

test('log ไปที่ไดเรกทอรีของ panel ไม่ใช่บ้านของบัญชีไหน', static function (): void {
    // บ้านของบัญชีอาจไม่มีอยู่ · Apache ที่เปิดไฟล์ log ไม่ได้จะ **สตาร์ตไม่ขึ้นทั้งตัว**
    // ซึ่งแปลว่าเว็บทุกเว็บบนเครื่องดับเพราะ vhost ของเครื่องพัฒนาไฟล์เดียว
    $site = localhostSite();

    assertTrue(str_starts_with($site->errorLog(), '/var/log/phpcp/'), 'error log ต้องอยู่ในที่ของ panel');
    assertTrue(str_starts_with($site->accessLog(), '/var/log/phpcp/'), 'access log ต้องอยู่ในที่ของ panel');
});
