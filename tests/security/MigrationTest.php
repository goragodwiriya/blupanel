<?php

declare(strict_types=1);

/**
 * Migration ที่สร้างตารางใหม่ — ของที่หายไปเงียบ ๆ ตอนย้ายข้อมูล
 *
 * SQLite แก้ `CHECK` หรือถอดข้อจำกัดในที่เดิมไม่ได้ ต้อง **สร้างตารางใหม่แล้วย้ายข้อมูล**
 * ซึ่งเป็นขั้นตอนที่ทำของหายได้สามอย่างโดยไม่มีข้อผิดพลาดฟ้องเลยสักบรรทัด:
 *
 *   1. **แถวข้อมูล** — `INSERT ... SELECT` ที่ระบุคอลัมน์ไม่ครบ
 *   2. **index** — หายไปพร้อม `DROP TABLE` ต้องสร้างใหม่เอง · ผลคือคำสั่งค้นช้าลงเรื่อย ๆ
 *      เมื่อข้อมูลโต ซึ่งไม่มีใครโยงกลับมาที่ migration เมื่อหลายเดือนก่อน
 *   3. **foreign key** — เขียนไม่ครบแล้ว `ON DELETE CASCADE` หาย · ลบโดเมนแล้วเรกคอร์ด
 *      ค้างอยู่เป็นขยะที่ชี้ไปยังแถวที่ไม่มีอยู่แล้ว
 *
 * เทสต์อื่นทั้งหมด migrate จากศูนย์ จึงไม่มีตัวไหนเห็นเส้นทาง "อัปเกรดเครื่องที่มีข้อมูล
 * อยู่แล้ว" เลย — ซึ่งเป็นเส้นทางเดียวที่เกิดขึ้นจริงกับเครื่องที่ใช้งานอยู่
 */

use Phpcp\Kernel\Db;

group('Migration — อัปเกรดเครื่องที่มีข้อมูลอยู่แล้ว');

/**
 * ฐานข้อมูลที่ migrate ไปถึงรุ่นก่อนหน้าที่ระบุเท่านั้น — จำลองเครื่องที่ยังไม่ได้อัปเดต
 *
 * @return array{db:Db,root:string}
 */
function migrationFixtureBefore(string $version): array
{
    $root = sys_get_temp_dir() . '/phpcp-mig-' . getmypid() . '-' . bin2hex(random_bytes(4));
    mkdir($root . '/migrations', 0750, true);

    foreach (glob(PHPCP_ROOT . '/db/migrations/*.sql') ?: [] as $file) {
        if (basename($file) >= $version) {
            continue;
        }

        copy($file, $root . '/migrations/' . basename($file));
    }

    $db = new Db($root . '/panel.db');
    $db->migrate($root . '/migrations');

    register_shutdown_function(static fn () => exec('rm -rf ' . escapeshellarg($root)));

    return ['db' => $db, 'root' => $root];
}

