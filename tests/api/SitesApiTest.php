<?php

declare(strict_types=1);

/**
 * สัญญาของทรัพยากร sites / domains / dns-records / php-versions — PLAN-V2 เฟส B3.1
 *
 * เทสต์ชุดนี้เน้นสองเรื่องที่พลาดแล้วเจ็บที่สุด:
 *   1. **การแบ่งขอบเขตตามเจ้าของ** — ลูกค้ารายหนึ่งต้องไม่เห็นและแตะเว็บของรายอื่นได้เลย
 *      แม้จะเดา id ถูก (SECURITY §2.5 กัน IDOR) และต้องได้ 404 ไม่ใช่ 403
 *      เพราะ 403 บอกเป็นนัยว่า "มีเว็บนี้อยู่จริงนะ แต่ไม่ใช่ของคุณ"
 *   2. **รูปร่างของข้อมูล** — ค่าที่คืนต้องเป็นค่าดิบ (ตัวเลข/timestamp) ไม่ใช่ข้อความ
 *      ที่จัดรูปแบบมาแล้ว ซึ่งเป็นสิ่งที่ view เดิมทำและทำให้ SPA ใช้ต่อไม่ได้
 *
 * ไม่ต้องมี agent ทำงานอยู่: เคสที่ต้องสั่ง capability จะได้ 503 AGENT_UNAVAILABLE
 * ซึ่งก็เป็นส่วนหนึ่งของสัญญาที่ต้องตรวจเหมือนกัน
 */

use Phpcp\Http\ApiProblem;
use Phpcp\Security\Permissions;

group('REST API v2 — สัญญาของ sites, domains และ DNS');

/** สภาพแวดล้อมของชุดนี้ พร้อมข้อมูลตัวอย่างที่คุมได้ทั้งหมด */
function sitesHarness(): ApiHarness
{
    static $harness = null;

    if ($harness !== null) {
        return $harness;
    }

    $harness = ApiHarness::boot();
    $harness->createUser('siteadmin', 'Sites-Admin-Password-11', Permissions::SUPERADMIN);
    $ownerId = $harness->createHostingUser('siteowner', 'Sites-Owner-Password-22', Permissions::WEBADMIN);
    $strangerId = $harness->createHostingUser('sitestranger', 'Sites-Other-Password-33', Permissions::WEBADMIN);

    $db = $harness->app->db();
    $now = time();

    // เว็บของ siteowner และเว็บของลูกค้าอีกราย — ใช้ตรวจการแบ่งขอบเขต
    // ตั้งแต่ migration 0005 เว็บทุกแห่งต้องมีเจ้าของ จึงไม่มี "เว็บไร้เจ้าของ" ให้ทดสอบอีก
    $owned = $db->insert('sites', [
        'primary_domain' => 'owned.example.com',
        'docroot' => '/srv/phpcp/sites/owned.example.com/public',
        'php_version' => '8.4',
        'ssl_mode' => 'forced',
        'status' => 'active',
        'disk_used_mb' => 2480,
        'owner_user_id' => $ownerId,
        'created_at' => $now - 86400,
        'updated_at' => $now,
    ]);

    $other = $db->insert('sites', [
        'primary_domain' => 'other.example.com',
        'docroot' => '/srv/phpcp/sites/other.example.com/public',
        'php_version' => '8.3',
        'status' => 'suspended',
        'disk_used_mb' => 15,
        'owner_user_id' => $strangerId,
        'created_at' => $now - 3600,
        'updated_at' => $now,
    ]);

    foreach ([[$owned, 'owned.example.com', 'primary'], [$owned, 'shop.owned.example.com', 'subdomain'],
        [$other, 'other.example.com', 'primary']] as [$siteId, $domain, $type]) {
        $db->insert('domains', [
            'site_id' => $siteId,
            'domain' => $domain,
            'type' => $type,
            'created_at' => $now,
        ]);
    }

    return $harness;
}

