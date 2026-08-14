<?php

declare(strict_types=1);

/**
 * ระบบโควตาของลูกค้า — สิ่งที่กันไม่ให้ลูกค้ารายเดียวกินทรัพยากรทั้งเครื่อง
 *
 * เทสต์ชุดนี้เกิดขึ้นเพราะพบว่า **ระบบโควตาไม่ทำงานเลยทั้งสองทาง**:
 *   - ลูกค้าที่มีโควตาจริง → ถูกปฏิเสธทุกคำขอ ("โควตาถูกปิดการใช้งาน") เพราะผู้เรียก
 *     ส่งชื่อชนิดเป็นเอกพจน์ (`domain`) แต่อาร์เรย์ใช้คีย์พหูพจน์ (`domains`)
 *   - ผู้ใช้ webadmin ที่ไม่มีแถวใน customers → ผ่านทุกคำขอแบบไม่จำกัด
 *
 * ทั้งสองอย่างไม่มีเทสต์จับมาก่อน เพราะเทสต์เดิมตรวจ "capability ปฏิเสธค่าผิดรูปแบบ"
 * แต่ไม่เคยตรวจว่า "ลูกค้าที่ยังมีโควตาเหลือสร้างของได้จริงไหม"
 */

use Phpcp\Domain\QuotaChecker;
use Phpcp\Domain\UserRepository;
use Phpcp\Security\Permissions;

group('Quota — โควตาต้องบังคับใช้จริง ไม่ใช่ปฏิเสธทุกอย่างหรืออนุญาตทุกอย่าง');

