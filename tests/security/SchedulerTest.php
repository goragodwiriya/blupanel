<?php

declare(strict_types=1);

/**
 * ตัวจับเวลาของ panel — PLAN-V2 เฟส A1
 *
 * เทสต์ชุดนี้ปกป้องคุณสมบัติเดียวที่สำคัญที่สุดของ scheduler:
 * `rollback.run` ต้องถูกเรียกเองแม้ไม่มีใครเปิดหน้าเว็บ เพราะกรณีที่กลไกคืนค่า
 * ถูกออกแบบมาเพื่อรับมือคือกรณีที่ผู้ดูแล "หลุดการเชื่อมต่อไปแล้ว"
 * ถ้าเทสต์กลุ่มนี้ล้ม แปลว่าเปลี่ยนพอร์ต SSH ผิดแล้วล็อกตัวเองออกจากเครื่องถาวรได้อีกครั้ง
 *
 * ใช้ฐานข้อมูลชั่วคราวจริง ไม่ใช่ mock — ตรรกะ "ถึงเวลาหรือยัง" อยู่ที่การอ่าน/เขียน
 * last_run_at ซึ่ง mock จะซ่อนความผิดพลาดตรงนั้นไปทั้งหมด
 */

use Phpcp\Agent\CapabilityRegistry;
use Phpcp\Agent\TransportError;
use Phpcp\Domain\CronSchedule;
use Phpcp\Domain\ScheduledJobRepository;
use Phpcp\Domain\Scheduler;

group('Scheduler — งานตามเวลาต้องทำงานเองโดยไม่ต้องมีคนเปิดหน้าเว็บ');

/** เวลาที่รู้ค่าแน่นอน ใช้เทียบตารางเวลาได้โดยไม่ขึ้นกับตอนที่รันเทสต์ */
function at(string $time): int
{
    return (int) strtotime($time);
}

test('เทียบตารางเวลากับเวลาจริงได้ถูกต้อง', static function (): void {
    assertTrue(CronSchedule::matches('* * * * *', at('2026-08-05 10:37:00')), 'ทุกนาทีต้องตรงเสมอ');
    assertTrue(CronSchedule::matches('0 3 * * *', at('2026-08-05 03:00:00')), 'ตี 3 ต้องตรง');
    assertTrue(!CronSchedule::matches('0 3 * * *', at('2026-08-05 03:01:00')), 'ตี 3 นาทีที่ 1 ต้องไม่ตรง');
    assertTrue(!CronSchedule::matches('0 3 * * *', at('2026-08-05 04:00:00')), 'ตี 4 ต้องไม่ตรง');

    assertTrue(CronSchedule::matches('*/15 * * * *', at('2026-08-05 10:30:00')), 'ทุก 15 นาที: นาทีที่ 30 ต้องตรง');
    assertTrue(!CronSchedule::matches('*/15 * * * *', at('2026-08-05 10:31:00')), 'ทุก 15 นาที: นาทีที่ 31 ต้องไม่ตรง');

    // 2026-08-05 เป็นวันพุธ (3)
    assertTrue(CronSchedule::matches('0 0 * * 3', at('2026-08-05 00:00:00')), 'วันพุธต้องตรง');
    assertTrue(!CronSchedule::matches('0 0 * * 4', at('2026-08-05 00:00:00')), 'วันพฤหัสต้องไม่ตรง');

    // 2026-08-09 เป็นวันอาทิตย์ — cron ให้ใช้ได้ทั้ง 0 และ 7
    assertTrue(CronSchedule::matches('0 0 * * 0', at('2026-08-09 00:00:00')), 'อาทิตย์เขียนเป็น 0 ต้องตรง');
    assertTrue(CronSchedule::matches('0 0 * * 7', at('2026-08-09 00:00:00')), 'อาทิตย์เขียนเป็น 7 ต้องตรงเหมือนกัน');

    assertTrue(CronSchedule::matches('0 4 1 * *', at('2026-09-01 04:00:00')), 'วันที่ 1 ของเดือนต้องตรง');
    assertTrue(!CronSchedule::matches('0 4 1 * *', at('2026-09-02 04:00:00')), 'วันที่ 2 ต้องไม่ตรง');
});