function sitesLogin(string $username, string $password): ApiHarness
{
    $harness = sitesHarness();
    $harness->forget();
    $harness->clearRateLimits();
    $harness->request('GET', '/api/v2/session');

    $login = $harness->request('POST', '/api/v2/session', ['username' => $username, 'password' => $password]);

    if ($login->status !== 200) {
        throw new RuntimeException("เตรียมเทสต์ไม่สำเร็จ: ล็อกอินได้ {$login->status}");
    }

    return $harness;
}

/** id ของเว็บไซต์จากชื่อโดเมน — ไม่ผูกกับ auto increment ที่อาจเปลี่ยน */
function sitesIdOf(string $domain): int
{
    return (int) sitesHarness()->app->db()->value(
        'SELECT id FROM sites WHERE primary_domain = :d',
        ['d' => $domain],
        0,
    );
}

test('รายการเว็บไซต์คืนค่าดิบพร้อม meta ของการแบ่งหน้า', static function (): void {
    $harness = sitesLogin('siteadmin', 'Sites-Admin-Password-11');

    $response = $harness->request('GET', '/api/v2/sites');

    assertSame(200, $response->status, 'ผู้ดูแลระบบต้องดูรายการเว็บไซต์ได้');
    assertSame(2, $response->json['meta']['total'] ?? 0, 'ต้องเห็นเว็บไซต์ทั้งสองแห่ง');
    assertSame(50, $response->json['meta']['per_page'] ?? 0, 'ค่าเริ่มต้นของ per_page ต้องเป็น 50 ตาม §4.5');

    $first = $response->json['data'][0];

    // ค่าดิบเท่านั้น — ถ้าเป็นข้อความอย่าง "2.4 GB" ฝั่ง SPA จะเรียงลำดับหรือคำนวณต่อไม่ได้
    // หน่วยคือไบต์เสมอ (ไม่ใช่ MB ที่ฐานข้อมูลเก็บ) เพื่อให้ `data-format="bytes"` ใช้ได้ตรง ๆ
    assertTrue(is_int($first['disk_used']), 'พื้นที่ดิสก์ต้องเป็นตัวเลข ไม่ใช่ข้อความที่จัดรูปแบบแล้ว');
    assertSame(15 * 1048576, $first['disk_used'], 'ค่าที่คืนต้องเป็นไบต์ (MB ในฐานข้อมูล × 1048576) — เว็บนี้เรียงมาก่อนตาม primary_domain');
    assertTrue(is_int($first['created_at']), 'เวลาต้องเป็น unix timestamp ไม่ใช่ "2 วันที่แล้ว"');
    assertTrue(is_int($first['domain_count']), 'จำนวนโดเมนต้องมาด้วยและเป็นตัวเลข');
    assertTrue(str_starts_with((string) $first['docroot'], '/'), 'docroot ต้องเป็นเส้นทางเต็ม');

    // เส้นทางต้องมีชื่อเจ้าของอยู่จริง — เคยหลุดเป็น /srv/phpcp/users//domains/… บน API จริง
    // เพราะ query ที่ป้อน SiteResource ไม่ได้ join users มาด้วย แล้วชื่อเจ้าของกลายเป็นค่าว่าง
    foreach ($response->json['data'] as $site) {
        assertTrue(
            !str_contains((string) $site['docroot'], '//'),
            "docroot ของ {$site['domain']} มี // ซ้อน แปลว่าชื่อเจ้าของหายไป: {$site['docroot']}",
        );
        assertTrue(
            !str_contains((string) $site['root'], '//'),
            "บ้านของ {$site['domain']} มี // ซ้อน: {$site['root']}",
        );
    }
});

