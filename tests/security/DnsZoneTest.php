<?php

declare(strict_types=1);

/**
 * เชื่อม BIND9 จริง — PLAN-V2 เฟส E3
 *
 * สองชั้นที่เทสต์ชุดนี้แยกกันตรวจ เรียงตามความมั่นใจที่ได้:
 *   1. **รูปแบบ zone file ที่สร้างออกมา** — ตรวจกับ `named-checkzone` ตัวจริงที่ติดตั้งอยู่
 *      บนเครื่องนี้ตรง ๆ (ไม่ผ่าน Executor เลย เพราะไม่ต้องมีสิทธิ์พิเศษ เป็นแค่ตัวตรวจ
 *      ไวยากรณ์) จึงเป็นการพิสูจน์จริง ไม่ใช่แค่ "โครงสร้างดูสมเหตุสมผล" — ต่างจาก S3
 *      (SigV4) และ E2 (project quota) ที่ไม่มี oracle จริงให้ตรวจในเครื่องพัฒนานี้เลย
 *   2. **พฤติกรรมของ `BindZoneManager`/capability** — ผ่าน `DryRunExecutor` แบบเดียวกับ
 *      ที่ `FirewallTest.php`/`SshConfigSet` ใช้ เพราะการเขียน `/etc/bind/*` และสั่ง
 *      `rndc reload` จริงต้องมีสิทธิ์ root ซึ่งเครื่องพัฒนานี้ไม่มี (และไม่ควรมี)
 */

use Phpcp\Agent\Actor;
use Phpcp\Agent\Capability\DnsZoneWrite;
use Phpcp\Agent\Context;
use Phpcp\Agent\Executor\DryRunExecutor;
use Phpcp\Domain\DnsRecord;
use Phpcp\Driver\Dns\BindZoneManager;
use Phpcp\Kernel\Config;
use Phpcp\Kernel\Db;
use Phpcp\Security\Permissions;

group('DnsZone — เชื่อม BIND9 จริง (zone file, named-checkzone, capability)');

// --- เครื่องมือช่วยเทสต์ -------------------------------------------------------

/** สร้าง Config ที่ตั้งค่า dns.* ตามที่ระบุ โดยไม่ทิ้งผลข้างเคียงไว้กับ process อื่น */
function dnsTestConfig(array $dnsOverrides = []): Config
{
    $root = sys_get_temp_dir() . '/phpcp-dns-cfg-' . getmypid() . '-' . bin2hex(random_bytes(4));
    mkdir($root . '/etc', 0700, true);

    file_put_contents($root . '/etc/config.php', sprintf(
        "<?php return %s;\n",
        var_export(['mode' => 'sandbox', 'layout' => 'portable', 'dns' => $dnsOverrides], true),
    ));

    $previous = getenv('PHPCP_CONFIG');
    putenv('PHPCP_CONFIG=' . $root . '/etc/config.php');

    $config = Config::load($root);

    putenv($previous === false ? 'PHPCP_CONFIG' : 'PHPCP_CONFIG=' . $previous);
    register_shutdown_function(static fn () => exec('rm -rf ' . escapeshellarg($root)));

    return $config;
}

function dnsZoneFixture(): array
{
    $root = sys_get_temp_dir() . '/phpcp-dnszone-' . getmypid() . '-' . bin2hex(random_bytes(4));
    mkdir($root, 0750, true);

    $db = new Db($root . '/panel.db');
    $db->migrate(PHPCP_ROOT . '/db/migrations');

    $userId = $db->insert('users', [
        'username' => 'dnsowner', 'display_name' => 'dnsowner',
        'password_hash' => password_hash('x', PASSWORD_DEFAULT), 'role' => Permissions::WEBADMIN,
        'totp_enabled' => 0, 'must_change_password' => 0, 'status' => 'active', 'failed_attempts' => 0,
        'email' => '', 'service_status' => 'active', 'uid' => 0, 'gid' => 0,
        'quota_domains' => -1, 'quota_subdomains' => -1, 'quota_aliases' => -1, 'quota_emails' => -1,
        'quota_databases' => -1, 'quota_ftp_users' => -1, 'disk_quota_mb' => -1, 'disk_used_mb' => 0,
        'created_at' => time(), 'updated_at' => time(),
    ]);

    $siteId = $db->insert('sites', [
        'name' => 'เว็บทดสอบ DNS', 'primary_domain' => 'primary.test', 'docroot' => '/srv/phpcp/x',
        'php_version' => '8.4', 'ssl_mode' => 'off', 'status' => 'active', 'disk_used_mb' => 0,
        'owner_user_id' => $userId, 'docroot_override' => '', 'created_at' => time(), 'updated_at' => time(),
    ]);

    register_shutdown_function(static fn () => exec('rm -rf ' . escapeshellarg($root)));

    return ['root' => $root, 'db' => $db, 'user_id' => $userId, 'site_id' => $siteId];
}

