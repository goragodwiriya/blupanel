<?php

declare(strict_types=1);

/**
 * กลไกคืนค่าอัตโนมัติและการสำรอง/กู้คืน — เกณฑ์รับงานเฟส 4
 *
 * เทสต์ชุดนี้ตรวจคุณสมบัติที่ "ถ้าพังแล้วกู้ไม่ได้" ทั้งหมด:
 * ชื่อไฟล์สำรองต้องไม่ชนกัน · checksum ต้องกันไฟล์ที่ถูกแก้ · ค่าตั้ง SSH ต้องคืนได้
 */

use Phpcp\Agent\CapabilityRegistry;
use Phpcp\Agent\ValidationError;
use Phpcp\Driver\BackupManager;
use Phpcp\Driver\RollbackGuard;
use Phpcp\Driver\SshManager;

group('Rollback + Backup — คุณสมบัติที่พังแล้วกู้ไม่ได้');

test('ชื่อไฟล์สำรองต้องไม่ซ้ำแม้สร้างในวินาทีเดียวกัน', static function (): void {
    // เคยเป็นบั๊กจริง: ไฟล์สำรองนิรภัยที่สร้างตอนกู้คืนเขียนทับไฟล์ต้นฉบับ
    // เพราะทั้งคู่ได้ชื่อจากเวลาระดับวินาทีเดียวกัน ทำให้กู้ "สถานะที่พังแล้ว" กลับมา
    $method = new ReflectionMethod(BackupManager::class, 'pathFor');
    $manager = new BackupManager();

    $names = [];
    for ($i = 0; $i < 50; $i++) {
        $names[] = $method->invoke($manager, 'site-example.test', 'tar.gz');
    }

    assertSame(50, count(array_unique($names)), 'ชื่อไฟล์สำรอง 50 ครั้งติดกันต้องไม่ซ้ำกันเลย');
});

test('checksum ว่างต้องถูกปฏิเสธ ไม่ใช่ข้ามการตรวจ', static function (): void {
    $executor = new \Phpcp\Agent\Executor\SandboxExecutor(sys_get_temp_dir() . '/phpcp-bk-' . getmypid());
    $manager = new BackupManager();

    $path = '/var/lib/phpcp/backups/probe.tar.gz';
    $executor->writeFile($executor->path($path), 'ข้อมูลจำลอง');

    assertRejects(
        ValidationError::class,
        static fn () => $manager->assertIntact($executor, $path, ''),
        'ไฟล์สำรองที่ไม่มี checksum ต้องกู้คืนไม่ได้',
    );

    assertRejects(
        ValidationError::class,
        static fn () => $manager->assertIntact($executor, $path, str_repeat('0', 64)),
        'checksum ที่ไม่ตรงต้องถูกปฏิเสธ',
    );

    // checksum ที่ถูกต้องต้องผ่าน
    $manager->assertIntact($executor, $path, hash_file('sha256', $executor->path($path)));
    TestRunner::$assertions++;

    @unlink($executor->path($path));
});

test('ลบไฟล์สำรองนอกไดเรกทอรีที่กำหนดไม่ได้', static function (): void {
    $executor = new \Phpcp\Agent\Executor\SandboxExecutor(sys_get_temp_dir() . '/phpcp-bk2-' . getmypid());
    $manager = new BackupManager();

    foreach (['/etc/passwd', '/etc/shadow', '/var/lib/phpcp/panel.db', '/var/lib/phpcp/backups/../panel.db'] as $path) {
        assertRejects(
            ValidationError::class,
            static fn () => $manager->delete($executor, $path),
            "ต้องปฏิเสธการลบ {$path}",
        );
    }
});

