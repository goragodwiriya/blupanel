<?php

declare(strict_types=1);

/**
 * ฟอร์มสร้างฐานข้อมูล — `/api/v2/databases`
 *
 * **เจอจากการใช้งานจริง:** ฟอร์มให้พิมพ์ชื่อผู้ใช้ฐานข้อมูลเอง ซึ่งเปิดปัญหาสามชั้น
 * ที่ผู้ใช้มองไม่เห็นเลยจนกว่าจะสาย:
 *
 *   1. ชื่อที่พิมพ์อาจเป็นผู้ใช้ของลูกค้ารายอื่น หรือชื่อที่ไม่มีอยู่จริง
 *   2. ชื่อผู้ใช้ปริยายถูกคิดจากชื่อฐาน**ก่อนเติมคำนำหน้า** ลูกค้าสองรายที่ตั้งชื่อฐาน
 *      ว่า `shop` เหมือนกันจึงไปชนกันที่ `shop_user` — รายที่สองสร้างไม่ได้พร้อม
 *      ข้อความที่พูดถึงชื่อที่เขาไม่เคยพิมพ์
 *   3. โควตาถูกตรวจจาก**เว็บไซต์** ฐานข้อมูลที่ไม่ผูกกับเว็บจึงข้ามการตรวจไปทั้งหมด
 *
 * และคำสั่งหน้าจอหลังบันทึกก็ไม่ครบตามมาตรฐาน: ไม่มีแถบแจ้งผล และหน้าต่างรหัสผ่าน
 * ส่งคีย์ `content` ซึ่ง `ResponseHandler` ไม่รู้จัก — หน้าต่างจึงเปิดมาว่างเปล่า
 * พร้อมรหัสที่ไม่มีที่อื่นให้ดูย้อนหลังอีกเลย
 */

use Phpcp\Http\V2\DatabasesController;
use Phpcp\Kernel\Ctx;
use Phpcp\Kernel\Router;
use Phpcp\Security\Permissions;

group('ฐานข้อมูล — ฟอร์มสร้างและคำสั่งหน้าจอ');

/** ตัวควบคุมจริงพร้อมของครบชุด (แปลภาษาได้) */
function databasesController(): DatabasesController
{
    static $controller = null;

    if ($controller === null) {
        $app = ApiHarness::boot()->app;
        $controller = new DatabasesController($app, new Ctx($app), new Router());
    }

    return $controller;
}

/**
 * คำสั่งหน้าจอที่ store() ส่งออกไปจริง — เรียกผ่าน revealed() ตัวเดียวกับทุกหน้าในระบบ
 *
 * ประกอบเองไม่ได้แล้วเพราะไม่มีเมท็อดกลางทางให้เรียก · อ่านจาก body ที่ส่งออกจริง
 * แทน ซึ่งแม่นกว่าด้วย — ลำดับที่ผู้ใช้เจอคือลำดับในไฟล์ JSON นี้
 *
 * @param array<string,mixed> $result ผลลัพธ์จาก capability db.create
 * @return list<array<string,mixed>>
 */
function databaseCreatedActions(array $result): array
{
    $method = new ReflectionMethod(databasesController(), 'databaseCreated');
    $method->setAccessible(true);

    $response = $method->invoke(databasesController(), $result, 'Database ' . $result['name'] . ' created');

    return json_decode($response->body(), true)['actions'] ?? [];
}

/**
 * @param array<string,mixed> $result
 * @return list<string>
 */
function databaseActionKinds(array $result): array
{
    return array_map(
        static fn (array $action): string => $action['type'] === 'modal'
            ? 'modal:' . $action['action']
            : (string) $action['type'],
        databaseCreatedActions($result),
    );
}

test('คำสั่งหลังสร้างต้องครบตามมาตรฐาน — แจ้งผล โชว์รหัส แล้วโหลดตารางใหม่', static function (): void {
    /*
     * ลำดับสำคัญสองข้อ:
     *
     *   1. แถบแจ้งผลต้องมา**ก่อน**หน้าต่างรหัสผ่าน ไม่งั้นถูกบังจนไม่มีใครเห็น
     *      (กฎเดียวกับกล่องจดหมาย ซึ่งเจอปัญหานี้มาก่อน)
     *   2. **ห้ามมี `modal:close` นำหน้า `modal:show`** — Modal.hide() ตั้งเวลาล้าง
     *      เนื้อในของตัวเองไว้ 150ms ซึ่งมาถึงหลังเนื้อหาใหม่ถูกใส่ไปแล้ว หน้าต่างจึง
     *      เปิดขึ้นมาว่างเปล่าพร้อมรหัสผ่านที่หายไปด้วย · นี่คืออาการ "สร้างฐานข้อมูล
     *      แล้วไม่เห็นรหัสผ่าน" ที่เจอจากการใช้งานจริง · การ show เฉย ๆ คือการสลับ
     *      เนื้อในของหน้าต่างเดิม ซึ่งเป็นสิ่งที่ตั้งใจไว้แต่แรก
     */
    assertSame(
        ['notification', 'modal:show', 'refresh'],
        databaseActionKinds(['name' => 'alice_shop', 'username' => 'alice_shop_user', 'password' => 'S3cret']),
        'ต้องแจ้งผล → โชว์รหัส (สลับเนื้อในฟอร์ม ไม่ใช่ปิดแล้วเปิดใหม่) → โหลดตารางใหม่',
    );
});

