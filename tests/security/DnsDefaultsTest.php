<?php

declare(strict_types=1);

/**
 * เรกคอร์ดชุดแรกของโดเมนใหม่
 *
 * เดิมสร้างเว็บแล้วหน้า DNS ว่างเปล่า และ zone file ไม่เกิดขึ้นจนกว่าจะพิมพ์เรกคอร์ด
 * แรกด้วยมือ · ที่แย่กว่านั้นคือพอพิมพ์แล้ว zone ก็ยังเขียนไม่ผ่านเพราะขาด glue
 * — ผู้ดูแลจึงเจอ "DNS ใช้ไม่ได้เลย" โดยไม่มีอะไรบอกว่าต้องทำอะไรเพิ่ม
 */

use Phpcp\Domain\DnsZoneDefaults;
use Phpcp\Domain\ServerAddress;

group('DnsZoneDefaults — zone ที่ใช้งานได้ตั้งแต่วินาทีแรก');

/**
 * ฐานข้อมูลเปล่าพร้อมตัวช่วยสร้างเว็บและโดเมน
 *
 * @return array{db:Phpcp\Kernel\Db,site:callable,domain:callable}
 */
function dnsDefaultsFixture(): array
{
    $root = sys_get_temp_dir() . '/phpcp-dnsfx-' . getmypid() . '-' . bin2hex(random_bytes(4));
    mkdir($root, 0750, true);
    register_shutdown_function(static fn () => exec('rm -rf ' . escapeshellarg($root)));

    $db = new Phpcp\Kernel\Db($root . '/panel.db');
    $db->migrate(PHPCP_ROOT . '/db/migrations');

    $userId = $db->insert('users', [
        'username' => 'dnsfx', 'display_name' => 'dnsfx',
        'password_hash' => password_hash('x', PASSWORD_DEFAULT), 'role' => Phpcp\Security\Permissions::WEBADMIN,
        'totp_enabled' => 0, 'must_change_password' => 0, 'status' => 'active', 'failed_attempts' => 0,
        'email' => '', 'service_status' => 'active', 'uid' => 0, 'gid' => 0,
        'quota_domains' => -1, 'quota_subdomains' => -1, 'quota_aliases' => -1, 'quota_emails' => -1,
        'quota_databases' => -1, 'quota_ftp_users' => -1, 'disk_quota_mb' => -1, 'disk_used_mb' => 0,
        'created_at' => time(), 'updated_at' => time(),
    ]);

    return [
        'db' => $db,
        'site' => static fn (string $domain): int => $db->insert('sites', [
            'name' => $domain, 'primary_domain' => $domain, 'docroot' => '/tmp/x',
            'php_version' => '8.4', 'ssl_mode' => 'off', 'status' => 'active', 'disk_used_mb' => 0,
            'owner_user_id' => $userId, 'docroot_override' => '', 'created_at' => time(), 'updated_at' => time(),
        ]),
        'domain' => static fn (int $siteId, string $domain): int => $db->insert('domains', [
            'site_id' => $siteId, 'domain' => $domain, 'type' => 'primary', 'created_at' => time(),
        ]),
    ];
}

test('เนมเซิร์ฟเวอร์ที่อยู่ในโดเมนตัวเองต้องได้ glue record — ขาดแล้ว zone ทั้งไฟล์โหลดไม่ขึ้น', static function (): void {
    /*
     * **เจอบนเซิร์ฟเวอร์จริง (2026-08-14):** named-checkzone ปฏิเสธ zone แรกของเครื่อง
     * ด้วย "NS 'ns1.bluprint.in.th' has no address records (A or AAAA)" · เมื่อชื่อของ
     * เนมเซิร์ฟเวอร์อยู่ใต้ zone ที่มันดูแลเอง ต้องมี A record ของชื่อนั้นอยู่ใน zone ด้วย
     * ไม่งั้นไม่มีใครหาที่อยู่ของมันเจอ (ไก่กับไข่) — และ **ทั้ง zone โหลดไม่ขึ้น**
     * ไม่ใช่แค่ NS ตัวนั้นใช้ไม่ได้
     */
    $records = DnsZoneDefaults::forDomain(
        'example.com',
        '203.0.113.10',
        ['ns1.example.com', 'ns2.example.com'],
    );

    $names = array_column($records, 'name');

    assertTrue(in_array('ns1', $names, true), 'ต้องมี glue ของ ns1 · ได้: ' . implode(', ', $names));
    assertTrue(in_array('ns2', $names, true), 'ต้องมี glue ของ ns2 · ได้: ' . implode(', ', $names));
    assertTrue(in_array('@', $names, true), 'ต้องมี A ของโดเมนเอง');
    assertTrue(in_array('www', $names, true), 'ต้องมี www');

    foreach ($records as $record) {
        assertSame('A', $record['type'], 'เรกคอร์ดเริ่มต้นต้องเป็น A ทั้งหมด');
        assertSame('203.0.113.10', $record['value'], 'ต้องชี้ไปไอพีที่ส่งมา');
    }
});