/** @return array{id:int,owner_user_id:int} */
function seedDomain(array $fixture, string $domain, int $zoneSerial = 0): array
{
    $id = $fixture['db']->insert('domains', [
        'site_id' => $fixture['site_id'], 'domain' => $domain, 'type' => 'primary',
        'redirect_target' => null, 'redirect_code' => null, 'zone_serial' => $zoneSerial,
        'created_at' => time(),
    ]);

    return ['id' => $id, 'owner_user_id' => $fixture['user_id']];
}

function seedDnsRecord(array $fixture, int $domainId, string $type, string $name, string $value, ?int $priority = null): void
{
    $fixture['db']->insert('dns_records', [
        'domain_id' => $domainId, 'type' => $type, 'name' => $name, 'value' => $value,
        'ttl' => 3600, 'priority' => $priority,
    ]);
}

function contextWith(array $fixture, Config $config, int $userId = 0, string $role = Permissions::SUPERADMIN): Context
{
    return new Context(new Actor($userId, 'tester', $role, '127.0.0.1', 'test'), $config, $fixture['db']);
}

/** รัน named-checkzone ตัวจริงกับเนื้อหา zone — คืน [ok, output] */
function checkZoneReal(string $domain, string $content): array
{
    $path = sys_get_temp_dir() . '/phpcp-checkzone-' . bin2hex(random_bytes(6)) . '.zone';
    file_put_contents($path, $content);

    $output = [];
    $exitCode = 0;
    exec('named-checkzone ' . escapeshellarg($domain) . ' ' . escapeshellarg($path) . ' 2>&1', $output, $exitCode);
    @unlink($path);

    return [$exitCode === 0, implode("\n", $output)];
}

// --- 1. รูปแบบ zone file ต้องผ่าน named-checkzone ตัวจริง ---------------------

test('zone ที่มีเรกคอร์ดครบทุกชนิดผ่าน named-checkzone จริง', static function (): void {
    $records = [
        ['type' => 'A', 'name' => '@', 'value' => '203.0.113.10', 'ttl' => 3600, 'priority' => null],
        ['type' => 'AAAA', 'name' => '@', 'value' => '2001:db8::1', 'ttl' => 3600, 'priority' => null],
        ['type' => 'A', 'name' => 'www', 'value' => '203.0.113.10', 'ttl' => 3600, 'priority' => null],
        ['type' => 'CNAME', 'name' => 'blog', 'value' => 'example.com', 'ttl' => 3600, 'priority' => null],
        ['type' => 'MX', 'name' => '@', 'value' => 'mail.example.com', 'ttl' => 3600, 'priority' => 10],
        ['type' => 'TXT', 'name' => '@', 'value' => '"v=spf1 -all"', 'ttl' => 3600, 'priority' => null],
        ['type' => 'CAA', 'name' => '@', 'value' => '0 issue "letsencrypt.org"', 'ttl' => 3600, 'priority' => null],
    ];

    $zone = DnsRecord::toAuthoritativeZoneFile(
        'example.com', $records, 2026080900,
        ['ns1.myhostingcompany.net', 'ns2.myhostingcompany.net'], 'hostmaster@example.com',
    );

    [$ok, $output] = checkZoneReal('example.com', $zone);
    assertTrue($ok, "named-checkzone ต้องผ่าน แต่ได้:\n{$output}\n\n--- zone ---\n{$zone}");
});

