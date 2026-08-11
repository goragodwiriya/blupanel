<?php

declare(strict_types=1);

/**
 * Firewall — เกณฑ์รับงานเฟส 4
 *
 * ความเสี่ยงเดียวที่สำคัญจริงบนหน้านี้คือ "ปิดประตูขังตัวเอง" ผู้ดูแลที่ตั้งกฎผิด
 * แล้วเข้าเครื่องไม่ได้อีกคือความเสียหายที่กู้ผ่านหน้าเว็บไม่ได้เลย
 * เทสต์ชุดนี้จึงเน้นสองเรื่อง: กฎที่เป็นเส้นชีวิตต้องแตะไม่ได้
 * และค่าที่ป้อนเข้าคำสั่ง ufw ต้องไม่มีทางกลายเป็นอย่างอื่นได้
 */

use Phpcp\Agent\Capability\FirewallRuleAdd;
use Phpcp\Agent\Capability\FirewallRuleDelete;
use Phpcp\Agent\CapabilityRegistry;
use Phpcp\Agent\ValidationError;
use Phpcp\Driver\Firewall\UfwDriver;

group('Firewall — ห้ามปิดประตูขังตัวเอง');

test('พอร์ตนอกช่วงถูกปฏิเสธ รวมถึงพอร์ต 0 ที่เป็นค่า falsy ของ PHP', static function (): void {
    // เคยเป็นบั๊กจริง: array_filter ทิ้ง '0' เพราะเป็น falsy การตรวจช่วงจึงถูกข้ามไปเงียบ ๆ
    foreach (['0', '0:100', '65536', '70000', '99999'] as $port) {
        assertRejects(
            ValidationError::class,
            static fn () => UfwDriver::assertPort($port),
            "พอร์ต {$port} ต้องถูกปฏิเสธ",
        );
    }

    foreach (['1', '22', '65535', '6000:6010'] as $port) {
        assertSame($port, UfwDriver::assertPort($port), "พอร์ต {$port} ต้องผ่าน");
    }
});

test('ช่วงพอร์ตต้องเรียงจากน้อยไปมาก', static function (): void {
    assertRejects(
        ValidationError::class,
        static fn () => UfwDriver::assertPort('900:80'),
        'ช่วงกลับหัวต้องถูกปฏิเสธ',
    );

    assertRejects(
        ValidationError::class,
        static fn () => UfwDriver::assertPort('80:80'),
        'ช่วงที่ต้นกับปลายเท่ากันต้องถูกปฏิเสธ เพราะควรเขียนเป็นพอร์ตเดี่ยว',
    );
});

test('ค่าที่มีอักขระของ shell ปนต้องไม่หลุดเข้าไปเป็น argument', static function (): void {
    $payloads = [
        '80; rm -rf /',
        '80 && ufw --force disable',
        '80|nc',
        "80\n--force",
        '80`id`',
        '$(id)',
        '80 --force delete allow 22/tcp',
    ];

    foreach ($payloads as $payload) {
        assertRejects(
            ValidationError::class,
            static fn () => UfwDriver::assertPort($payload),
            'พอร์ตที่มีอักขระพิเศษต้องถูกปฏิเสธ: ' . $payload,
        );

        assertRejects(
            ValidationError::class,
            static fn () => UfwDriver::assertSource($payload),
            'ต้นทางที่มีอักขระพิเศษต้องถูกปฏิเสธ: ' . $payload,
        );
    }
});

test('ต้นทางรับเฉพาะ IP และ CIDR ที่ถูกต้อง', static function (): void {
    foreach (['203.0.113.5', '10.0.0.0/8', '2001:db8::1', '2001:db8::/32'] as $source) {
        assertSame($source, UfwDriver::assertSource($source), "ต้นทาง {$source} ต้องผ่าน");
    }

    // ว่างและ any แปลว่า "ทุกที่" ทั้งคู่ ต้องกลายเป็นค่าว่างเหมือนกัน
    assertSame('', UfwDriver::assertSource(''), 'ค่าว่างหมายถึงทุกที่');
    assertSame('', UfwDriver::assertSource('any'), 'any หมายถึงทุกที่');

    foreach (['evil.example.com', '10.0.0.0/64', '999.1.1.1', '10.0.0.0/abc', '../../etc'] as $source) {
        assertRejects(
            ValidationError::class,
            static fn () => UfwDriver::assertSource($source),
            "ต้นทาง {$source} ต้องถูกปฏิเสธ",
        );
    }
});