test('หน้าต่างรหัสผ่านต้องใช้คีย์ที่ ResponseHandler อ่านได้จริง', static function (): void {
    /*
     * `ResponseHandler.handleModalShow()` อ่าน `html` หรือ `template` เท่านั้น ·
     * ของเดิมส่ง `content` หน้าต่างจึงเปิดมาว่างเปล่า และรหัสที่ระบบสุ่มให้ก็หายไป
     * พร้อมกัน — ไม่มีที่ไหนให้ดูย้อนหลังอีกเลยเพราะ panel ไม่เก็บมันไว้
     */
    $actions = databaseCreatedActions(
        ['name' => 'alice_shop', 'username' => 'alice_shop_user', 'password' => 'S3cretGenerated9'],
    );

    $modal = $actions[1];

    assertTrue(isset($modal['html']), 'ต้องส่งเนื้อหาด้วยคีย์ html');
    assertTrue(!isset($modal['content']), 'คีย์ content ไม่มีใครอ่าน — ต้องไม่ใช้');
    assertTrue(str_contains((string) $modal['html'], 'S3cretGenerated9'), 'รหัสต้องอยู่ในหน้าต่างนั้น');
    assertTrue(str_contains((string) $modal['html'], 'alice_shop_user'), 'ต้องบอกด้วยว่ารหัสนี้ของผู้ใช้ไหน');

    // ปิดเองได้ด้วยปุ่มที่มีป้าย ไม่ใช่แค่กากบาทมุมบน · และคัดลอกได้โดยไม่ต้องลากเมาส์
    // คลุมสตริงสุ่ม ซึ่งเป็นจุดที่รหัสขาดหายไปทีละตัวโดยไม่มีใครรู้
    assertTrue(str_contains((string) $modal['html'], 'closeModal'), 'ต้องมีปุ่มปิดที่มีป้ายกำกับ');
    assertTrue(str_contains((string) $modal['html'], 'copyToClipboard'), 'ต้องมีปุ่มคัดลอกค่า');
});

test('ไม่มีรหัสผ่านในคำตอบ ต้องไม่เปิดหน้าต่างเปล่า', static function (): void {
    // ไม่มีอะไรต้องอ่านครั้งเดียว = ฟอร์มบันทึกธรรมดา · ปิดหน้าต่างไปตามปกติ
    assertSame(
        ['modal:close', 'notification', 'refresh'],
        databaseActionKinds(['name' => 'alice_shop', 'username' => 'alice_shop_user', 'password' => '']),
        'ไม่มีรหัสก็ไม่ควรมีหน้าต่างอะไรเปิดขึ้นมา',
    );
});