test('zone ที่ไม่มีเรกคอร์ดเลย (แค่ SOA/NS) ยังโหลดได้', static function (): void {
    $zone = DnsRecord::toAuthoritativeZoneFile('bare.test', [], 1, ['ns1.myhostingcompany.net'], '');

    [$ok, $output] = checkZoneReal('bare.test', $zone);
    assertTrue($ok, "zone เปล่าต้องยังผ่าน checkzone ได้: {$output}");
    assertTrue(str_contains($zone, 'hostmaster.bare.test'), 'soa_email ว่าง ต้องถอยไปใช้ hostmaster.<domain> เป็นค่าเริ่มต้น');
});

test('อีเมล SOA ที่มีจุดใน local-part ถูก escape ถูกต้องและยังผ่าน checkzone', static function (): void {
    $zone = DnsRecord::toAuthoritativeZoneFile('dotted.test', [], 1, ['ns1.myhostingcompany.net'], 'first.last@example.com');

    assertTrue(str_contains($zone, 'first\\.last.example.com.'), 'จุดใน local-part ต้องถูก escape ด้วย \\. ไม่ใช่ปล่อยเป็นตัวคั่นโดเมนเฉย ๆ');

    [$ok, $output] = checkZoneReal('dotted.test', $zone);
    assertTrue($ok, "zone ที่มี local-part แบบมีจุดต้องยังผ่าน checkzone: {$output}");
});

test('หลายเนมเซิร์ฟเวอร์ปรากฏเป็น NS record ครบทุกตัว', static function (): void {
    $zone = DnsRecord::toAuthoritativeZoneFile(
        'multins.test', [], 1,
        ['ns1.myhostingcompany.net', 'ns2.myhostingcompany.net', 'ns3.myhostingcompany.net'], '',
    );

    foreach (['ns1', 'ns2', 'ns3'] as $ns) {
        assertTrue(str_contains($zone, "{$ns}.myhostingcompany.net."), "ต้องมี NS record ของ {$ns}: {$zone}");
    }
});

test('ชื่อโฮสต์ของ CNAME/MX ถูกเติมจุดท้าย (FQDN) ให้เองเสมอ กัน BIND9 ต่อชื่อโดเมนซ้ำ', static function (): void {
    $records = [
        ['type' => 'CNAME', 'name' => 'app', 'value' => 'target.example.net', 'ttl' => 3600, 'priority' => null],
        ['type' => 'MX', 'name' => '@', 'value' => 'mail.example.net', 'ttl' => 3600, 'priority' => 10],
    ];

    $zone = DnsRecord::toAuthoritativeZoneFile('fqdn.test', $records, 1, ['ns1.myhostingcompany.net'], '');

    assertTrue(str_contains($zone, 'target.example.net.'), 'ค่า CNAME ต้องลงท้ายด้วยจุด');
    assertTrue(str_contains($zone, '10 mail.example.net.'), 'ค่า MX ต้องลงท้ายด้วยจุด');
});

// --- 1b. เส้นทางของ binary ต้องชี้ไปที่ไฟล์ที่มีอยู่จริง -------------------------

test('เส้นทางของเครื่องมือ BIND9 ที่โค้ดใช้ ต้องมีไฟล์อยู่จริงบนเครื่องนี้อย่างน้อยหนึ่งตัวเลือก', static function (): void {
    // **บั๊กที่เทสต์ชุดนี้พลาดตอนแรก:** ฮาร์ดโค้ด /usr/sbin/named-checkzone ไว้ทั้งที่บน
    // Ubuntu อยู่ที่ /usr/bin — เทสต์เดิมจับไม่ได้เพราะ DryRunExecutor ไม่รันคำสั่งจริง
    // ส่วนเทสต์ที่ยิง named-checkzone จริงเรียกผ่าน PATH ไม่ได้ผ่านค่าคงที่ในโค้ด
    // เจอตอนยิงใส่เซิร์ฟเวอร์จริง (2026-08-10) · ข้อนี้ปิดช่องว่างนั้น
    $groups = [
        'named-checkzone' => BindZoneManager::CHECKZONE_PATHS,
        'named-checkconf' => BindZoneManager::CHECKCONF_PATHS,
        'rndc' => BindZoneManager::RNDC_PATHS,
    ];

    foreach ($groups as $name => $paths) {
        $found = array_values(array_filter($paths, static fn (string $p): bool => is_file($p)));

        assertTrue(
            $found !== [],
            "ไม่พบ {$name} ที่เส้นทางใดเลยใน: " . implode(', ', $paths)
            . ' — ถ้าเครื่องนี้ติดตั้ง BIND9 ไว้จริง แปลว่ารายการเส้นทางในโค้ดไม่ครบ',
        );
    }
});