test('การกระทำมีแค่ allow กับ deny — ไม่เปิดทางให้คำสั่งย่อยอื่นของ ufw', static function (): void {
    foreach (['allow', 'deny'] as $action) {
        assertSame($action, UfwDriver::assertAction($action), "{$action} ต้องผ่าน");
    }

    // 'delete', 'reset', 'disable' เป็นคำสั่งย่อยจริงของ ufw ที่ทำลายค่าตั้งได้
    // ถ้าหลุดเข้าไปตรงตำแหน่ง action ก็คือการยึดคำสั่งทั้งคำสั่ง
    foreach (['delete', 'reset', 'disable', 'reject', 'limit', 'ALLOW', ''] as $action) {
        assertRejects(
            ValidationError::class,
            static fn () => UfwDriver::assertAction($action),
            "การกระทำ '{$action}' ต้องถูกปฏิเสธ",
        );
    }
});

test('ช่วงพอร์ตที่คร่อมพอร์ตของ panel ต้องถูกจับได้ ไม่ใช่แค่เทียบเลขตรง ๆ', static function (): void {
    // กฎอย่าง deny 8000:9000 ปิดพอร์ต 8443 ไปด้วยทั้งที่ไม่ได้เอ่ยถึงตัวเลขนั้นเลย
    $covers = new ReflectionMethod(FirewallRuleAdd::class, 'covers');
    $capability = new FirewallRuleAdd();

    assertTrue($covers->invoke($capability, '8443', '8443'), 'พอร์ตตรงกันต้องนับว่าครอบคลุม');
    assertTrue($covers->invoke($capability, '8000:9000', '8443'), 'ช่วงที่คร่อมต้องนับว่าครอบคลุม');
    assertTrue($covers->invoke($capability, '22:22', '22'), 'ช่วงที่มีสมาชิกเดียวต้องนับว่าครอบคลุม');
    assertTrue(!$covers->invoke($capability, '8000:8400', '8443'), 'ช่วงที่ไม่ถึงต้องไม่นับ');
    assertTrue(!$covers->invoke($capability, '80', '8443'), 'พอร์ตคนละตัวต้องไม่นับ');
});

test('capability ของ firewall ทุกตัวต้องใช้ permission ของหมวด Server', static function (): void {
    $registry = new CapabilityRegistry();

    foreach ($registry->describe() as $name => $meta) {
        if (!str_starts_with($name, 'firewall.')) {
            continue;
        }

        assertTrue(
            in_array($meta['permission'], ['firewall.view', 'firewall.manage'], true),
            "{$name} ต้องใช้ permission ของ firewall ไม่ใช่ {$meta['permission']}",
        );

        // ผู้ดูแลเว็บไซต์ต้องไม่มีสิทธิ์นี้เลย — firewall เป็นของทั้งเครื่อง ไม่ใช่ของเว็บไซต์ใดเว็บไซต์หนึ่ง
        assertTrue(
            !\Phpcp\Security\Permissions::roleHas('webadmin', $meta['permission']),
            "ผู้ดูแลเว็บไซต์ต้องไม่มีสิทธิ์ {$meta['permission']}",
        );
    }
});