test('ระบุทั้งวันที่และวันในสัปดาห์ = ตรงข้อใดข้อหนึ่ง ตามกฎของ cron', static function (): void {
    // กฎนี้คนพลาดกันบ่อยที่สุด: "0 0 1 * 1" คือวันที่ 1 ของเดือน *หรือ* ทุกวันจันทร์
    // ถ้าเข้าใจผิดเป็น "และ" งานสำรองข้อมูลรายสัปดาห์จะเงียบไปเป็นเดือน
    assertTrue(CronSchedule::matches('0 0 1 * 1', at('2026-09-01 00:00:00')), 'วันที่ 1 (อังคาร) ต้องตรงเพราะวันที่ตรง');
    assertTrue(CronSchedule::matches('0 0 1 * 1', at('2026-09-07 00:00:00')), 'วันจันทร์ที่ 7 ต้องตรงเพราะวันในสัปดาห์ตรง');
    assertTrue(!CronSchedule::matches('0 0 1 * 1', at('2026-09-08 00:00:00')), 'อังคารที่ 8 ต้องไม่ตรงทั้งสองทาง');

    // ส่วนที่ระบุข้างเดียวยังเป็น "และ" ตามปกติ
    assertTrue(!CronSchedule::matches('0 0 15 * *', at('2026-09-16 00:00:00')), 'ระบุแค่วันที่ต้องตรงเฉพาะวันนั้น');
});

test('งานที่พลาดรอบไปตอนเครื่องดับต้องถูกไล่เก็บ ไม่ใช่ข้ามทั้งวัน', static function (): void {
    $daily = '0 3 * * *';

    // เครื่องดับตั้งแต่เที่ยงคืน กลับมา 09:00 — งานตี 3 ต้องได้รัน
    assertTrue(
        CronSchedule::isDue($daily, at('2026-08-05 09:00:00'), at('2026-08-04 23:59:00')),
        'งานตี 3 ที่พลาดไปต้องถูกไล่เก็บเมื่อเครื่องกลับมา',
    );

    // รันไปแล้วเมื่อตี 3 วันนี้ ตอน 09:00 ต้องไม่รันซ้ำ
    assertTrue(
        !CronSchedule::isDue($daily, at('2026-08-05 09:00:00'), at('2026-08-05 03:00:00')),
        'งานที่รันไปแล้วในรอบนี้ต้องไม่รันซ้ำ',
    );

    // นาทีเดียวกันต้องไม่ยิงสองครั้ง แม้ scheduler จะถูกเรียกซ้ำ
    assertTrue(
        !CronSchedule::isDue('* * * * *', at('2026-08-05 10:00:30'), at('2026-08-05 10:00:05')),
        'นาทีเดียวกันต้องรันได้ครั้งเดียว',
    );

    assertTrue(
        CronSchedule::isDue('* * * * *', at('2026-08-05 10:01:00'), at('2026-08-05 10:00:05')),
        'นาทีถัดไปต้องรันได้',
    );

    // งานที่เพิ่งถูกเพิ่มต้องไม่ระเบิดย้อนหลังทันทีที่ติดตั้ง
    assertTrue(
        !CronSchedule::isDue($daily, at('2026-08-05 09:00:00'), null),
        'งานใหม่ที่ยังไม่เคยรันต้องรอถึงเวลาจริง ไม่ใช่รันทันทีที่ติดตั้ง',
    );

    // ไล่ย้อนหลังต้องมีเพดาน ไม่งั้นเครื่องที่ปิดไว้เป็นเดือนจะรันรวดเดียวเป็นร้อยรอบ
    assertTrue(
        !CronSchedule::isDue($daily, at('2026-08-05 09:00:00'), at('2026-06-01 00:00:00'), 3600),
        'งานที่ค้างเกินหน้าต่างไล่เก็บต้องไม่ถูกรันย้อนหลัง',
    );
});

