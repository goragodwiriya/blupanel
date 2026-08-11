<?php

declare(strict_types=1);

/**
 * NginxProxyDriver — nginx ชั้นหน้า + Apache ชั้นหลัง
 *
 * โหมดนี้มีไว้เพื่อให้ `.htaccess` ของลูกค้าทำงานได้จริงบนเครื่องที่ใช้ nginx
 * สิ่งที่เทสต์ชุดนี้เฝ้าคือคุณสมบัติที่ถ้าหายไปแล้ว **ไม่มีอะไรฟ้อง** จนกว่าจะสายเกินไป:
 *
 *   - ชั้นหลังต้องอ่าน .htaccess ได้ (AllowOverride All) ไม่งั้นทั้งโหมดไม่มีความหมาย
 *   - ชั้นหลังต้องไม่ฟังพอร์ตสาธารณะ ไม่งั้นมีทางลัดข้าม TLS และข้าม rate limit
 *   - ที่อยู่ผู้ใช้จริงต้องมาถึงชั้นหลัง ไม่งั้น fail2ban แบน 127.0.0.1 ตัวเอง
 *   - การบังคับ HTTPS ต้องทำที่ชั้นหน้าชั้นเดียว ไม่งั้น redirect วนไม่จบ
 */

use Phpcp\Agent\Executor\SandboxExecutor;
use Phpcp\Domain\Site;
use Phpcp\Domain\UserAccount;
use Phpcp\Driver\Template;
use Phpcp\Driver\WebServer\NginxProxyDriver;

group('NginxProxyDriver — .htaccess ต้องใช้งานได้จริงหลัง nginx');

function proxyDriver(): NginxProxyDriver
{
    return new NginxProxyDriver(new Template(PHPCP_ROOT . '/templates'));
}

function proxyExecutor(): SandboxExecutor
{
    return new SandboxExecutor(sys_get_temp_dir() . '/phpcp-proxy-' . getmypid());
}

function proxySite(string $mode = 'off', array $aliases = [], string $status = 'active'): Site
{
    return new Site(1, 'ทดสอบ', 'example.test', new UserAccount(7, 'sitefiles'), '8.4', $mode, $status, $aliases);
}

/**
 * ตัดคอมเมนต์ออกก่อนตรวจ "ต้องไม่มี"
 *
 * เทมเพลตของโปรเจกต์นี้อธิบายเหตุผลไว้ละเอียด คำอย่าง RemoteIPInternalProxy จึงโผล่
 * ในคอมเมนต์ที่บอกว่า "ห้ามใช้ตัวนี้" · การ grep ทั้งไฟล์จะจับคำในคอมเมนต์แล้วฟ้องผิด
 */
function withoutComments(string $conf): string
{
    $lines = array_filter(
        explode("\n", $conf),
        static fn (string $line): bool => !str_starts_with(ltrim($line), '#'),
    );

    return implode("\n", $lines);
}

// --- 1. เหตุผลของการมีอยู่: .htaccess ต้องถูกอ่าน ----------------------------

test('ชั้นหลังต้องเปิด AllowOverride All — ถ้าไม่มี ทั้งโหมดนี้ไม่มีเหตุผลให้มีอยู่', static function (): void {
    $conf = proxyDriver()->renderBackendVhost(proxySite(), proxyExecutor());

    assertTrue(str_contains($conf, 'AllowOverride All'), 'ชั้นหลังต้องอ่าน .htaccess ได้: ' . $conf);
});

test('ชั้นหน้าต้องส่งต่อทุกคำขอ ไม่เสิร์ฟไฟล์ static เอง', static function (): void {
    $conf = proxyDriver()->renderVhost(proxySite(), proxyExecutor());

    // ถ้า nginx เสิร์ฟไฟล์เอง กฎ .htaccess ที่กันโฟลเดอร์จะถูกข้ามเงียบ ๆ
    assertTrue(!str_contains(withoutComments($conf), 'try_files'), 'ต้องไม่มี try_files ที่ทำให้ nginx ตอบไฟล์เอง: ' . $conf);
    assertTrue(!str_contains(withoutComments($conf), 'fastcgi_pass'), 'PHP ต้องผ่าน Apache ไม่ใช่ยิงเข้า FPM ตรง: ' . $conf);
    assertTrue(str_contains($conf, 'proxy_pass http://127.0.0.1:8080'), 'ต้องส่งต่อไปชั้นหลัง: ' . $conf);
});

// --- 2. ชั้นหลังต้องไม่โผล่ออกอินเทอร์เน็ต ------------------------------------