test('ค้นหา กรอง และเรียงลำดับทำงานตามสเปก', static function (): void {
    $harness = sitesLogin('siteadmin', 'Sites-Admin-Password-11');

    $search = $harness->request('GET', '/api/v2/sites', query: ['q' => 'owned']);
    assertSame(1, $search->json['meta']['total'] ?? 0, 'ค้นหาต้องกรองผลลัพธ์จริง');

    $suspended = $harness->request('GET', '/api/v2/sites', query: ['status' => 'suspended']);
    assertSame(1, $suspended->json['meta']['total'] ?? 0, 'กรองตามสถานะต้องทำงาน');
    assertSame('other.example.com', $suspended->json['data'][0]['domain'], 'ต้องได้เว็บที่ถูกระงับ');

    // `-field` = จากมากไปน้อย ตาม §4.5 · ชื่อฟิลด์ฝั่ง API คือ `disk_used` (ไบต์) แม้ในฐานข้อมูล
    // จะเก็บเป็น disk_used_mb — SitesController::SORT_COLUMN แปลชื่อให้ตอนต่อ ORDER BY
    $sorted = $harness->request('GET', '/api/v2/sites', query: ['sort' => '-disk_used']);
    assertSame('owned.example.com', $sorted->json['data'][0]['domain'], 'เรียงจากมากไปน้อยต้องได้เว็บที่ใช้ดิสก์เยอะสุดก่อน');

    // ฟิลด์ที่ไม่อยู่ใน allowlist ต้องถูกเมินเฉย ไม่ใช่หลุดเข้า ORDER BY
    $injected = $harness->request('GET', '/api/v2/sites', query: ['sort' => 'id; DROP TABLE sites']);
    assertSame(200, $injected->status, 'ค่า sort ที่ไม่รู้จักต้องตกกลับไปใช้ค่าปริยาย ไม่ใช่พัง');
    assertTrue(
        (int) $harness->app->db()->value('SELECT count(*) FROM sites') === 2,
        'ตาราง sites ต้องยังอยู่ครบ — ค่า sort ห้ามหลุดเข้า SQL',
    );

    $paged = $harness->request('GET', '/api/v2/sites', query: ['per_page' => '1', 'page' => '2']);
    assertSame(1, count($paged->json['data']), 'per_page ต้องถูกใช้จริง');
    assertSame(2, $paged->json['meta']['total_pages'] ?? 0, 'ต้องคำนวณจำนวนหน้าให้');

    $capped = $harness->request('GET', '/api/v2/sites', query: ['per_page' => '9999']);
    assertSame(200, $capped->json['meta']['per_page'] ?? 0, 'per_page ต้องถูกบีบไว้ที่เพดาน 200');
});

test('ลูกค้าเห็นเฉพาะเว็บของตัวเอง และแตะของคนอื่นไม่ได้เลย', static function (): void {
    $harness = sitesLogin('siteowner', 'Sites-Owner-Password-22');

    $list = $harness->request('GET', '/api/v2/sites');
    assertSame(1, $list->json['meta']['total'] ?? 0, 'ต้องเห็นเฉพาะเว็บของตัวเอง');
    assertSame('owned.example.com', $list->json['data'][0]['domain'], 'ต้องเป็นเว็บของตัวเอง');

    $otherId = sitesIdOf('other.example.com');

    // เดา id ถูกก็ต้องไม่ได้อะไร และต้องเป็น 404 ไม่ใช่ 403 (403 = ยืนยันว่ามีอยู่จริง)
    $show = $harness->request('GET', '/api/v2/sites/' . $otherId);
    assertSame(404, $show->status, 'เว็บของคนอื่นต้องเหมือนไม่มีอยู่จริง');
    assertSame(ApiProblem::NotFound->value, $show->errorCode(), 'ต้องเป็น NOT_FOUND ไม่ใช่ FORBIDDEN');

    foreach ([['PATCH', ''], ['DELETE', ''], ['PUT', '/php-version'], ['PUT', '/suspension']] as [$method, $suffix]) {
        $response = $harness->request($method, '/api/v2/sites/' . $otherId . $suffix, ['php_version' => '8.4']);
        assertTrue(
            in_array($response->status, [403, 404], true),
            "{$method} /sites/{id}{$suffix} ของคนอื่นต้องถูกปฏิเสธ แต่ได้ {$response->status}",
        );
    }
});