test('รายการบัญชีกับเว็บไซต์ต้องอยู่ในชั้น data ไม่ใช่ meta', static function (): void {
    /*
     * `meta.*` ผูกกับ `data-for`/`data-text` ของ Now.js ไม่ได้ — คอมโพเนนต์เห็นเฉพาะ
     * ชั้น `data` · รายการที่ใส่ไว้ใน meta จะกลายเป็น <select> ว่างเปล่าโดยไม่มี
     * ข้อผิดพลาดอะไรให้เห็นเลย (เสียเวลาไปแล้วหนึ่งรอบในหน้า Mailboxes)
     */
    $harness = ApiHarness::boot();
    $harness->createUser('dbadmin', 'Db-Admin-Pass-11', Permissions::SUPERADMIN);
    $harness->forget();
    $harness->clearRateLimits();
    $harness->request('GET', '/api/v2/session');
    $harness->request('POST', '/api/v2/session', ['username' => 'dbadmin', 'password' => 'Db-Admin-Pass-11']);

    $now = time();
    $ownerId = $harness->app->db()->insert('users', [
        'username' => 'dbcust',
        'password_hash' => password_hash('x', PASSWORD_DEFAULT),
        'role' => Permissions::WEBADMIN,
        'totp_enabled' => 0, 'must_change_password' => 0, 'status' => 'active', 'failed_attempts' => 0,
        'email' => '', 'service_status' => 'active', 'uid' => 0, 'gid' => 0,
        'quota_domains' => -1, 'quota_subdomains' => -1, 'quota_aliases' => -1, 'quota_emails' => -1,
        'quota_databases' => -1, 'quota_ftp_users' => -1, 'disk_quota_mb' => -1, 'disk_used_mb' => 0,
        'system_user' => 'dbcust', 'created_at' => $now, 'updated_at' => $now,
    ]);

    $response = $harness->request('GET', '/api/v2/databases/form');
    $data = $response->json['data'] ?? [];

    assertSame(200, $response->status, 'ฟอร์มต้องเปิดได้');
    assertTrue(isset($data['accounts']), 'รายการบัญชีต้องอยู่ใน data');
    assertTrue(isset($data['sites']), 'รายการเว็บไซต์ต้องอยู่ใน data');

    $accounts = array_column($data['accounts'], 'username', 'id');

    assertSame('dbcust', $accounts[$ownerId] ?? '', 'ต้องมีบัญชีโฮสติ้งในรายการ');
    assertTrue(
        str_contains((string) ($data['accounts'][0]['label'] ?? '')  , 'dbcust_'),
        'ป้ายต้องบอกคำนำหน้าที่ชื่อฐานข้อมูลจะได้ ไม่งั้นผู้ใช้พิมพ์ shop แล้วประหลาดใจ',
    );

    // ตัวเลือก "ไม่ผูกกับเว็บไซต์" ต้องมาจากเซิร์ฟเวอร์ — `data-for` เขียนทับลูกทั้งหมด
    // ของ <select> ตัวเลือกที่เขียนนิ่งไว้ในเทมเพลตจะหายตอนข้อมูลมาถึง
    assertSame(0, (int) ($data['sites'][0]['id'] ?? -1), 'ตัวเลือกแรกต้องเป็น "ไม่ผูกกับเว็บไซต์"');
});

test('ลูกค้าต้องเห็นบัญชีของตัวเองบัญชีเดียวในฟอร์ม', static function (): void {
    // รายการที่แคบแล้วเป็นความสะดวกของคนกรอกฟอร์ม ไม่ใช่ด่าน — ด่านจริงอยู่ที่
    // capability (ดู DbCreate::assertOwnerAccess) แต่รายการที่กว้างเกินก็สื่อผิด
    // ว่าลูกค้าเลือกบัญชีคนอื่นได้
    $harness = ApiHarness::boot();
    $harness->createUser('dbowner', 'Db-Owner-Pass-22', Permissions::WEBADMIN);
    $harness->forget();
    $harness->clearRateLimits();
    $harness->request('GET', '/api/v2/session');
    $harness->request('POST', '/api/v2/session', ['username' => 'dbowner', 'password' => 'Db-Owner-Pass-22']);

    $id = (int) $harness->app->db()->value('SELECT id FROM users WHERE username = :u', ['u' => 'dbowner'], 0);
    $harness->app->db()->update('users', ['system_user' => 'dbowner'], ['id' => $id]);

    $data = $harness->request('GET', '/api/v2/databases/form')->json['data'] ?? [];

    assertSame(1, count($data['accounts'] ?? []), 'ลูกค้าต้องเห็นบัญชีเดียว');
    assertSame($id, (int) ($data['accounts'][0]['id'] ?? 0), 'และต้องเป็นของตัวเอง');
    assertSame($id, (int) ($data['owner_user_id'] ?? 0), 'ค่าเริ่มต้นต้องเป็นบัญชีของตัวเองด้วย');
});

test('ฟอร์มต้องไม่มีช่องพิมพ์ชื่อผู้ใช้ฐานข้อมูลเอง', static function (): void {
    /*
     * ชื่อผู้ใช้ถูกตั้งจากชื่อฐานที่เติมคำนำหน้าแล้วเสมอ (ดู DbCreate::dedicatedUser)
     * · ช่องให้พิมพ์เองคือทางเดียวที่ทำให้ฐานข้อมูลใหม่ไปผูกกับผู้ใช้ของลูกค้ารายอื่นได้
     */
    $form = (string) file_get_contents(PHPCP_ROOT . '/public/assets/spa/templates/database-form.html');

    assertTrue(!str_contains($form, 'name="username"'), 'ต้องไม่มีช่อง username ให้พิมพ์');
    assertTrue(str_contains($form, 'name="owner_user_id"'), 'ต้องมีตัวเลือกบัญชีเจ้าของแทน');
    assertTrue(str_contains($form, 'data-for="a in accounts"'), 'ตัวเลือกบัญชีต้องผูกกับรายการจากเซิร์ฟเวอร์');
});
