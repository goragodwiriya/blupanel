<?php

declare(strict_types=1);

/**
 * panel ต้องจัดการตัวเองไม่ได้ — ARCHITECTURE §5.3
 *
 * นี่คือเทสต์ที่กันไม่ให้ผู้ใช้ล็อกตัวเองออกจากระบบอย่างถาวร
 * ถ้าเทสต์กลุ่มนี้ล้ม แปลว่ามีทางสั่งหยุดบริการของ panel ผ่าน UI ได้ ซึ่งกู้คืนไม่ได้จากระยะไกล
 */

use Phpcp\Agent\CapabilityRegistry;
use Phpcp\Agent\ProtectedResource;
use Phpcp\Agent\SelfProtection;
use Phpcp\Domain\ServiceCatalog;

group('SelfProtection — panel ต้องแตะตัวเองไม่ได้');

test('systemd unit ของ panel ถูกปฏิเสธ', static function (): void {
    $units = ['phpcp-agentd', 'phpcp-web', 'phpcp-fpm'];

    foreach ($units as $unit) {
        foreach ([$unit, $unit . '.service'] as $variant) {
            assertTrue(SelfProtection::isProtectedUnit($variant), "{$variant} ต้องถูกถือว่าเป็นทรัพยากรของ panel");

            assertRejects(
                ProtectedResource::class,
                static fn () => SelfProtection::assertUnit($variant),
                "ต้องปฏิเสธการจัดการ {$variant}",
            );
        }
    }
});

test('unit ของระบบที่บริหารได้ต้องไม่ถูกบล็อก', static function (): void {
    foreach (['apache2', 'nginx', 'mariadb', 'cron', 'php8.4-fpm'] as $unit) {
        assertTrue(!SelfProtection::isProtectedUnit($unit), "{$unit} ต้องจัดการได้ตามปกติ");
    }
});

test('ServiceCatalog ไม่มีบริการของ panel อยู่ในรายการ', static function (): void {
    foreach (ServiceCatalog::units() as $unit) {
        assertTrue(
            !SelfProtection::isProtectedUnit($unit),
            "ServiceCatalog ต้องไม่มี {$unit} ซึ่งเป็นบริการของ panel เอง",
        );
    }

    // บริการของ panel ต้องไม่ปรากฏในหน้า Services เลย ไม่ใช่แค่ซ่อนปุ่ม
    foreach (SelfProtection::protectedUnits() as $unit) {
        assertTrue(!ServiceCatalog::isAllowed($unit), "{$unit} ต้องไม่อยู่ในรายการที่จัดการได้");
    }
});

test('เส้นทางไฟล์ของ panel ถูกปฏิเสธ', static function (): void {
    $protected = [
        '/etc/phpcp',
        '/etc/phpcp/config.php',
        '/etc/phpcp/tls/panel.key',
        '/var/lib/phpcp',
        '/var/lib/phpcp/panel.db',
        '/usr/share/phpcp/src/Agent/Server.php',
        '/run/phpcp/agent.sock',
    ];

    foreach ($protected as $path) {
        assertTrue(SelfProtection::isProtectedPath($path), "{$path} ต้องถูกป้องกัน");

        assertRejects(
            ProtectedResource::class,
            static fn () => SelfProtection::assertPath($path),
            "ต้องปฏิเสธการแก้ไข {$path}",
        );
    }
});

test('เส้นทางที่มีชื่อคล้ายกันต้องไม่ถูกบล็อกผิด', static function (): void {
    // /etc/phpcp-backup ไม่ใช่ /etc/phpcp — การเทียบต้องดูขอบเขตของเส้นทาง ไม่ใช่แค่ str_starts_with
    foreach (['/etc/phpcp-backup', '/var/lib/phpcp2', '/srv/phpcp/sites/example.com'] as $path) {
        assertTrue(!SelfProtection::isProtectedPath($path), "{$path} ต้องไม่ถูกบล็อก");
    }
});

test('ไฟล์ของเว็บไซต์ที่โฮสต์ต้องจัดการได้', static function (): void {
    foreach (['/srv/phpcp/sites/example.com/public/index.php', '/srv/phpcp/sites/shop.com'] as $path) {
        assertTrue(!SelfProtection::isProtectedPath($path), "{$path} ต้องแก้ไขได้");
    }
});

test('ผู้ใช้ระบบที่สำคัญถูกปฏิเสธ', static function (): void {
    foreach (['root', 'phpcp-web', 'www-data'] as $user) {
        assertRejects(
            ProtectedResource::class,
            static fn () => SelfProtection::assertUser($user),
            "ต้องปฏิเสธการจัดการผู้ใช้ {$user}",
        );
    }

    foreach (['web_10', 'web_25'] as $user) {
        assertTrue(!SelfProtection::isProtectedUser($user), "{$user} ต้องจัดการได้");
    }
});

test('capability ที่รับชื่อ service ต้องเรียก SelfProtection', static function (): void {
    $registry = new CapabilityRegistry();
    $checked = 0;

    foreach ($registry->names() as $name) {
        $capability = $registry->resolve($name);

        // ตรวจก่อนว่า capability นี้ "รับ" ชื่อ service เข้าไปจริงหรือไม่
        // capability อย่าง system.metrics ทิ้ง argument ทุกตัวโดยเจตนา จึงไม่มีอะไรให้ป้องกัน
        try {
            $clean = $capability->validate(['services' => ['apache2'], 'service' => 'apache2']);
        } catch (\Phpcp\Agent\AgentException) {
            continue;
        }

        if (!str_contains(json_encode($clean, JSON_UNESCAPED_UNICODE) ?: '', 'apache2')) {
            continue;   // ไม่ได้รับชื่อ service เข้าไป ข้าม
        }

        $checked++;

        foreach (SelfProtection::protectedUnits() as $unit) {
            assertRejects(
                \Phpcp\Agent\AgentException::class,
                static fn () => $capability->validate(['services' => [$unit], 'service' => $unit]),
                "{$name} ต้องปฏิเสธ unit ของ panel: {$unit}",
            );
        }
    }

    assertTrue($checked > 0, 'ต้องมี capability ที่รับชื่อ service อย่างน้อยหนึ่งตัวให้ตรวจ');
});

test('capability ที่ไม่รับ argument ต้องทิ้งค่าที่ส่งมาทั้งหมด', static function (): void {
    // system.metrics รับ argument ไม่ได้เลย — ค่าที่ส่งมาต้องหายไปหมด ไม่ใช่ถูกส่งต่อเข้าไปข้างใน
    $capability = (new CapabilityRegistry())->resolve('system.metrics');

    $clean = $capability->validate([
        'services' => ['phpcp-agentd'],
        'path' => '/etc/phpcp/config.php',
        'cmd' => 'rm -rf /',
    ]);

    assertSame([], $clean, 'system.metrics ต้องคืน array ว่าง ไม่ส่งต่อค่าใดเข้าไป');
});

test('filterUnits ตัดบริการของ panel ออกจากรายการ', static function (): void {
    $input = ['apache2', 'phpcp-agentd', 'mariadb', 'phpcp-web', 'cron'];
    $output = SelfProtection::filterUnits($input);

    assertSame(['apache2', 'mariadb', 'cron'], $output, 'ต้องเหลือเฉพาะบริการของระบบที่บริหารได้');
});