test('ผู้ใช้ที่ไม่มีสิทธิ์สร้างเว็บไซต์ต้องได้ 403 จาก middleware', static function (): void {
    // webadmin มี site.edit แต่ไม่มี site.create — ต้องถูกตัดที่ชั้น Authorize
    // ก่อนถึง controller ด้วยซ้ำ
    $harness = sitesLogin('siteowner', 'Sites-Owner-Password-22');

    $response = $harness->request('POST', '/api/v2/sites', [
        'domain' => 'newsite.example.com',
        'php_version' => '8.4',
    ]);

    assertSame(403, $response->status, 'ไม่มีสิทธิ์สร้างต้องได้ 403');
    assertSame(ApiProblem::Forbidden->value, $response->errorCode(), 'ต้องเป็นรหัส FORBIDDEN');
});

test('เว็บไซต์เดียวคืนโดเมนของมันมาด้วย', static function (): void {
    $harness = sitesLogin('siteadmin', 'Sites-Admin-Password-11');
    $id = sitesIdOf('owned.example.com');

    $response = $harness->request('GET', '/api/v2/sites/' . $id);

    assertSame(200, $response->status, 'ต้องดูรายละเอียดได้');
    assertSame(2, count($response->data('domains')), 'ต้องมีโดเมนทั้งสองรายการ');

    $subdomain = null;
    foreach ($response->data('domains') as $domain) {
        if ($domain['type'] === 'subdomain') {
            $subdomain = $domain;
        }
    }

    assertTrue($subdomain !== null, 'ต้องมีโดเมนย่อยอยู่ในผลลัพธ์');
    assertSame(true, $subdomain['removable'], 'โดเมนย่อยต้องบอกว่าลบได้');

    foreach ($response->data('domains') as $domain) {
        if ($domain['type'] === 'primary') {
            assertSame(false, $domain['removable'], 'โดเมนหลักต้องบอกว่าลบเดี่ยว ๆ ไม่ได้');
        }
    }
});

test('GET /sites/0 คืนค่าเริ่มต้นสำหรับสร้างเว็บใหม่ ไม่ใช่ 404', static function (): void {
    $harness = sitesLogin('siteadmin', 'Sites-Admin-Password-11');

    $response = $harness->request('GET', '/api/v2/sites/0');

    assertSame(200, $response->status, 'id=0 เป็นค่าจอง ไม่ใช่เว็บไซต์ที่ไม่มีอยู่');
    assertTrue(is_int($response->data('owner_user_id')), 'ต้องมีเจ้าของโดยปริยายให้ฟอร์มกรอกไว้ล่วงหน้า');
    assertTrue(is_bool($response->data('has_pointer_roots')), 'ต้องบอกได้ว่ามีโฟลเดอร์แม่ให้เลือกหรือไม่');
    assertTrue(is_array($response->data('options')['pointer_root'] ?? null), 'ต้องมีตัวเลือกโฟลเดอร์แม่ให้ select (ว่างได้ถ้าไม่ได้ตั้งค่า)');

    $owners = $response->data('options')['owner_user_id'] ?? null;
    assertTrue(is_array($owners) && count($owners) >= 2, 'ผู้ดูแลต้องเห็นบัญชีโฮสติ้งทั้งหมดให้เลือกเป็นเจ้าของ');
    foreach ($owners as $owner) {
        assertTrue(isset($owner['value'], $owner['text']), 'แต่ละตัวเลือกต้องมี value/text เหมือนตัวเลือก php_version');
    }
});

test('GET /sites/0 — ลูกค้าเห็นตัวเลือกเจ้าของแค่ตัวเอง', static function (): void {
    $harness = sitesLogin('siteowner', 'Sites-Owner-Password-22');

    $response = $harness->request('GET', '/api/v2/sites/0');

    assertSame(200, $response->status, 'ลูกค้าดู default ของฟอร์มสร้างเว็บได้ด้วย site.view แม้กดปุ่มสร้างจริงไม่ได้');

    $owners = $response->data('options')['owner_user_id'] ?? null;
    assertTrue(is_array($owners) && count($owners) === 1, 'ลูกค้าต้องเลือกเจ้าของเป็นใครอื่นไม่ได้นอกจากตัวเอง');
    assertSame($response->data('owner_user_id'), $owners[0]['value'] ?? null, 'ตัวเลือกเดียวที่มีต้องตรงกับตัวเอง');
});