test('ค่าตั้ง SSH รับเฉพาะคีย์และค่าที่กำหนดไว้', static function (): void {
    foreach (['PermitRootLogin' => 'maybe', 'PasswordAuthentication' => 'sometimes',
        'PubkeyAuthentication' => '1', 'PermitEmptyPasswords' => 'true'] as $key => $bad) {
        assertRejects(
            ValidationError::class,
            static fn () => SshManager::assertValue($key, $bad),
            "ต้องปฏิเสธค่า {$bad} ของ {$key}",
        );
    }

    foreach (['0', '65536', '99999', 'abc', '22; rm -rf /', '-1'] as $bad) {
        assertRejects(
            ValidationError::class,
            static fn () => SshManager::assertValue('Port', $bad),
            "ต้องปฏิเสธพอร์ต {$bad}",
        );
    }

    assertSame('2222', SshManager::assertValue('Port', '2222'), 'พอร์ตที่ถูกต้องต้องผ่าน');
    assertSame('no', SshManager::assertValue('PermitRootLogin', 'no'), 'ค่าที่อยู่ใน enum ต้องผ่าน');
});

test('คีย์ที่ไม่ได้อยู่ในรายการแก้ไม่ได้', static function (): void {
    foreach (['AllowUsers', 'Subsystem', 'Match', 'AuthorizedKeysFile', 'ListenAddress'] as $key) {
        assertRejects(
            ValidationError::class,
            static fn () => SshManager::assertValue($key, 'x'),
            "ต้องปฏิเสธการแก้ {$key} ซึ่งไม่ได้อยู่ในรายการที่อนุญาต",
        );
    }
});

test('หน้าต่างเวลายืนยันถูกจำกัดช่วงเสมอ', static function (): void {
    $capability = (new CapabilityRegistry())->resolve('ssh.config_set');

    // สั้นเกินไปจนกดยืนยันไม่ทัน หรือยาวจนไร้ความหมาย ต้องถูกบีบเข้าช่วง
    $short = $capability->validate(['Port' => '2222', 'window' => 1]);
    $long = $capability->validate(['Port' => '2222', 'window' => 99999]);

    assertTrue($short['window'] >= 30, 'เวลายืนยันต้องไม่สั้นกว่า 30 วินาที');
    assertTrue($long['window'] <= 900, 'เวลายืนยันต้องไม่ยาวเกิน 900 วินาที');
});

test('ssh.config_set ต้องมีค่าอย่างน้อยหนึ่งค่าจึงจะทำงาน', static function (): void {
    $capability = (new CapabilityRegistry())->resolve('ssh.config_set');

    assertRejects(
        ValidationError::class,
        static fn () => $capability->validate([]),
        'ไม่มีค่าที่จะเปลี่ยน ต้องไม่ยอมให้เขียนไฟล์',
    );
});

test('การกู้คืนต้องยืนยันด้วยชื่อโดเมนเสมอ', static function (): void {
    $capability = (new CapabilityRegistry())->resolve('backup.restore');

    assertRejects(
        ValidationError::class,
        static fn () => $capability->validate(['backup_id' => 1]),
        'ต้องบังคับให้ยืนยันด้วยชื่อโดเมน',
    );
});

test('capability กลุ่มคืนค่าถูกทำเครื่องหมายว่าเปลี่ยนแปลงระบบ', static function (): void {
    $registry = new CapabilityRegistry();

    // ต้องเป็น mutating เพื่อให้เข้า audit log และเข้าโหมด dryrun อย่างถูกต้อง
    foreach (['ssh.config_set', 'rollback.confirm', 'rollback.run',
        'backup.create', 'backup.restore', 'backup.delete'] as $name) {
        assertTrue($registry->resolve($name)->isMutating(), "{$name} ต้องถูกบันทึกลง audit");
    }

    assertTrue(!$registry->resolve('ssh.config_get')->isMutating(), 'ssh.config_get ต้องเป็นการอ่านอย่างเดียว');
});

test('เวลาที่เหลือของรายการรอยืนยันไม่ติดลบ', static function (): void {
    assertSame(0, RollbackGuard::remaining(['expires_at' => time() - 100]), 'หมดเวลาแล้วต้องเป็น 0');
    assertTrue(RollbackGuard::remaining(['expires_at' => time() + 60]) > 0, 'ยังไม่หมดเวลาต้องมากกว่า 0');
});