/** บัญชีโฮสติ้งหนึ่งรายพร้อมเว็บไซต์และโดเมนที่นับเข้าโควตาแล้ว */
function quotaFixture(): array
{
    $db = migratedDb();
    $users = new UserRepository($db);

    $userId = $users->createHostingAccount(
        'quotacust',
        'Quota-Customer-Password-11',
        
        'quota@example.com',
        [
            'domains' => 3,
            'subdomains' => 2,
            'aliases' => 5,
            'emails' => 10,
            'databases' => 2,
            'ftp_users' => 0,
        ],
    );

    $now = time();

    $siteId = $db->insert('sites', [
        'primary_domain' => 'quota.example.com',
        'docroot' => '/srv/phpcp/sites/quota.example.com/public',
        'php_version' => '8.4',
        'owner_user_id' => $userId,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    // ใช้ไปแล้ว: โดเมน 2 (primary + subdomain) · subdomain 1 · ฐานข้อมูล 1
    foreach ([['quota.example.com', 'primary'], ['app.quota.example.com', 'subdomain']] as [$domain, $type]) {
        $db->insert('domains', ['site_id' => $siteId, 'domain' => $domain, 'type' => $type, 'created_at' => $now]);
    }

    $db->insert('databases_', [
        'db_name' => 'quota_db',
        'site_id' => $siteId,
        'charset' => 'utf8mb4',
        'size_bytes' => 0,
        'created_at' => $now,
    ]);

    return [$db, $users, $userId];
}

test('ลูกค้าที่ยังมีโควตาเหลือต้องสร้างของได้จริง', static function (): void {
    // นี่คือเคสที่พังมาตลอดและไม่มีใครจับได้: ผู้เรียกทุกตัวในระบบส่งชนิดเป็นเอกพจน์
    [$db, $users, $userId] = quotaFixture();
    $quota = new QuotaChecker($users);

    foreach (['domain' => 2, 'subdomain' => 1, 'alias' => 0, 'database' => 1] as $type => $expectedUsed) {
        $result = $quota->canCreate($userId, $type, 1);

        assertTrue($result['ok'], "ลูกค้าต้องสร้าง {$type} ได้เมื่อยังมีโควตาเหลือ — ได้: {$result['message']}");
        assertSame($expectedUsed, $result['used'], "จำนวนที่ใช้ของ {$type} ต้องนับถูก");
    }

    // และพหูพจน์ต้องให้ผลเหมือนกันเป๊ะ — ผู้เรียกเดิมใช้คนละแบบกันอยู่
    assertSame(
        $quota->canCreate($userId, 'domain', 1)['limit'],
        $quota->canCreate($userId, 'domains', 1)['limit'],
        'เอกพจน์กับพหูพจน์ต้องหมายถึงโควตาตัวเดียวกัน',
    );
});

test('เกินโควตาต้องถูกปฏิเสธพร้อมบอกตัวเลขจริง', static function (): void {
    [$db, $users, $userId] = quotaFixture();
    $quota = new QuotaChecker($users);

    // โดเมน: โควตา 3 ใช้ไป 2 → ขอเพิ่ม 1 ได้ · ขอเพิ่ม 2 ไม่ได้
    assertTrue($quota->canCreate($userId, 'domain', 1)['ok'], 'ขอพอดีโควตาต้องได้');

    $over = $quota->canCreate($userId, 'domain', 2);
    assertTrue(!$over['ok'], 'ขอเกินโควตาต้องถูกปฏิเสธ');
    assertTrue(str_contains($over['message'], '2 จาก 3'), 'ข้อความต้องบอกตัวเลขจริง ไม่ใช่บอกแค่ว่าเต็ม');

    // ฐานข้อมูล: โควตา 2 ใช้ไป 1 → เพิ่มได้อีก 1 เท่านั้น
    assertTrue($quota->canCreate($userId, 'database', 1)['ok'], 'ช่องว่างที่เหลือพอดีต้องสร้างได้');
    assertTrue(!$quota->canCreate($userId, 'database', 2)['ok'], 'ขอเกินช่องว่างที่เหลือต้องถูกปฏิเสธ');
});

test('โควตา 0 คือปิดใช้งาน ส่วน -1 คือไม่จำกัด', static function (): void {
    [$db, $users, $userId] = quotaFixture();
    $quota = new QuotaChecker($users);

    // fixture ตั้ง ftp_users = 0
    $disabled = $quota->canCreate($userId, 'ftp_user', 1);
    assertTrue(!$disabled['ok'], 'โควตา 0 ต้องสร้างไม่ได้');
    assertTrue(str_contains($disabled['message'], 'ปิดการใช้งาน'), 'ต้องบอกว่าปิดไว้ ไม่ใช่บอกว่าเต็ม');

    $users->updateQuota($userId, ['domains' => -1]);
    $unlimited = $quota->canCreate($userId, 'domain', 9999);

    assertTrue($unlimited['ok'], 'โควตา -1 ต้องสร้างได้ไม่จำกัด');
    assertSame(-1, $unlimited['limit'], 'ต้องรายงาน limit เป็น -1 ให้หน้าจอแสดงว่า "ไม่จำกัด"');
});

test('ทรัพยากรที่ไม่มีคอลัมน์โควตาต้องไม่ถูกปฏิเสธเหมือนโควตา 0', static function (): void {
    // redirect ไม่มีคอลัมน์ quota_redirects — "ไม่ได้จำกัดไว้" ต่างจาก "จำกัดเป็น 0"
    // ถ้าแยกไม่ออก การเพิ่มชนิดทรัพยากรใหม่จะทำให้ของเดิมพังเงียบ ๆ
    [$db, $users, $userId] = quotaFixture();
    $quota = new QuotaChecker($users);

    $result = $quota->canCreate($userId, 'redirect', 1);

    assertTrue($result['ok'], 'ทรัพยากรที่ไม่ได้ตั้งโควตาไว้ต้องสร้างได้');
    assertSame(-1, $result['limit'], 'ต้องรายงานว่าไม่จำกัด ไม่ใช่ 0');
});

test('ผู้ใช้ทุกคนมีโควตาเสมอ — ช่องโหว่ "ไม่พบลูกค้าจึงปล่อยผ่าน" ต้องเป็นไปไม่ได้', static function (): void {
    // ก่อน migration 0005 โควตาอยู่ตาราง customers แยกต่างหาก · webadmin ที่สร้างจาก
    // CLI หรือ API จะไม่มีแถวลูกค้า แล้ว checkOwnerCanCreate() ก็ปล่อยผ่านทุกคำขอ
    // แบบไม่จำกัด — ทดสอบจริงแล้วสร้างได้ 999 โดเมนโดยไม่มีอะไรขวาง
    //
    // ตอนนี้โควตาเป็นคอลัมน์บนแถว users เดียวกับที่ใช้ล็อกอิน สภาพนั้นจึงสร้างไม่ได้อีก
    [$db, $users, $userId] = quotaFixture();
    $quota = new QuotaChecker($users);

    $plainId = $users->create('plainweb', 'Plain-Webadmin-Pass-22', Permissions::WEBADMIN);

    $result = $quota->checkOwnerCanCreate($plainId, 'domain', 9999);

    assertTrue(!$result['ok'], 'webadmin ที่สร้างด้วย create() ธรรมดาต้องยังถูกจำกัดโควตา');
    assertTrue($result['limit'] > 0, 'ต้องมีค่าโควตาเริ่มต้นจากตาราง ไม่ใช่ไม่มีโควตาเลย');

    // และเจ้าของที่มีบัญชีจริงก็ถูกจำกัดตามปกติ
    assertTrue(
        !$quota->checkOwnerCanCreate($userId, 'domain', 9999)['ok'],
        'เจ้าของที่เป็นลูกค้าจริงต้องถูกจำกัดตามโควตา',
    );
});

test('บัญชีผู้ดูแลต้องไม่ถูกจำกัดโควตา แม้เคยเป็นลูกค้ามาก่อน', static function (): void {
    // เจอจากการทดสอบบนเครื่องจริง: ลูกค้าที่ถูกเลื่อนเป็น superadmin ยังติดโควตาเดิมค้างอยู่
    // (migration ตั้ง -1 ให้เฉพาะตอนย้ายข้อมูล ไม่ได้ตามไปแก้ตอนเปลี่ยนบทบาททีหลัง)
    // แล้วแก้ไม่ได้ด้วย เพราะเส้นทางจัดการโควตารับเฉพาะบัญชี webadmin โดยตั้งใจ
    // — กลายเป็นทางตัน · กฎจึงต้องมาจากบทบาท ไม่ใช่จากตัวเลขที่เก็บไว้
    [$db, $users, $userId] = quotaFixture();
    $quota = new QuotaChecker($users);

    // ตอนเป็นลูกค้า: ติดโควตาตามปกติ
    assertTrue(!$quota->checkOwnerCanCreate($userId, 'domain', 99)['ok'], 'ลูกค้าต้องติดโควตา');

    $users->setRole($userId, Permissions::SUPERADMIN);

    $result = $quota->checkOwnerCanCreate($userId, 'domain', 99);
    assertTrue($result['ok'], 'ผู้ดูแลต้องไม่ติดโควตาที่ค้างมาจากตอนเป็นลูกค้า');
    assertSame(-1, $result['limit'], 'ต้องรายงานว่าไม่จำกัด');

    // และลดกลับมาเป็นลูกค้าแล้วต้องติดโควตาเหมือนเดิมทันที
    $users->setRole($userId, Permissions::WEBADMIN);
    assertTrue(!$quota->checkOwnerCanCreate($userId, 'domain', 99)['ok'], 'ลดบทบาทกลับแล้วต้องติดโควตาอีก');
});

test('สถานะบริการที่ไม่ใช่ active ต้องสร้างของใหม่ไม่ได้', static function (): void {
    [$db, $users, $userId] = quotaFixture();
    $quota = new QuotaChecker($users);

    foreach (['suspended', 'expired'] as $status) {
        $users->setServiceStatus($userId, $status);

        $result = $quota->checkOwnerCanCreate($userId, 'domain', 1);

        assertTrue(!$result['ok'], "ลูกค้าสถานะ {$status} ต้องสร้างของใหม่ไม่ได้");
    }

    $users->setServiceStatus($userId, 'active');
    assertTrue($quota->checkOwnerCanCreate($userId, 'domain', 1)['ok'], 'กลับมา active แล้วต้องสร้างได้อีก');
});