test('งานตั้งต้นต้องมี rollback.run ทุกนาที', static function (): void {
    $names = array_column(ScheduledJobRepository::DEFAULTS, 'name');

    foreach (['rollback.run', 'expiry.check', 'disk.usage', 'cert.sync'] as $required) {
        assertTrue(in_array($required, $names, true), "งานตั้งต้นต้องมี {$required}");
    }

    $rollback = null;
    foreach (ScheduledJobRepository::DEFAULTS as $job) {
        if ($job['name'] === 'rollback.run') {
            $rollback = $job;
        }

        // ตารางเวลาที่ผิดรูปแบบจะถูก cron ข้ามเงียบ ๆ — ต้องจับตั้งแต่ตอนนี้
        CronSchedule::normalize($job['schedule']);
        TestRunner::$assertions++;
    }

    assertSame('* * * * *', $rollback['schedule'] ?? '', 'rollback.run ต้องตรวจทุกนาที');
});

test('เติมงานตั้งต้นซ้ำกี่ครั้งก็ไม่เกิดรายการซ้ำ', static function (): void {
    $jobs = new ScheduledJobRepository(migratedDb());

    $first = $jobs->installDefaults();
    $second = $jobs->installDefaults();

    assertSame([], $second, 'เรียกซ้ำต้องไม่เพิ่มอะไรอีก');
    assertSame(count(ScheduledJobRepository::DEFAULTS), count($jobs->all()), 'จำนวนงานต้องเท่ากับรายการตั้งต้น');

    // expiry.check ถูกใส่ไว้แล้วโดย migration 0003 — ต้องไม่ถูกเพิ่มซ้ำ
    assertTrue(!in_array('expiry.check', $first, true), 'งานที่มาจาก migration ต้องไม่ถูกเพิ่มซ้ำ');
});

test('งานที่ถึงเวลาถูกสั่งผ่าน agent และบันทึกผลไว้', static function (): void {
    $db = migratedDb();
    $jobs = new ScheduledJobRepository($db);
    $jobs->installDefaults();

    // ให้มีรายการรอคืนค่าที่หมดเวลาแล้ว rollback.run จึงมีงานให้ทำจริง
    $db->insert('pending_rollbacks', [
        'action' => 'ssh.config_set',
        'description' => 'ทดสอบ',
        'payload_json' => '{"files":{},"units":[],"undo":[]}',
        'created_at' => time() - 300,
        'expires_at' => time() - 60,
    ]);

    $called = [];
    $scheduler = new Scheduler($db, static function (string $capability, array $args) use (&$called): array {
        $called[] = $capability;

        return ['message' => 'เรียบร้อย'];
    });

    $results = $scheduler->runDue(at('2026-08-05 10:07:00'));

    assertTrue(in_array('rollback.run', $called, true), 'rollback.run ต้องถูกเรียกทุกนาทีเมื่อมีรายการค้าง');
    assertTrue(!in_array('expiry.check', $called, true), 'งานรายวันต้องไม่ถูกเรียกนอกเวลาที่ตั้งไว้');
    assertTrue(!in_array('disk.usage', $called, true), 'งานทุก 15 นาทีต้องไม่ถูกเรียกที่นาทีที่ 7');

    $rollback = $jobs->find('rollback.run');
    assertSame('ok', $rollback['last_status'], 'ต้องบันทึกว่ารอบล่าสุดสำเร็จ');
    assertTrue($rollback['last_run_at'] !== null, 'ต้องบันทึกเวลาที่รันไว้');

    // ตรวจว่า rollback.run มีผลลัพธ์ของตัวเอง ไม่ใช่นับจำนวนงานทั้งหมด — งานอื่นที่ตั้ง
    // ไว้ทุกนาทีเพิ่มเข้ามาได้ตลอด (เช่น metrics.record ของเฟส E6) โดยไม่เกี่ยวกับเทสต์นี้
    $names = array_column($results, 'name');
    assertTrue(in_array('rollback.run', $names, true), 'ต้องมีผลลัพธ์ของ rollback.run: ' . implode(', ', $names));
});