test('ลบโดเมนหลักแยกไม่ได้ ต้องได้ 409 พร้อมเหตุผล', static function (): void {
    $harness = sitesLogin('siteadmin', 'Sites-Admin-Password-11');

    $primaryId = (int) $harness->app->db()->value(
        "SELECT id FROM domains WHERE domain = 'owned.example.com'",
        [],
        0,
    );

    $response = $harness->request('DELETE', '/api/v2/domains/' . $primaryId);

    assertSame(409, $response->status, 'การขัดกับสถานะปัจจุบันต้องเป็น 409 ไม่ใช่ 422');
    assertSame(ApiProblem::Conflict->value, $response->errorCode(), 'ต้องเป็นรหัส CONFLICT');
});

test('zone file ต้องเดินผ่านชั้น HTTP ได้ทั้งอ่านและแทนที่ทั้งชุด', static function (): void {
    /*
     * **ยิงผ่านชั้น HTTP จริง ไม่ใช่เรียก capability ตรง ๆ**
     *
     * ขอบเขตไฟล์ตั้งค่าของเว็บไซต์เคยล้มด้วย 500 ตั้งแต่คำขอแรกเพราะชนิดของค่าเริ่มต้นใน
     * `Request::get()` — ความผิดพลาดในชั้นบาง ๆ ระหว่าง controller กับ agent ที่เทสต์
     * ระดับ capability มองไม่เห็นเลยสักตัว
     *
     * **agent ไม่ได้รันในแท่นทดสอบ** จึงตรวจได้ถึงแค่ว่าคำขอเดินไปถึง agent (503) หรือ
     * สำเร็จทั้งเส้น (200) · สิ่งที่ต้องไม่เกิดคือ 500
     */
    $harness = sitesLogin('siteadmin', 'Sites-Admin-Password-11');

    $domainId = (int) $harness->app->db()->value(
        "SELECT id FROM domains WHERE domain = 'owned.example.com'",
        [],
        0,
    );

    /*
     * การอ่านต้องไม่พังเมื่อ agent ไม่ตอบ — เครื่องที่ยังไม่เปิด `dns.enabled` ไม่มีไฟล์
     * zone อยู่เลยตามปกติ และนั่นต้องไม่ทำให้หน้าดูเรกคอร์ดใช้ไม่ได้ทั้งหน้า
     */
    $view = $harness->request('GET', '/api/v2/domains/' . $domainId . '/zone-file');

    assertSame(200, $view->status, 'อ่าน zone file ต้องได้ 200 แม้ agent จะไม่ตอบ · ได้ ' . $view->status);
    assertTrue(($view->data('content') ?? '') !== '', 'ต้องมีเนื้อไฟล์ให้ดูเสมอ');
    assertSame('generated', $view->data('source'), 'ไม่มีไฟล์บนดิสก์ต้องบอกตามจริงว่าเป็นค่าที่ประกอบขึ้น');
    assertSame(true, $view->data('no_file'), 'ต้องบอกให้หน้าจอขึ้นคำเตือนว่ายังไม่มีไฟล์');

    // เปิดเป็น Modal เหมือนไฟล์ตั้งค่าอื่นในระบบ ไม่ใช่แผงที่ซ่อนอยู่ในหน้า
    $actions = $view->json['actions'] ?? [];
    assertSame('modal', $actions[0]['type'] ?? '', 'ต้องสั่งเปิด Modal');
    assertSame('zone-file.html', $actions[0]['template'] ?? '', 'ต้องใช้เทมเพลตอ่านอย่างเดียว');

    // ช่องแก้ไขเป็นคนละ endpoint และคนละเทมเพลต — ของผู้ใช้ล้วน ไม่มี SOA/NS ที่ระบบสร้าง
    $form = $harness->request('GET', '/api/v2/domains/' . $domainId . '/zone-form');

    assertSame(200, $form->status, 'ช่องแก้ไขต้องเปิดได้ · ได้ ' . $form->status);
    assertSame(
        'zone-records-form.html',
        $form->json['actions'][0]['template'] ?? '',
        'ต้องใช้เทมเพลตของช่องแก้ไข',
    );
    /*
     * ตัดคอมเมนต์ก่อนตรวจ — คำอธิบายในหัวข้อความอธิบายว่า *ทำไม* SOA ถึงไม่อยู่ที่นี่
     * ถ้าไม่ตัด เทสต์จะฟ้องคำอธิบายที่ดีแทนที่จะฟ้องเรกคอร์ดที่ไม่ควรมี
     */
    $editable = implode("\n", array_filter(
        preg_split('/\R/', (string) $form->data('content')) ?: [],
        static fn (string $line): bool => !str_starts_with(ltrim($line), ';'),
    ));

    assertTrue(
        !str_contains($editable, 'SOA'),
        'ช่องแก้ไขต้องไม่มี SOA ให้แก้ เพราะระบบสร้างทับให้ทุกครั้งอยู่แล้ว: ' . $editable,
    );

    $save = $harness->request('PUT', '/api/v2/domains/' . $domainId . '/zone-file', [
        'content' => "@ IN A 203.0.113.5\n",
    ]);

    assertTrue(
        in_array($save->status, [200, 503], true),
        'บันทึกต้องได้ 200 หรือ 503 (ไม่มี agent ในแท่นทดสอบ) · ได้ ' . $save->status
            . ' ' . ($save->json['error']['message'] ?? ''),
    );

    // โดเมนที่ไม่มีอยู่ต้องได้ 404 ไม่ใช่ 500 — ด่านนี้อยู่ก่อนเรียก agent
    assertSame(
        404,
        $harness->request('PUT', '/api/v2/domains/999999/zone-file', ['content' => ''])->status,
        'โดเมนที่ไม่มีอยู่ต้องได้ 404',
    );
});