test('เนมเซิร์ฟเวอร์ที่อยู่คนละ zone ต้องไม่ได้ glue — ไม่ใช่หน้าที่ของ zone นี้', static function (): void {
    // ผู้ที่ใช้ DNS ของผู้ให้บริการอื่น (Cloudflare, Route53) ตั้ง ns เป็นชื่อของเจ้านั้น
    // การใส่ A record ของชื่อคนอื่นลง zone ตัวเองคือการประกาศข้อมูลผิดให้ทั้งอินเทอร์เน็ต
    $names = array_column(
        DnsZoneDefaults::forDomain('example.com', '203.0.113.10', ['ns1.cloudflare.com', 'ns2.cloudflare.com']),
        'name',
    );

    assertSame(['@', 'www'], $names, 'ต้องมีแค่ @ กับ www · ได้: ' . implode(', ', $names));
});

test('ไม่มีไอพีที่ใช้ได้ต้องไม่สร้างอะไรเลย ดีกว่าสร้างเรกคอร์ดที่ชี้ผิด', static function (): void {
    foreach (['', 'ไม่ใช่ไอพี', '999.1.1.1', '::1'] as $bad) {
        assertSame(
            [],
            DnsZoneDefaults::forDomain('example.com', $bad, ['ns1.example.com']),
            "ไอพี '{$bad}' ต้องไม่ทำให้เกิดเรกคอร์ด",
        );
    }
});

test('ไอพีส่วนตัวต้องถูกจับได้ — บนคลาวด์มันแปลว่าตรวจไอพีผิด', static function (): void {
    /*
     * เครื่องบนคลาวด์อยู่หลัง NAT: การ์ดเน็ตเห็น 172.26.x แต่โลกติดต่อผ่าน 18.x
     * ถ้าเอาค่าจากการ์ดเน็ตไปทำ A record โดเมนทุกโดเมนบนเครื่องจะชี้ไปที่อยู่ที่
     * ไม่มีใครนอกวงเข้าถึงได้ — เว็บล่มทั้งเครื่องโดยที่ทุกอย่างดู "สำเร็จ"
     */
    foreach (['172.26.15.166', '10.0.0.5', '192.168.1.1', '127.0.0.1'] as $private) {
        assertTrue(ServerAddress::isPrivate($private), "{$private} ต้องถูกนับเป็นไอพีส่วนตัว");
    }

    foreach (['18.142.27.80', '203.0.113.10', '8.8.8.8'] as $public) {
        assertTrue(!ServerAddress::isPrivate($public), "{$public} ต้องไม่ถูกนับเป็นไอพีส่วนตัว");
    }
});

