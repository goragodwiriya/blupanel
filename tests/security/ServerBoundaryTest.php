<?php

declare(strict_types=1);

/**
 * ขอบเขตระหว่างชั้น Hosting กับชั้น Server — PROMPT.md "Important UX Rule"
 *
 * เกณฑ์รับงานเฟส 1 ระบุว่า "ผู้ดูแลเว็บไซต์เปิด URL ของหน้า SERVER ได้ 403 ทุกหน้า"
 * เทสต์ชุดนี้พิสูจน์ที่ระดับตารางสิทธิ์ ไม่ต้องยิง HTTP จริง จึงรันใน CI ได้
 */

use Phpcp\Agent\CapabilityRegistry;
use Phpcp\Domain\LogCatalog;
use Phpcp\Domain\ServiceCatalog;
use Phpcp\Kernel\Routes;
use Phpcp\Security\Permissions;

group('ServerBoundary — ชั้น Server ต้องปิดสนิทจากผู้ดูแลเว็บไซต์');

/**
 * เส้นทางที่ถือว่าอยู่ในชั้น Server
 *
 * เดิมคัดด้วยคำนำหน้า `/server` ของ UI แบบ HTML · เมื่อเหลือแต่ REST API แล้ว
 * ต้อง **ระบุรายการเอง** ไม่ใช่คัดจาก permission ที่เส้นทางประกาศ — ไม่งั้นเทสต์จะ
 * ถามว่า "เส้นทางที่ใช้สิทธิ์หมวด SERVER ใช้สิทธิ์หมวด SERVER หรือเปล่า" ซึ่งจริงเสมอ
 * และจับไม่ได้เลยตอนมีคนเผลอผูก `/api/v2/firewall/...` เข้ากับ `site.view`
 */
const SERVER_PATH_PREFIXES = [
    '/api/v2/services',
    '/api/v2/firewall',
    '/api/v2/ssh-config',
    '/api/v2/logs',
    '/api/v2/security',
    '/api/v2/system/info',
    '/api/v2/settings',
    '/api/v2/users',
    '/api/v2/rollbacks',
    '/api/v2/scheduled-jobs',
    '/api/v2/backup-destinations',
    '/api/v2/backup-schedules',
];

function serverRoutes(): array
{
    return array_values(array_filter(
        Routes::build()->routes(),
        static function ($route): bool {
            foreach (SERVER_PATH_PREFIXES as $prefix) {
                if (str_starts_with($route->path, $prefix)) {
                    return true;
                }
            }

            return false;
        },
    ));
}

test('ทุกเส้นทาง /server ต้องมี permission และผู้ดูแลเว็บไซต์ต้องไม่มีสิทธิ์นั้น', static function (): void {
    $routes = serverRoutes();
    assertTrue(count($routes) > 20, 'ต้องมีเส้นทางในหมวด SERVER ให้ตรวจ');

    foreach ($routes as $route) {
        assertTrue(
            $route->permission !== null,
            "เส้นทาง {$route->method} {$route->path} ต้องประกาศ permission",
        );

        assertTrue(
            !Permissions::roleHas(Permissions::WEBADMIN, $route->permission),
            "ผู้ดูแลเว็บไซต์ต้องเข้า {$route->method} {$route->path} ไม่ได้ (permission: {$route->permission})",
        );
    }
});

test('ผู้ดูแลเซิร์ฟเวอร์เข้าหน้า Server ได้ทุกหน้าที่เป็นการอ่าน', static function (): void {
    foreach (serverRoutes() as $route) {
        if ($route->method !== 'GET') {
            continue;
        }

        assertTrue(
            Permissions::roleHas(Permissions::SYSADMIN, $route->permission),
            "ผู้ดูแลเซิร์ฟเวอร์ต้องเข้า {$route->path} ได้",
        );
    }
});