test('เพิ่ม DNS record ตรวจค่าตามชนิดจริง', static function (): void {
    $harness = sitesLogin('siteadmin', 'Sites-Admin-Password-11');

    $domainId = (int) $harness->app->db()->value(
        "SELECT id FROM domains WHERE domain = 'owned.example.com'",
        [],
        0,
    );

    $created = $harness->request('POST', '/api/v2/domains/' . $domainId . '/dns-records', [
        'type' => 'A',
        'name' => 'www',
        'value' => '203.0.113.10',
        'ttl' => 300,
    ]);

    assertSame(201, $created->status, 'สร้างสำเร็จต้องเป็น 201');
    assertTrue(($created->headers['Location'] ?? '') !== '', '201 ต้องแนบ Location ตาม §4.3');
    // คำตอบของคำสั่งไม่แนบทรัพยากรกลับมาแล้ว — อ่านค่าที่บันทึกจริงจากรายการ
    $records = $harness->request('GET', '/api/v2/domains/' . $domainId . '/dns-records');
    $saved = array_values(array_filter(
        $records->json['data'] ?? [],
        static fn (array $r): bool => (int) $r['id'] === (int) $created->data('record_id'),
    ));

    assertSame(300, $saved[0]['ttl'] ?? null, 'ต้องเก็บ TTL ตามที่ส่งมา');
    assertSame(null, $created->data('priority'), 'เรกคอร์ดที่ไม่ใช่ MX ต้องมี priority เป็น null ไม่ใช่ 0');

    // ใส่ IP ลงช่อง CNAME เป็นความผิดพลาดที่พบบ่อยที่สุดและ DNS จะรับไว้เงียบ ๆ
    $wrong = $harness->request('POST', '/api/v2/domains/' . $domainId . '/dns-records', [
        'type' => 'CNAME',
        'name' => 'bad',
        'value' => '203.0.113.10',
    ]);

    assertSame(422, $wrong->status, 'ค่าที่ไม่ตรงชนิดต้องถูกปฏิเสธ');
    assertSame(ApiProblem::ValidationError->value, $wrong->errorCode(), 'ต้องเป็นรหัส VALIDATION_ERROR');
    assertTrue(
        str_contains((string) ($wrong->json['error']['message'] ?? ''), 'A หรือ AAAA'),
        'ต้องบอกทางแก้ให้ด้วย ไม่ใช่แค่บอกว่าผิด',
    );

    // ชื่อโฮสต์ที่ไม่มีจุดเลยก็ไม่ใช่ชื่อที่ใช้ได้จริงใน zone file
    assertSame(
        422,
        $harness->request('POST', '/api/v2/domains/' . $domainId . '/dns-records', [
            'type' => 'CNAME',
            'name' => 'nodot',
            'value' => 'localhost',
        ])->status,
        'CNAME ที่ไม่มีโดเมนเต็มต้องถูกปฏิเสธ',
    );

    // MX ต้องได้ priority ปริยายเสมอ ไม่งั้น zone file ที่ส่งออกจะใช้ไม่ได้
    $mx = $harness->request('POST', '/api/v2/domains/' . $domainId . '/dns-records', [
        'type' => 'MX',
        'name' => '@',
        'value' => 'mail.example.com',
    ]);

    assertSame(201, $mx->status, 'MX ที่ถูกต้องต้องเพิ่มได้');

    $afterMx = $harness->request('GET', '/api/v2/domains/' . $domainId . '/dns-records');
    $savedMx = array_values(array_filter(
        $afterMx->json['data'] ?? [],
        static fn (array $r): bool => (int) $r['id'] === (int) $mx->data('record_id'),
    ));

    assertSame(10, $savedMx[0]['priority'] ?? null, 'MX ที่ไม่ระบุลำดับต้องได้ค่าปริยาย 10');

    $records = $harness->request('GET', '/api/v2/domains/' . $domainId . '/dns-records');
    assertSame(2, count($records->json['data']), 'ต้องเห็นเรกคอร์ดที่เพิ่มไปทั้งสอง');

    // ลบแล้วต้องหายจริง
    // ตอบ 200 พร้อม `actions` ไม่ใช่ 204 — 204 ไม่มีเนื้อคำตอบ หน้าจอจึงไม่รู้ว่า
    // ต้องโหลดตารางใหม่ แล้วแถวที่ลบไปแล้วจะค้างอยู่บนหน้าจอ
    $deleted = $harness->request('DELETE', '/api/v2/dns-records/' . $created->data('record_id'));

    assertSame(200, $deleted->status, 'ลบสำเร็จต้องเป็น 200 พร้อมคำสั่งหน้าจอ');
    assertSame(['notification', 'redirect'], $deleted->actionTypes(), 'ต้องแจ้งผลแล้วโหลดตารางใหม่');
    assertSame(
        1,
        count($harness->request('GET', '/api/v2/domains/' . $domainId . '/dns-records')->json['data']),
        'เรกคอร์ดที่ลบต้องหายไปจริง',
    );
});