test('เรียกซ้ำต้องไม่สร้างเรกคอร์ดซ้ำ และต้องไม่ทับของที่ผู้ดูแลตั้งเอง', static function (): void {
    $root = sys_get_temp_dir() . '/phpcp-dnsdef-' . getmypid() . '-' . bin2hex(random_bytes(4));
    mkdir($root, 0750, true);
    register_shutdown_function(static fn () => exec('rm -rf ' . escapeshellarg($root)));

    $db = new Phpcp\Kernel\Db($root . '/panel.db');
    $db->migrate(PHPCP_ROOT . '/db/migrations');

    $userId = $db->insert('users', [
        'username' => 'dnsdef', 'display_name' => 'dnsdef',
        'password_hash' => password_hash('x', PASSWORD_DEFAULT), 'role' => Phpcp\Security\Permissions::WEBADMIN,
        'totp_enabled' => 0, 'must_change_password' => 0, 'status' => 'active', 'failed_attempts' => 0,
        'email' => '', 'service_status' => 'active', 'uid' => 0, 'gid' => 0,
        'quota_domains' => -1, 'quota_subdomains' => -1, 'quota_aliases' => -1, 'quota_emails' => -1,
        'quota_databases' => -1, 'quota_ftp_users' => -1, 'disk_quota_mb' => -1, 'disk_used_mb' => 0,
        'created_at' => time(), 'updated_at' => time(),
    ]);
    $siteId = $db->insert('sites', [
        'name' => 'x', 'primary_domain' => 'example.com', 'docroot' => '/tmp/x',
        'php_version' => '8.4', 'ssl_mode' => 'off', 'status' => 'active', 'disk_used_mb' => 0,
        'owner_user_id' => $userId, 'docroot_override' => '', 'created_at' => time(), 'updated_at' => time(),
    ]);
    $domainId = $db->insert('domains', [
        'site_id' => $siteId, 'domain' => 'example.com', 'type' => 'primary', 'created_at' => time(),
    ]);

    // ผู้ดูแลตั้ง www เป็น CNAME ไว้เอง — ห้ามมี A ซ้อนเข้ามา
    // (CNAME อยู่ร่วมกับเรกคอร์ดชนิดอื่นที่ชื่อเดียวกันไม่ได้ตามมาตรฐาน zone จะขัดกันเอง)
    $db->insert('dns_records', [
        'domain_id' => $domainId, 'type' => 'CNAME', 'name' => 'www',
        'value' => 'shop.partner.com.', 'ttl' => 3600, 'priority' => null,
    ]);

    $ns = ['ns1.example.com', 'ns2.example.com'];

    $first = DnsZoneDefaults::seed($db, $domainId, 'example.com', '203.0.113.10', $ns);
    assertTrue(!in_array('www', $first, true), 'ต้องไม่แตะ www ที่ผู้ดูแลตั้งเป็น CNAME ไว้');
    assertTrue(in_array('@', $first, true) && in_array('ns1', $first, true), 'ที่เหลือต้องถูกสร้าง');

    $second = DnsZoneDefaults::seed($db, $domainId, 'example.com', '203.0.113.10', $ns);
    assertSame([], $second, 'เรียกซ้ำต้องไม่สร้างอะไรเพิ่ม');

    $total = (int) $db->value('SELECT count(*) FROM dns_records WHERE domain_id = :id', ['id' => $domainId]);
    assertSame(4, $total, 'ต้องมี CNAME เดิม + @ + ns1 + ns2 = 4 · ได้ ' . $total);
});

test('สร้าง zone แม่ต้องไม่ทำให้เว็บลูกที่ให้บริการอยู่กลายเป็น NXDOMAIN', static function (): void {
    /*
     * **เจอบนเซิร์ฟเวอร์จริง (2026-08-14):** เครื่องให้บริการ srv.example.com อยู่ก่อน
     * โดยอาศัยเรกคอร์ดที่ผู้รับจดโดเมน · ผู้ดูแลสร้างเว็บ example.com แล้วชี้ NS มาที่
     * เครื่องนี้ → zone ใหม่ไม่มีชื่อ srv เลย → เว็บที่ใช้งานได้อยู่ล่มทันที และ certbot
     * ต่ออายุใบรับรองไม่ได้อีกเพราะพิสูจน์สิทธิ์ไม่ผ่าน · ไม่มีอะไรเตือนสักคำ
     */
    $fx = dnsDefaultsFixture();

    // มี srv.example.com ให้บริการอยู่ก่อนแล้ว
    $fx['domain']($fx['site']('srv.example.com'), 'srv.example.com');

    // แล้วค่อยรับ zone แม่มาดูแล
    $parentId = $fx['domain']($fx['site']('example.com'), 'example.com');

    $created = Phpcp\Domain\DnsZoneDefaults::seed(
        $fx['db'], $parentId, 'example.com', '203.0.113.10', ['ns1.example.com'],
    );

    assertTrue(in_array('srv', $created, true), 'zone แม่ต้องมีชื่อของเว็บลูกที่มีอยู่ · ได้: ' . implode(', ', $created));
});