test('ชั้นหลังผูกกับ loopback เท่านั้น ไม่ใช่ทุกหน้าตัดเน็ต', static function (): void {
    $conf = proxyDriver()->renderBackendVhost(proxySite(), proxyExecutor());

    assertTrue(str_contains($conf, '<VirtualHost 127.0.0.1:8080>'), 'vhost ชั้นหลังต้องผูกกับ 127.0.0.1: ' . $conf);
    assertTrue(!str_contains(withoutComments($conf), '<VirtualHost *:'), 'ห้ามฟังทุกหน้าตัดเน็ต: ' . $conf);
});

test('ports.conf ที่เขียนให้ Apache ต้องไม่มี Listen 80 หรือ 443 หลงเหลือ', static function (): void {
    $files = proxyDriver()->vhostFiles(proxySite(), proxyExecutor());
    $ports = $files['/etc/apache2/ports.conf'] ?? '';

    assertTrue($ports !== '', 'ต้องเขียน ports.conf ของชั้นหลังด้วย');
    assertTrue(str_contains($ports, 'Listen 127.0.0.1:8080'), 'ต้องฟังที่ loopback: ' . $ports);

    foreach (['Listen 80', 'Listen 443'] as $public) {
        assertTrue(
            !str_contains(withoutComments($ports), $public),
            "เหลือ {$public} ไว้ = nginx จะจองพอร์ตไม่ได้ และมีทางลัดข้าม TLS: " . $ports,
        );
    }
});

// --- 3. ที่อยู่ผู้ใช้จริงต้องไปถึงชั้นหลัง ---------------------------------------

test('ชั้นหน้าส่ง X-Forwarded-For และชั้นหลังเชื่อเฉพาะ loopback', static function (): void {
    $driver = proxyDriver();
    $front = $driver->renderVhost(proxySite(), proxyExecutor());
    $back = $driver->renderBackendVhost(proxySite(), proxyExecutor());

    assertTrue(str_contains($front, 'X-Forwarded-For'), 'ชั้นหน้าต้องส่งที่อยู่ผู้ใช้ต่อไป: ' . $front);
    assertTrue(str_contains($back, 'RemoteIPHeader X-Forwarded-For'), 'ชั้นหลังต้องอ่านค่านั้น: ' . $back);

    // เชื่อกว้างกว่า loopback = ใครก็ปลอมที่อยู่ตัวเองเพื่อเลี่ยงการแบนได้
    assertTrue(str_contains($back, 'RemoteIPTrustedProxy 127.0.0.1'), 'ต้องเชื่อเฉพาะ loopback: ' . $back);
    assertTrue(!str_contains(withoutComments($back), 'RemoteIPInternalProxy'), 'ห้ามใช้ InternalProxy ที่เชื่อทั้งวงในเครือข่าย: ' . $back);
});

test('ชั้นหลังต้องรู้ว่าคำขอเดิมมาทาง https', static function (): void {
    $driver = proxyDriver();

    assertTrue(
        str_contains($driver->renderBackendVhost(proxySite(), proxyExecutor()), 'X-Forwarded-Proto'),
        'ไม่มีค่านี้ PHP จะเห็นเป็น http เสมอแล้ว CMS สร้างลิงก์ผิดทั้งเว็บ',
    );

    $ssl = $driver->renderVhost(proxySite('forced'), proxyExecutor());
    assertTrue(
        str_contains($ssl, 'proxy_set_header X-Forwarded-Proto https'),
        'บล็อก https ต้องบอกชั้นหลังว่าเป็น https: ' . $ssl,
    );
});

// --- 4. บังคับ HTTPS ต้องทำชั้นเดียว -------------------------------------------

test('บังคับ HTTPS: ชั้นหน้า redirect ส่วนชั้นหลังต้องไม่ redirect ซ้ำ', static function (): void {
    $driver = proxyDriver();
    $front = $driver->renderVhost(proxySite('forced'), proxyExecutor());
    $back = $driver->renderBackendVhost(proxySite('forced'), proxyExecutor());

    assertTrue(str_contains($front, 'return 301 https://$host$request_uri'), 'ชั้นหน้าต้อง redirect: ' . $front);

    // ชั้นหลังเห็นคำขอเป็น http เสมอ ถ้ามันก็ redirect ด้วยจะวนไม่จบ
    assertTrue(!str_contains(withoutComments($back), 'RewriteRule ^(.*)$ https://'), 'ชั้นหลังต้องไม่ redirect ซ้ำ: ' . $back);
});