test('zone file ออกมาเป็น JSON ไม่ใช่ไฟล์แนบ', static function (): void {
    $harness = sitesLogin('siteadmin', 'Sites-Admin-Password-11');

    $domainId = (int) $harness->app->db()->value(
        "SELECT id FROM domains WHERE domain = 'owned.example.com'",
        [],
        0,
    );

    $response = $harness->request('GET', '/api/v2/domains/' . $domainId . '/zone-file');

    assertSame(200, $response->status, 'ต้องดึง zone file ได้');
    assertTrue($response->isJson(), 'ต้องเป็น JSON — สัญญาของ v2 ไม่มีข้อยกเว้นให้ไฟล์แนบ');
    assertSame('owned.example.com.zone', $response->data('filename'), 'ต้องบอกชื่อไฟล์ให้ SPA เอาไปตั้งตอนดาวน์โหลด');
    assertTrue(str_contains((string) $response->data('content'), 'IN'), 'เนื้อหาต้องเป็น zone file จริง');
});

test('ลูกค้าเข้าถึง DNS ของโดเมนคนอื่นไม่ได้', static function (): void {
    $harness = sitesLogin('sitestranger', 'Sites-Other-Password-33');

    $domainId = (int) $harness->app->db()->value(
        "SELECT id FROM domains WHERE domain = 'owned.example.com'",
        [],
        0,
    );

    foreach ([
        ['GET', '/api/v2/domains/' . $domainId . '/dns-records'],
        ['GET', '/api/v2/domains/' . $domainId . '/zone-file'],
        ['POST', '/api/v2/domains/' . $domainId . '/dns-records'],
    ] as [$method, $path]) {
        $response = $harness->request($method, $path, ['type' => 'A', 'name' => 'x', 'value' => '1.2.3.4']);

        assertSame(404, $response->status, "{$method} {$path} ของคนอื่นต้องได้ 404");
    }

    // รายการโดเมนต้องมีแต่ของตัวเอง — แข็งแรงกว่าการตรวจว่า "รายการว่าง" เพราะ
    // รายการว่างผ่านได้แม้การกรองพังจนไม่คืนอะไรเลย
    $list = $harness->request('GET', '/api/v2/domains');
    $domains = array_column($list->json['data'] ?? [], 'domain');

    assertSame(['other.example.com'], $domains, 'ต้องเห็นเฉพาะโดเมนของเว็บตัวเอง');
    assertSame(1, $list->json['meta']['total'] ?? -1, 'จำนวนรวมต้องนับเฉพาะของตัวเอง');
});