// --- 2. BindZoneManager ผ่าน DryRunExecutor -----------------------------------

test('dns.enabled = false ทำให้ writeZone() เป็น no-op ที่บอกชัดเจน ไม่ใช่แกล้งสำเร็จ', static function (): void {
    $fixture = dnsZoneFixture();
    $domain = seedDomain($fixture, 'off.test');
    $config = dnsTestConfig(['enabled' => false]);

    $manager = new BindZoneManager(new DryRunExecutor(), $config, $fixture['db']);
    $result = $manager->writeZone(['id' => $domain['id'], 'domain' => 'off.test', 'zone_serial' => 0]);

    assertSame(false, $result['pushed'], 'ปิด dns.enabled ต้องไม่ถือว่า push สำเร็จ');
    assertTrue(str_contains($result['message'], 'ยังไม่ได้เปิดใช้งาน'), 'ข้อความต้องบอกเหตุผลชัดเจน: ' . $result['message']);
});

test('เปิด dns.enabled แต่ไม่ตั้ง nameservers ต้องถูกปฏิเสธก่อนแตะไฟล์ใด ๆ เลย', static function (): void {
    $fixture = dnsZoneFixture();
    $domain = seedDomain($fixture, 'nons.test');
    $config = dnsTestConfig(['enabled' => true, 'nameservers' => []]);

    assertRejects(
        Phpcp\Agent\ValidationError::class,
        static fn () => (new BindZoneManager(new DryRunExecutor(), $config, $fixture['db']))
            ->writeZone(['id' => $domain['id'], 'domain' => 'nons.test', 'zone_serial' => 0]),
        'BIND9 ปฏิเสธ zone ที่ไม่มี NS อยู่แล้ว ต้องกันตั้งแต่ก่อนเขียนไฟล์',
    );
});

test('writeZone() ที่ผ่านครบเขียนไฟล์ zone ใหม่ (โดเมนใหม่เขียน named.conf.local ด้วย) แล้วบันทึก serial', static function (): void {
    $fixture = dnsZoneFixture();
    $domain = seedDomain($fixture, 'newzone.test', zoneSerial: 0);
    seedDnsRecord($fixture, $domain['id'], 'A', '@', '203.0.113.5');

    $config = dnsTestConfig(['enabled' => true, 'nameservers' => ['ns1.myhostingcompany.net']]);
    $executor = new DryRunExecutor();

    $manager = new BindZoneManager($executor, $config, $fixture['db']);
    $result = $manager->writeZone(['id' => $domain['id'], 'domain' => 'newzone.test', 'zone_serial' => 0]);

    assertSame(true, $result['pushed'], 'ต้องสำเร็จเมื่อเปิดใช้งานและตั้งค่าครบ: ' . json_encode($result));
    assertSame(1, $result['record_count'], 'ต้องนับเรกคอร์ดที่ใส่ไว้ 1 รายการ');

    $commands = implode(' | ', $executor->simulatedCommands());
    assertTrue(str_contains($commands, 'newzone.test.zone'), 'ต้องมีการเขียนไฟล์ zone: ' . $commands);
    assertTrue(str_contains($commands, 'named.conf.local'), 'โดเมนใหม่ (zone_serial=0) ต้องเขียน named.conf.local ด้วย: ' . $commands);

    $newSerial = (int) $fixture['db']->value('SELECT zone_serial FROM domains WHERE id = :id', ['id' => $domain['id']]);
    assertTrue($newSerial > 0, 'ต้องบันทึก serial ใหม่ที่มากกว่า 0 ลงฐานข้อมูล');
    assertSame((int) $result['serial'], $newSerial, 'serial ที่คืนออกมาต้องตรงกับที่บันทึกลง DB');
});