test('อัปเกรดตาราง dns_records ต้องไม่ทำข้อมูล index หรือ CASCADE หาย', static function (): void {
    $fixture = migrationFixtureBefore('0019');
    $db = $fixture['db'];

    $userId = $db->insert('users', [
        'username' => 'migowner', 'password_hash' => 'x',
        'role' => 'superadmin', 'totp_enabled' => 0, 'must_change_password' => 0,
        'status' => 'active', 'failed_attempts' => 0, 'email' => '', 'service_status' => 'active',
        'uid' => 0, 'gid' => 0, 'quota_domains' => -1, 'quota_subdomains' => -1,
        'quota_aliases' => -1, 'quota_emails' => -1, 'quota_databases' => -1,
        'quota_ftp_users' => -1, 'disk_quota_mb' => -1, 'disk_used_mb' => 0,
        'created_at' => time(), 'updated_at' => time(),
    ]);

    // สคีมาก่อน 0021 ยังมีคอลัมน์ `name` แบบ NOT NULL — เทสต์นี้จำลองสภาพตอนนั้น
    // จึงต้องใส่ค่ามาด้วย ต่างจากเทสต์อื่นที่ทำงานบนสคีมาปัจจุบัน
    $siteId = $db->insert('sites', [
        'name' => 'เว็บเก่า', 'primary_domain' => 'legacy.test', 'docroot' => '/srv/phpcp/legacy',
        'php_version' => '8.4', 'ssl_mode' => 'off', 'status' => 'active', 'disk_used_mb' => 0,
        'owner_user_id' => $userId, 'docroot_override' => '', 'created_at' => time(), 'updated_at' => time(),
    ]);

    $domainId = $db->insert('domains', [
        'site_id' => $siteId, 'domain' => 'legacy.test', 'type' => 'primary',
        'created_at' => time(), 'zone_serial' => 2026010100,
    ]);

    // ข้อมูลที่ต้องรอดข้ามการอัปเกรดมาครบทุกคอลัมน์ รวมทั้งค่าที่เป็น null
    $before = [
        ['A', '@', '203.0.113.1', 3600, null],
        ['MX', '@', 'mail.legacy.test', 3600, 10],
        ['TXT', '@', 'v=spf1 -all', 600, null],
    ];

    foreach ($before as [$type, $name, $value, $ttl, $priority]) {
        $db->insert('dns_records', [
            'domain_id' => $domainId, 'type' => $type, 'name' => $name,
            'value' => $value, 'ttl' => $ttl, 'priority' => $priority,
        ]);
    }

    $ran = $db->migrate(PHPCP_ROOT . '/db/migrations');

    assertTrue(in_array('0019_dns_any_type', $ran, true), 'ต้องรัน migration 0019 จริง: ' . implode(', ', $ran));

    // 1. ข้อมูลครบทุกแถวและทุกค่า
    $after = array_map(
        static fn (array $r): array => [
            (string) $r['type'], (string) $r['name'], (string) $r['value'],
            (int) $r['ttl'], $r['priority'] === null ? null : (int) $r['priority'],
        ],
        $db->all('SELECT type, name, value, ttl, priority FROM dns_records ORDER BY type'),
    );

    assertSame($before, $after, 'ข้อมูลเดิมต้องรอดข้ามการอัปเกรดมาครบทุกค่า');

    // 2. index ต้องถูกสร้างใหม่ — DROP TABLE พามันไปด้วยเสมอ
    $indexes = array_column($db->all('PRAGMA index_list(dns_records)'), 'name');

    assertTrue(
        in_array('idx_dns_domain', $indexes, true),
        'index ต้องถูกสร้างใหม่หลังย้ายตาราง ได้: ' . implode(', ', $indexes),
    );

    // 3. ชนิดที่เคยถูก CHECK ปฏิเสธต้องเก็บได้แล้ว — เป็นเหตุผลทั้งหมดของ migration นี้
    $db->insert('dns_records', [
        'domain_id' => $domainId, 'type' => 'SRV', 'name' => '_sip._tcp',
        'value' => '0 5 5060 sip.legacy.test.', 'ttl' => 3600, 'priority' => null,
    ]);

    assertSame(
        1,
        (int) $db->value("SELECT COUNT(*) FROM dns_records WHERE type = 'SRV'", [], 0),
        'ชนิดใหม่ต้องเก็บได้หลังอัปเกรด',
    );

    // 4. ON DELETE CASCADE ต้องยังทำงาน — ไม่งั้นเรกคอร์ดค้างเป็นขยะที่ชี้ไปแถวที่ไม่มีแล้ว
    $db->run('DELETE FROM domains WHERE id = :id', ['id' => $domainId]);

    assertSame(
        0,
        (int) $db->value('SELECT COUNT(*) FROM dns_records', [], 0),
        'ลบโดเมนแล้วเรกคอร์ดต้องหายตามไปด้วย (foreign key ยังทำงาน)',
    );
});

test('ทุก migration ที่สร้างตารางใหม่ต้องสร้าง index กลับมาด้วย', static function (): void {
    /*
     * ตรึงกฎไว้กับ migration **ทุกตัวในอนาคต** ไม่ใช่แค่ตัวที่เพิ่งเขียน — ไฟล์ที่มี
     * `DROP TABLE` แล้วไม่มี `CREATE INDEX` เลยแปลว่า index ของตารางนั้นหายไปทั้งหมด
     * โดยไม่มีอะไรฟ้อง · อาการที่เห็นคือระบบช้าลงเรื่อย ๆ เมื่อข้อมูลโต ซึ่งไม่มีใคร
     * โยงกลับมาที่ migration เมื่อหลายเดือนก่อน
     */
    $offenders = [];

    foreach (glob(PHPCP_ROOT . '/db/migrations/*.sql') ?: [] as $file) {
        $sql = (string) file_get_contents($file);

        // ตัดคอมเมนต์ก่อน — คำอธิบายของ migration พูดถึง DROP TABLE เป็นเรื่องปกติ
        $code = (string) preg_replace('~^\s*--[^\n]*$~m', '', $sql);

        if (!preg_match('~DROP\s+TABLE~i', $code)) {
            continue;
        }

        preg_match_all('~RENAME\s+TO\s+([a-z_]+)~i', $code, $renamed);

        foreach ($renamed[1] as $table) {
            // ตารางนั้นเคยมี index ไหม — ดูจาก migration ที่สร้างมันครั้งแรก
            $everIndexed = false;

            foreach (glob(PHPCP_ROOT . '/db/migrations/*.sql') ?: [] as $other) {
                if (basename($other) >= basename($file)) {
                    continue;
                }

                if (preg_match('~CREATE\s+INDEX[^;]+\bON\s+' . preg_quote($table, '~') . '\b~i', (string) file_get_contents($other))) {
                    $everIndexed = true;
                    break;
                }
            }

            if (!$everIndexed) {
                continue;
            }

            if (!preg_match('~CREATE\s+INDEX[^;]+\bON\s+' . preg_quote($table, '~') . '\b~i', $code)) {
                $offenders[] = basename($file) . ' → ' . $table;
            }
        }
    }

    assertSame([], $offenders, "migration ที่ย้ายตารางแล้วไม่ได้สร้าง index กลับ:\n  " . implode("\n  ", $offenders));
});