test('โดเมนที่อยู่ใต้ zone ที่เครื่องดูแลอยู่แล้ว ต้องเป็นเรกคอร์ดใน zone นั้น ไม่ใช่ zone แยก', static function (): void {
    $fx = dnsDefaultsFixture();
    $parentId = $fx['domain']($fx['site']('example.com'), 'example.com');
    $childId = $fx['domain']($fx['site']('srv.example.com'), 'srv.example.com');

    $parent = Phpcp\Domain\DnsZoneDefaults::parentZone($fx['db'], 'srv.example.com', $childId);

    assertTrue($parent !== null, 'ต้องหา zone แม่เจอ');
    assertSame('example.com', $parent['domain'], 'zone แม่ต้องเป็น example.com');
    assertSame('srv', $parent['label'], 'ชื่อในzone ต้องเป็น srv');

    // โดเมนที่ไม่มีแม่บนเครื่องนี้ต้องได้ zone ของตัวเอง
    $otherId = $fx['domain']($fx['site']('other.com'), 'other.com');
    assertSame(null, Phpcp\Domain\DnsZoneDefaults::parentZone($fx['db'], 'other.com', $otherId), 'ไม่มีแม่ = zone ของตัวเอง');
});

test('zone ที่ใกล้ที่สุดต้องเป็นเจ้าของ ไม่ใช่ zone บนสุด', static function (): void {
    // เครื่องที่โฮสต์ทั้ง example.com และ sub.example.com เป็น zone แยกกันจริง ๆ
    // ชื่อ a.sub.example.com ต้องไปอยู่ใน sub.example.com ไม่ใช่ example.com
    $fx = dnsDefaultsFixture();
    $fx['domain']($fx['site']('example.com'), 'example.com');
    $fx['domain']($fx['site']('sub.example.com'), 'sub.example.com');
    $leafId = $fx['domain']($fx['site']('a.sub.example.com'), 'a.sub.example.com');

    $parent = Phpcp\Domain\DnsZoneDefaults::parentZone($fx['db'], 'a.sub.example.com', $leafId);

    assertSame('sub.example.com', $parent['domain'], 'ต้องเลือก zone ที่ใกล้ที่สุด');
    assertSame('a', $parent['label'], 'ชื่อในzone ต้องเป็น a');
});

test('www ต้องเป็นโดเมนสำรองอัตโนมัติ — DNS สัญญาไว้แล้ว vhost ต้องรับ', static function (): void {
    /*
     * **เจอบนเซิร์ฟเวอร์จริง (2026-08-14):** ระบบสร้างเรกคอร์ด DNS ของ `www` ให้ตอนสร้างเว็บ
     * แต่ไม่ได้เพิ่มเป็น alias · ชื่อนั้นจึง resolve มาที่เครื่องถูกต้องแล้วตกไปที่ vhost
     * เริ่มต้นของ nginx — ผู้เยี่ยมชม www.<โดเมน> เห็นหน้าต้อนรับของ nginx แทนเว็บลูกค้า
     * และได้ HTTP 200 ซึ่งดูเหมือนทุกอย่างปกติ
     */
    $capability = new Phpcp\Agent\Capability\SiteCreate();

    $clean = $capability->validate([
        'domain' => 'example.com', 'php_version' => '8.4', 'owner_user_id' => 1,
    ]);

    assertTrue(
        in_array('www.example.com', $clean['aliases'], true),
        'www ต้องถูกเพิ่มเป็นโดเมนสำรองให้เอง · ได้: ' . implode(', ', $clean['aliases']),
    );

    // โดเมนย่อยก็ต้องได้ www ของตัวเอง — DnsZoneDefaults สร้าง www.srv ไว้ให้แล้ว
    $sub = $capability->validate([
        'domain' => 'srv.example.com', 'php_version' => '8.4', 'owner_user_id' => 1,
    ]);
    assertTrue(in_array('www.srv.example.com', $sub['aliases'], true), 'โดเมนย่อยต้องได้ www ด้วย');

    // โดเมนที่เป็น www อยู่แล้วต้องไม่ได้ www.www
    $www = $capability->validate([
        'domain' => 'www.example.com', 'php_version' => '8.4', 'owner_user_id' => 1,
    ]);
    assertTrue(!in_array('www.www.example.com', $www['aliases'], true), 'ห้ามได้ www.www');

    // ที่ผู้ใช้ระบุเองต้องไม่หาย
    $custom = $capability->validate([
        'domain' => 'example.com', 'php_version' => '8.4', 'owner_user_id' => 1,
        'aliases' => ['shop.example.com'],
    ]);
    assertTrue(in_array('shop.example.com', $custom['aliases'], true), 'alias ที่ระบุเองต้องยังอยู่');
    assertTrue(in_array('www.example.com', $custom['aliases'], true), 'และยังได้ www เพิ่มให้');
});