test('writeZone() ของโดเมนที่มี zone อยู่แล้วไม่แตะ named.conf.local ซ้ำ', static function (): void {
    $fixture = dnsZoneFixture();
    $domain = seedDomain($fixture, 'existing.test', zoneSerial: 2026010100);
    seedDnsRecord($fixture, $domain['id'], 'A', '@', '203.0.113.6');

    $config = dnsTestConfig(['enabled' => true, 'nameservers' => ['ns1.myhostingcompany.net']]);
    $executor = new DryRunExecutor();

    (new BindZoneManager($executor, $config, $fixture['db']))
        ->writeZone(['id' => $domain['id'], 'domain' => 'existing.test', 'zone_serial' => 2026010100]);

    $commands = implode(' | ', $executor->simulatedCommands());
    assertTrue(!str_contains($commands, 'named.conf.local'), 'zone เดิมที่มีอยู่แล้วไม่ควรเขียน named.conf.local ซ้ำ: ' . $commands);
});

test('serial ต้องเพิ่มขึ้นเสมอ แม้ค่าที่มีอยู่แล้วเกินรูปแบบวันที่ในวันนี้ไปมาก', static function (): void {
    $fixture = dnsZoneFixture();
    // ตั้งใจใช้เลขที่ "อนาคต" เกินกว่ารูปแบบ YYYYMMDD00 ของวันนี้มาก ๆ
    $farFutureSerial = 9999999999;
    $domain = seedDomain($fixture, 'farfuture.test', zoneSerial: $farFutureSerial);

    $config = dnsTestConfig(['enabled' => true, 'nameservers' => ['ns1.myhostingcompany.net']]);
    $result = (new BindZoneManager(new DryRunExecutor(), $config, $fixture['db']))
        ->writeZone(['id' => $domain['id'], 'domain' => 'farfuture.test', 'zone_serial' => $farFutureSerial]);

    assertTrue($result['serial'] > $farFutureSerial, "serial ใหม่ ({$result['serial']}) ต้องมากกว่าค่าเดิม ({$farFutureSerial}) เสมอ ห้ามย้อนกลับไปใช้ค่าจากวันที่");
});

test('reloadAll() ที่ล้มทุกโดเมนต้องโยน error ไม่ใช่รายงานว่าสำเร็จ', static function (): void {
    // **เจอจากการทดสอบบนเซิร์ฟเวอร์จริง (2026-08-10):** เดิมคืน pushed=true เสมอ ทำให้
    // หน้าจอขึ้นแถบเขียว "สำเร็จ" ทั้งที่ซิงก์ไม่ได้สักโดเมน — ล้มเงียบแบบเดียวกับที่
    // เฟส E1 เตือนไว้เองว่าอันตรายพอ ๆ กับไม่มีระบบเลย
    //
    // จำลองความล้มเหลวด้วยการชี้ zone_dir ไปยังที่ที่สร้างไดเรกทอรีไม่ได้ (ใต้ไฟล์ปกติ)
    $fixture = dnsZoneFixture();
    $domain = seedDomain($fixture, 'willfail.test');
    seedDnsRecord($fixture, $domain['id'], 'A', '@', '203.0.113.9');

    $blocker = $fixture['root'] . '/not-a-dir';
    file_put_contents($blocker, 'ไฟล์ปกติ ไม่ใช่ไดเรกทอรี');

    $config = dnsTestConfig([
        'enabled' => true,
        'nameservers' => ['ns1.myhostingcompany.net'],
        'zone_dir' => $blocker . '/zones',
    ]);

    assertRejects(
        Phpcp\Agent\ExecutionFailed::class,
        static fn () => (new BindZoneManager(new Phpcp\Agent\Executor\RealExecutor(), $config, $fixture['db']))->reloadAll(),
        'ล้มทุกโดเมนต้องโยน error ให้ผู้เรียกเห็น ไม่ใช่คืน pushed=true เงียบ ๆ',
    );
});