test('rollback.run ที่ไม่มีอะไรค้างต้องไม่ถูกสั่ง เพื่อไม่ให้ audit log จม', static function (): void {
    $db = migratedDb();
    $jobs = new ScheduledJobRepository($db);
    $jobs->installDefaults();

    $called = [];
    $scheduler = new Scheduler($db, static function (string $capability) use (&$called): array {
        $called[] = $capability;

        return [];
    });

    $results = $scheduler->runDue(at('2026-08-05 10:07:00'));

    // ตรวจเจาะจงที่ rollback.run — งานอื่นที่ตั้งไว้ทุกนาทีถูกสั่งได้ตามปกติถ้ามันไม่บันทึก
    // audit (เช่น metrics.record ของเฟส E6 ที่ isMutating() = false) · เจตนาของเทสต์นี้คือ
    // "อย่าให้ audit จมด้วย rollback.run ที่ไม่มีงานทำ" ไม่ใช่ "ห้ามสั่งอะไรเลยทั้งรอบ"
    assertTrue(!in_array('rollback.run', $called, true), 'ไม่มีรายการค้าง = ต้องไม่สั่ง rollback.run: ' . implode(', ', $called));

    $rollbackResult = array_values(array_filter($results, static fn (array $r): bool => ($r['name'] ?? '') === 'rollback.run'));
    assertSame('skipped', $rollbackResult[0]['status'] ?? '', 'ต้องรายงานว่าข้าม ไม่ใช่เงียบหายไป');
    assertSame('skipped', $jobs->find('rollback.run')['last_status'], 'ต้องบันทึกว่าตรวจแล้วไม่มีงาน');
});

test('งานหนึ่งล้มต้องไม่ทำให้งานที่เหลือไม่ได้รัน', static function (): void {
    $db = migratedDb();
    $jobs = new ScheduledJobRepository($db);
    $jobs->installDefaults();

    // ทำให้ทั้งสองงานถึงเวลาพร้อมกันที่นาทีเดียวกัน
    $db->update('scheduled_jobs', ['schedule' => '* * * * *'], ['name' => 'expiry.check']);
    $db->update('scheduled_jobs', ['schedule' => '* * * * *'], ['name' => 'disk.usage']);

    $called = [];
    $scheduler = new Scheduler($db, static function (string $capability) use (&$called): array {
        $called[] = $capability;

        if ($capability === 'cert.sync') {
            throw new TransportError('ติดต่อ agent ไม่ได้');
        }

        return [];
    });

    $db->update('scheduled_jobs', ['schedule' => '* * * * *'], ['name' => 'cert.sync']);

    $scheduler->runDue(at('2026-08-05 10:07:00'));

    foreach (['cert.sync', 'disk.usage', 'expiry.check'] as $name) {
        assertTrue(in_array($name, $called, true), "{$name} ต้องถูกเรียกแม้งานอื่นจะล้ม");
    }

    $failed = $jobs->find('cert.sync');
    assertSame('error', $failed['last_status'], 'งานที่ล้มต้องถูกบันทึกว่าล้ม');
    assertTrue(str_contains((string) $failed['last_error'], 'agent'), 'ต้องเก็บสาเหตุไว้ให้ผู้ดูแลอ่าน');
    assertSame('ok', $jobs->find('disk.usage')['last_status'], 'งานอื่นต้องยังสำเร็จตามปกติ');
});

test('งานที่ถูกปิดไว้ต้องไม่ถูกสั่ง', static function (): void {
    $db = migratedDb();
    $jobs = new ScheduledJobRepository($db);
    $jobs->installDefaults();
    $jobs->setEnabled('disk.usage', false);
    $db->update('scheduled_jobs', ['schedule' => '* * * * *'], ['name' => 'disk.usage']);

    $called = [];
    $scheduler = new Scheduler($db, static function (string $capability) use (&$called): array {
        $called[] = $capability;

        return [];
    });

    $scheduler->runDue(at('2026-08-05 10:07:00'));

    assertTrue(!in_array('disk.usage', $called, true), 'งานที่ปิดไว้ต้องไม่ทำงาน');
});