test('คำสั่งที่ต้องใช้ agent ตอบ 503 เมื่อ agent ไม่ทำงาน', static function (): void {
    // เทสต์รันโดยไม่มี agentd — สิ่งที่ต้องพิสูจน์คือ "ล้มอย่างถูกต้อง":
    // 503 บอกว่าลองใหม่ได้เมื่อบริการกลับมา ต่างจาก 500 ที่แปลว่าคำสั่งนั้นพังเอง
    $harness = sitesLogin('siteadmin', 'Sites-Admin-Password-11');

    $response = $harness->request('POST', '/api/v2/sites', [
        'domain' => 'brandnew.example.com',
        'php_version' => '8.4',
    ]);

    assertSame(503, $response->status, 'agent ไม่ตอบต้องเป็น 503 ไม่ใช่ 500');
    assertSame(ApiProblem::AgentUnavailable->value, $response->errorCode(), 'ต้องเป็นรหัส AGENT_UNAVAILABLE');
    assertTrue($response->isJson() && !$response->looksLikeHtml(), 'ต้องยังเป็น JSON');
});

test('เส้นทางทั้งหมดของ B3.1 ตอบ JSON และมีรูปร่างตามสัญญา', static function (): void {
    $harness = sitesLogin('siteadmin', 'Sites-Admin-Password-11');
    $siteId = sitesIdOf('owned.example.com');

    $paths = [
        ['GET', '/api/v2/sites'],
        ['GET', '/api/v2/sites/' . $siteId],
        ['GET', '/api/v2/sites/' . $siteId . '/domains'],
        ['GET', '/api/v2/sites/999999'],
        ['GET', '/api/v2/domains'],
        ['GET', '/api/v2/domains/999999/dns-records'],
        ['GET', '/api/v2/dns-records/999999'],
        ['GET', '/api/v2/php-versions'],
    ];

    foreach ($paths as [$method, $path]) {
        $response = $harness->request($method, $path);

        assertTrue($response->isJson(), "{$method} {$path} ต้องตอบ JSON แต่ได้ " . $response->contentType());
        assertTrue(!$response->looksLikeHtml(), "{$method} {$path} มี HTML ปนออกมา");
        assertTrue(array_key_exists('ok', $response->json), "{$method} {$path} ต้องมีฟิลด์ ok");

        if (($response->json['ok'] ?? true) === false) {
            assertTrue(
                ApiProblem::tryFrom($response->errorCode()) !== null,
                "{$method} {$path} ใช้รหัสข้อผิดพลาดนอก enum: " . $response->errorCode(),
            );
        }
    }
});