test('reloadAll() ข้ามโดเมนที่ไม่มีเรกคอร์ด DNS เลย', static function (): void {
    $fixture = dnsZoneFixture();
    $withRecords = seedDomain($fixture, 'withrecords.test');
    seedDnsRecord($fixture, $withRecords['id'], 'A', '@', '203.0.113.7');
    seedDomain($fixture, 'norecords.test'); // ไม่เพิ่มเรกคอร์ดให้เลย

    $config = dnsTestConfig(['enabled' => true, 'nameservers' => ['ns1.myhostingcompany.net']]);
    $result = (new BindZoneManager(new DryRunExecutor(), $config, $fixture['db']))->reloadAll();

    assertSame(1, $result['domains'], 'ต้องซิงก์เฉพาะโดเมนที่มีเรกคอร์ดจริง: ' . json_encode($result));
    assertSame([], $result['failed'], 'ต้องไม่มีโดเมนล้มเหลว');
});

// --- 3. Capability: ทะเบียนถูกต้องและตรวจสิทธิ์ตามเจ้าของ ---------------------

test('dns.zone_write และ dns.reload ถูกทำเครื่องหมายว่าเปลี่ยนแปลงระบบและใช้สิทธิ์คนละระดับ', static function (): void {
    $registry = new Phpcp\Agent\CapabilityRegistry();

    $zoneWrite = $registry->resolve('dns.zone_write');
    assertTrue($zoneWrite->isMutating(), 'dns.zone_write ต้องเข้า audit');
    assertSame('domain.manage', $zoneWrite->permission(), 'ผูกกับสิทธิ์ระดับโดเมนเดียวกับที่ webadmin แก้ DNS ของตัวเองอยู่แล้ว');

    $reload = $registry->resolve('dns.reload');
    assertTrue($reload->isMutating(), 'dns.reload ต้องเข้า audit');
    assertSame('dns.manage', $reload->permission(), 'ต้องเป็นสิทธิ์ระดับเครื่อง แยกจาก domain.manage เพราะกระทบทุกโดเมนพร้อมกัน');
});

test('dns.manage ไม่อยู่ในสิทธิ์ของ webadmin — ซิงก์ทุกโดเมนพร้อมกันได้เฉพาะผู้ดูแลเซิร์ฟเวอร์', static function (): void {
    assertTrue(!Permissions::roleHas(Permissions::WEBADMIN, 'dns.manage'), 'webadmin ต้องไม่มีสิทธิ์สั่งซิงก์ทุกโดเมนพร้อมกัน');
    assertTrue(Permissions::roleHas(Permissions::SYSADMIN, 'dns.manage'), 'sysadmin ต้องมีสิทธิ์นี้เหมือน backup.offsite');
    assertTrue(Permissions::roleHas(Permissions::SUPERADMIN, 'dns.manage'), 'superadmin ต้องมีสิทธิ์ทุกอย่างเสมอ');
});

test('ผู้ดูแลเว็บไซต์เขียน zone ของโดเมนคนอื่นไม่ได้ แม้จะมีสิทธิ์ domain.manage', static function (): void {
    $fixture = dnsZoneFixture();
    $domain = seedDomain($fixture, 'owned-by-other.test');
    $config = dnsTestConfig(['enabled' => false]);

    // ผู้ใช้คนละคนกับเจ้าของโดเมน (owner_user_id ของ fixture คือ $fixture['user_id'])
    $context = contextWith($fixture, $config, userId: $fixture['user_id'] + 999, role: Permissions::WEBADMIN);

    $capability = new DnsZoneWrite();

    assertRejects(
        Phpcp\Agent\PermissionDenied::class,
        static fn () => $capability->run(['domain_id' => $domain['id']], new DryRunExecutor(), $context),
        'ต้องปฏิเสธ แม้ actor role จะมี domain.manage อยู่แล้วก็ตาม',
    );
});

test('เจ้าของโดเมนเรียก dns.zone_write กับโดเมนของตัวเองได้ปกติ', static function (): void {
    $fixture = dnsZoneFixture();
    $domain = seedDomain($fixture, 'ownzone.test');
    $config = dnsTestConfig(['enabled' => false]);

    $context = contextWith($fixture, $config, userId: $fixture['user_id'], role: Permissions::WEBADMIN);
    $capability = new DnsZoneWrite();

    $result = $capability->run(['domain_id' => $domain['id']], new DryRunExecutor(), $context);

    assertSame(false, $result['pushed'], 'dns.enabled ปิดอยู่ แต่คำสั่งเองต้องไม่ถูกปฏิเสธเพราะสิทธิ์');
});