test('ตารางเวลาที่พังต้องถูกบันทึกเป็นข้อผิดพลาด ไม่ใช่ทำให้ทั้งรอบตาย', static function (): void {
    $db = migratedDb();
    $jobs = new ScheduledJobRepository($db);
    $jobs->installDefaults();

    // เขียนตรงเข้าฐานข้อมูล — จำลองกรณีที่ค่าพังจากการแก้ด้วยมือหรือ migration เก่า
    $db->update('scheduled_jobs', ['schedule' => 'ทุกวันตอนเช้า'], ['name' => 'expiry.check']);
    $db->update('scheduled_jobs', ['schedule' => '* * * * *'], ['name' => 'disk.usage']);

    $called = [];
    $scheduler = new Scheduler($db, static function (string $capability) use (&$called): array {
        $called[] = $capability;

        return [];
    });

    $scheduler->runDue(at('2026-08-05 10:07:00'));

    assertSame('error', $jobs->find('expiry.check')['last_status'], 'ตารางเวลาที่พังต้องดังพอให้เห็น');
    assertTrue(in_array('disk.usage', $called, true), 'งานที่ตารางเวลาถูกต้องต้องยังทำงานต่อ');
});

test('capability ของ scheduler ต้องไม่เติม audit log ทุกรอบ', static function (): void {
    $registry = new CapabilityRegistry();

    // disk.usage รันทุก 15 นาทีและ cert.sync ทุกวัน ทั้งคู่ไม่ได้เปลี่ยนอะไรบนเครื่อง
    // ถ้าถูกทำเครื่องหมายว่าเปลี่ยนแปลงระบบ audit log จะเต็มไปด้วยแถวที่ไม่มีความหมาย
    // จนตามหาสิ่งที่เกิดขึ้นจริงย้อนหลังไม่ได้ ซึ่งทำลายเหตุผลที่ audit log มีอยู่
    foreach (['disk.usage', 'cert.sync'] as $name) {
        assertTrue(!$registry->resolve($name)->isMutating(), "{$name} ต้องเป็นการวัดผล ไม่ใช่การเปลี่ยนแปลงระบบ");
    }

    // ส่วน rollback.run ยังต้องเป็น mutating เหมือนเดิม เพราะมันคืนค่า config จริง
    assertTrue($registry->resolve('rollback.run')->isMutating(), 'rollback.run ต้องถูกบันทึก audit ทุกครั้งที่ทำงานจริง');
});

test('unit ของตัวจับเวลาต้องถูกป้องกันแบบเดียวกับบริการอื่นของ panel', static function (): void {
    foreach (['phpcp-scheduler', 'phpcp-scheduler.service', 'phpcp-scheduler.timer'] as $unit) {
        assertTrue(
            \Phpcp\Agent\SelfProtection::isProtectedUnit($unit),
            "{$unit} ต้องหยุดผ่านหน้าเว็บไม่ได้ — ไม่งั้นกลไกคืนค่าอัตโนมัติถูกปิดเงียบได้",
        );
    }
});

test('ตัวจับเวลามีไฟล์ unit และตัวรันครบ', static function (): void {
    // เกณฑ์รับงานของเฟส A1 คือ "ทำงานเองได้จริง" ซึ่งต้องมีทั้งสามชิ้นนี้พร้อมกัน
    foreach ([
        '/bin/phpcp-scheduler',
        '/templates/panel/phpcp-scheduler.service.tpl',
        '/templates/panel/phpcp-scheduler.timer.tpl',
    ] as $file) {
        assertTrue(is_file(PHPCP_ROOT . $file), "ต้องมีไฟล์ {$file}");
    }

    $installer = (string) file_get_contents(PHPCP_ROOT . '/install.sh');
    assertTrue(
        str_contains($installer, 'phpcp-scheduler.timer'),
        'install.sh ต้องติดตั้งและเปิดใช้งาน timer ไม่งั้นเครื่องที่ติดตั้งใหม่จะไม่มีตัวจับเวลา',
    );

    $unit = (string) file_get_contents(PHPCP_ROOT . '/templates/panel/phpcp-scheduler.service.tpl');
    assertTrue(
        str_contains($unit, 'User={{PANEL_USER}}'),
        'scheduler ต้องรันด้วยสิทธิ์ของชั้นเว็บ ไม่ใช่ root — ทุกอย่างต้องเดินผ่าน agent',
    );
});