test('เส้นทาง acme ต้องเข้าถึงทาง HTTP ได้แม้ตอนบังคับ HTTPS', static function (): void {
    $conf = proxyDriver()->renderVhost(proxySite('forced'), proxyExecutor());

    $acmePos = strpos($conf, '/.well-known/acme-challenge/');
    $redirectPos = strpos($conf, 'return 301');

    assertTrue($acmePos !== false, 'ต้องมีเส้นทาง acme: ' . $conf);
    assertTrue($redirectPos !== false, 'ต้องมี redirect');
    assertTrue($acmePos < $redirectPos, 'บล็อก acme ต้องมาก่อน redirect ไม่งั้นต่ออายุใบรับรองล้ม');
});

test('HSTS ใส่เฉพาะตอนบังคับ HTTPS เท่านั้น', static function (): void {
    $driver = proxyDriver();

    assertTrue(
        str_contains($driver->renderVhost(proxySite('forced'), proxyExecutor()), 'Strict-Transport-Security'),
        'โหมดบังคับต้องมี HSTS',
    );
    assertTrue(
        !str_contains($driver->renderVhost(proxySite('on'), proxyExecutor()), 'Strict-Transport-Security'),
        'โหมดเปิดใช้งานเฉย ๆ ต้องไม่มี HSTS — ปิด SSL ทีหลังแล้วผู้ใช้เดิมจะเข้าเว็บไม่ได้เลย',
    );
});

// --- 5. ไฟล์ที่ต้องเขียนและต้องลบ ----------------------------------------------

test('หนึ่งเว็บต้องเขียนสองไฟล์ — ขาดชั้นใดชั้นหนึ่งได้ 502 ทุกคำขอ', static function (): void {
    $driver = proxyDriver();
    $files = $driver->vhostFiles(proxySite(), proxyExecutor());

    assertTrue(isset($files['/etc/nginx/conf.d/phpcp-example.test.conf']), 'ต้องมี vhost ของ nginx');
    assertTrue(isset($files['/etc/apache2/sites-enabled/phpcp-example.test.conf']), 'ต้องมี vhost ของ Apache');
});

test('ลบเว็บต้องลบ vhost ทั้งสองชั้น แต่ห้ามลบไฟล์กลางของทั้งเครื่อง', static function (): void {
    $paths = proxyDriver()->vhostPaths(proxySite());

    assertSame(2, count($paths), 'ต้องลบสองไฟล์: ' . implode(', ', $paths));
    assertTrue(in_array('/etc/nginx/conf.d/phpcp-example.test.conf', $paths, true), 'ต้องลบ vhost ของ nginx');
    assertTrue(in_array('/etc/apache2/sites-enabled/phpcp-example.test.conf', $paths, true), 'ต้องลบ vhost ของ Apache');

    foreach ($paths as $path) {
        assertTrue(
            !str_contains($path, 'ports.conf') && !str_contains($path, '00-phpcp-proxy'),
            'ลบเว็บเดียวต้องไม่ลบไฟล์ที่ทั้งเครื่องใช้ร่วมกัน: ' . $path,
        );
    }
});

test('vhost ของ wildcard ต้องถูกอ่านท้ายสุดในชั้น Apache', static function (): void {
    $driver = proxyDriver();
    $wildcard = proxySite('off', ['*.example.test']);

    assertTrue(
        str_contains($driver->backendVhostPath($wildcard), 'zz-wildcard-'),
        'ไม่งั้น wildcard จะกลืน vhost ที่ระบุชื่อเต็มของลูกค้าอีกราย',
    );
    assertTrue(!str_contains($driver->backendVhostPath($wildcard), '*'), 'ชื่อไฟล์ต้องไม่มี * ปนเข้าไป');
});

// --- 6. เว็บที่ถูกระงับ ---------------------------------------------------------

test('เว็บที่ถูกระงับต้องไม่รัน PHP ที่ชั้นหลัง', static function (): void {
    $conf = proxyDriver()->renderBackendVhost(proxySite('off', [], 'suspended'), proxyExecutor());

    assertTrue(str_contains($conf, '503'), 'ต้องตอบ 503: ' . $conf);
    assertTrue(!str_contains(withoutComments($conf), 'proxy:unix:'), 'ต้องไม่ส่งอะไรเข้า FPM เลย: ' . $conf);
});

// --- 7. กฎกันไฟล์ลับต้องเท่าเดิมทุกโหมด ----------------------------------------

test('กฎกันไฟล์ลับต้องยังอยู่ครบเหมือนโหมด apache ล้วน', static function (): void {
    $conf = proxyDriver()->renderBackendVhost(proxySite(), proxyExecutor());

    foreach (['composer\.(json|lock)', '\.env', '\.git'] as $pattern) {
        assertTrue(str_contains($conf, $pattern), "ต้องยังกัน {$pattern} อยู่: " . $conf);
    }
});