test('ลายเซ็นของกฎต้องเปลี่ยนเมื่อกฎเปลี่ยน — ใช้กันการลบผิดตัว', static function (): void {
    // หมายเลขกฎของ ufw เลื่อนทุกครั้งที่มีการลบ ถ้าเชื่อหมายเลขที่หน้าจอจำไว้อย่างเดียว
    // ก็มีโอกาสลบกฎที่เลื่อนมาแทนที่แทนกฎที่ผู้ใช้เห็น
    $base = ['action' => 'ALLOW', 'target' => '80/tcp', 'source' => 'Anywhere'];

    $signature = FirewallRuleDelete::signature($base);

    foreach ([
        ['action' => 'DENY'],
        ['target' => '8080/tcp'],
        ['target' => '80/udp'],
        ['source' => '10.0.0.0/8'],
    ] as $change) {
        assertTrue(
            FirewallRuleDelete::signature($change + $base) !== $signature,
            'ลายเซ็นต้องเปลี่ยนเมื่อ ' . implode(',', array_keys($change)) . ' เปลี่ยน',
        );
    }
});

test('การเปลี่ยนแปลงที่ทำให้เข้าถึงได้แคบลงต้องมีกลไกคืนค่า', static function (): void {
    // สัญญาของหน้านี้: ลบกฎและเปิด firewall = ยืนยันภายในเวลาเสมอ
    // ส่วนเพิ่มกฎ allow และปิด firewall = ไม่ต้อง เพราะเปิดกว้างขึ้น ไม่ใช่แคบลง
    $source = file_get_contents(PHPCP_ROOT . '/src/Agent/Capability/FirewallRuleDelete.php');
    assertTrue(str_contains((string) $source, '->arm('), 'firewall.rule_delete ต้องเรียก RollbackGuard::arm()');

    $source = file_get_contents(PHPCP_ROOT . '/src/Agent/Capability/FirewallEnable.php');
    assertTrue(str_contains((string) $source, '->arm('), 'firewall.enable ต้องเรียก RollbackGuard::arm()');

    $source = file_get_contents(PHPCP_ROOT . '/src/Agent/Capability/FirewallDisable.php');
    assertTrue(!str_contains((string) $source, '->arm('), 'firewall.disable ไม่ต้องมีการนับถอยหลัง');
});

test('คำสั่งย้อนกลับของ firewall ต้องตรวจค่าซ้ำ ไม่เชื่อสิ่งที่อ่านจากฐานข้อมูล', static function (): void {
    // payload ของ pending_rollbacks ถูกอ่านกลับมาแล้วแปลงเป็นคำสั่ง ถ้าไม่ตรวจซ้ำ
    // คนที่แก้แถวในฐานข้อมูลได้ก็สั่ง ufw อะไรก็ได้ผ่านทางนี้
    // applyUndo ไม่แตะฐานข้อมูลเลย จึงสร้าง guard โดยข้าม constructor ได้
    $guard = (new ReflectionClass(\Phpcp\Driver\RollbackGuard::class))->newInstanceWithoutConstructor();
    $apply = new ReflectionMethod(\Phpcp\Driver\RollbackGuard::class, 'applyUndo');
    $executor = new \Phpcp\Agent\Executor\DryRunExecutor();

    assertRejects(
        ValidationError::class,
        static fn () => $apply->invoke($guard, $executor, ['type' => 'ufw.reset']),
        'คำสั่งย้อนกลับชนิดที่ไม่รู้จักต้องถูกปฏิเสธ',
    );

    assertRejects(
        ValidationError::class,
        static fn () => $apply->invoke($guard, $executor, [
            'type' => 'ufw.rule_add',
            'action' => 'delete',
            'port' => '22',
            'protocol' => 'tcp',
            'source' => '',
        ]),
        'action ที่ถูกแก้ในฐานข้อมูลต้องถูกปฏิเสธตอนย้อนกลับ',
    );

    assertRejects(
        ValidationError::class,
        static fn () => $apply->invoke($guard, $executor, [
            'type' => 'ufw.rule_remove',
            'action' => 'allow',
            'port' => '22; reboot',
            'protocol' => 'tcp',
            'source' => '',
        ]),
        'พอร์ตที่ถูกแก้ในฐานข้อมูลต้องถูกปฏิเสธตอนย้อนกลับ',
    );
});