test('การสั่งงานบริการต้องใช้ service.control ไม่ใช่แค่ service.view', static function (): void {
    $registry = new CapabilityRegistry();

    foreach (['service.start', 'service.stop', 'service.restart', 'service.reload'] as $name) {
        $capability = $registry->resolve($name);

        assertSame('service.control', $capability->permission(), "{$name} ต้องใช้ service.control");
        assertTrue($capability->isMutating(), "{$name} ต้องถูกทำเครื่องหมายว่าเปลี่ยนแปลงระบบ เพื่อให้เข้า audit และโหมด dryrun");
        assertTrue(
            !Permissions::roleHas(Permissions::WEBADMIN, $capability->permission()),
            "ผู้ดูแลเว็บไซต์ต้องสั่ง {$name} ไม่ได้",
        );
    }

    // อ่านสถานะเป็นคนละสิทธิ์ และต้องไม่ถูกทำเครื่องหมายว่าเปลี่ยนแปลงระบบ
    $status = $registry->resolve('service.status');
    assertSame('service.view', $status->permission(), 'service.status ต้องใช้ service.view');
    assertTrue(!$status->isMutating(), 'service.status ต้องเป็นการอ่านอย่างเดียว');
});

test('แหล่ง log ทั้งหมดอยู่ในรายการที่กำหนดไว้ล่วงหน้า', static function (): void {
    foreach (LogCatalog::all() as $key => $source) {
        assertTrue(
            preg_match('/^[a-z][a-z0-9_]{1,30}$/', $key) === 1,
            "คีย์แหล่ง log ต้องเป็นรูปแบบที่ปลอดภัย: {$key}",
        );

        assertTrue(str_starts_with($source['path'], '/'), "เส้นทาง log ต้องเป็น absolute: {$key}");
        assertTrue(!str_contains($source['path'], '..'), "เส้นทาง log ต้องไม่มี .. : {$key}");

        assertTrue(
            array_key_exists($source['permission'], Permissions::all()),
            "แหล่ง log {$key} อ้าง permission ที่ไม่มีอยู่จริง",
        );
    }
});

test('ผู้ดูแลเว็บไซต์อ่าน log ไม่ได้เลย', static function (): void {
    assertSame([], LogCatalog::forRole(Permissions::WEBADMIN), 'ผู้ดูแลเว็บไซต์ต้องไม่เห็นแหล่ง log ใดเลย');
});

test('audit log ต้องใช้สิทธิ์สูงกว่า log ทั่วไป', static function (): void {
    $audit = LogCatalog::get('audit');

    assertSame('audit.view', $audit['permission'], 'audit log ต้องใช้ audit.view');
    assertTrue(
        !Permissions::roleHas(Permissions::WEBADMIN, 'audit.view'),
        'ผู้ดูแลเว็บไซต์ต้องอ่าน audit log ไม่ได้',
    );
});

test('LogTail ปฏิเสธการระบุเส้นทางไฟล์เอง', static function (): void {
    $capability = (new CapabilityRegistry())->resolve('system.logs_tail');

    $attempts = [
        '/etc/passwd',
        '../../etc/shadow',
        '/var/lib/phpcp/panel.db',
        'access/../../../etc/passwd',
        '/etc/phpcp/config.php',
    ];

    foreach ($attempts as $attempt) {
        assertRejects(
            \Phpcp\Agent\ValidationError::class,
            static fn () => $capability->validate(['source' => $attempt]),
            "ต้องปฏิเสธการระบุเส้นทางเอง: {$attempt}",
        );
    }

    // คีย์ที่ถูกต้องต้องผ่าน
    $clean = $capability->validate(['source' => 'access', 'lines' => 100]);
    assertSame('access', $clean['source'], 'คีย์ที่ถูกต้องต้องผ่าน validate');
});

test('ServiceCatalog ไม่มี unit ที่ชื่อผิดรูปแบบ', static function (): void {
    foreach (ServiceCatalog::units() as $unit) {
        assertTrue(
            preg_match('/^[a-z][a-z0-9.\-]{1,62}$/', $unit) === 1,
            "ชื่อ unit ต้องเป็นรูปแบบที่ปลอดภัย: {$unit}",
        );
    }
});
