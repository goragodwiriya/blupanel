<?php

declare(strict_types=1);

/**
 * คำสั่งหน้าจอหลังบันทึกกล่องจดหมาย — `POST /api/v2/mailboxes`
 *
 * **เจอจากการใช้งานจริง:** เพิ่มอีเมลแล้ว "ไม่มีอะไรเกิดขึ้น" · สองอาการที่คนละสาเหตุ
 * แต่ผู้ใช้เห็นเป็นเรื่องเดียวกันคือ "ฟอร์มไม่ปิด/ไม่รู้ว่าสำเร็จ":
 *
 *   1. ไม่มีคำสั่ง `notification` เลย — Modal ปิดแล้วเงียบสนิท ไม่มีอะไรยืนยันผล
 *      ผู้ดูแลที่ไม่ได้จ้องตารางอยู่จึงกดบันทึกซ้ำ
 *   2. หน้าต่างรหัสผ่านเด้ง**ทุกครั้ง** เพราะเงื่อนไขเดิมคือ "มีรหัสในคำตอบไหม" ซึ่ง
 *      เป็นจริงเสมอ รวมถึงตอนที่ผู้ดูแลพิมพ์รหัสมาเอง · เห็นหน้าต่างค้างอยู่ก็เข้าใจว่า
 *      ฟอร์มยังไม่ปิด
 *
 * เทสต์นี้เรียกตัวประกอบคำสั่งจริง ไม่ได้ grep ซอร์ส — คอมเมนต์ในไฟล์เปลี่ยนได้
 * โดยไม่ทำให้เทสต์เขียว/แดงผิด และลำดับของคำสั่งซึ่งเป็นหัวใจก็ตรวจได้จริง
 */

use Phpcp\Http\V2\MailboxesController;
use Phpcp\Kernel\Ctx;
use Phpcp\Kernel\Router;

group('กล่องจดหมาย — คำสั่งหน้าจอหลังบันทึก');

/**
 * เรียกเมท็อดที่ประกอบคำสั่งหน้าจอ พร้อมของจริงครบชุด (แปลภาษาได้)
 *
 * @param array<string,mixed> $result ผลลัพธ์จาก capability
 * @return list<array<string,mixed>>
 */
function mailboxActions(array $result, string $message = 'Mailbox created'): array
{
    static $controller = null;

    if ($controller === null) {
        $app = ApiHarness::boot()->app;
        $controller = new MailboxesController($app, new Ctx($app), new Router());
    }

    $method = new ReflectionMethod($controller, 'mailboxSaved');
    $method->setAccessible(true);

    // อ่านคำสั่งจากตัว response จริง ไม่ใช่จาก array กลางทาง — ลำดับที่ผู้ใช้เจอคือ
    // ลำดับใน body ที่ส่งออกไปจริง ๆ และเส้นทางนี้ผ่าน done() ที่แปลภาษาด้วย
    $response = $method->invoke($controller, $message, 'Mailbox created', $result, 200);
    $body = json_decode($response->body(), true);

    return $body['actions'] ?? [];
}

/**
 * @param list<array<string,mixed>> $actions
 * @return list<string>
 */
function mailboxActionKinds(array $actions): array
{
    return array_map(
        static fn (array $action): string => $action['type'] === 'modal'
            ? 'modal:' . $action['action']
            : (string) $action['type'],
        $actions,
    );
}

test('บันทึกสำเร็จต้องปิด Modal แจ้งผล แล้วโหลดตารางใหม่', static function (): void {
    $actions = mailboxActions(
        ['password' => 'TypedByAdmin1234', 'password_generated' => false],
        'สร้างกล่อง sales@example.com แล้ว',
    );

    assertSame(
        ['modal:close', 'notification', 'refresh'],
        mailboxActionKinds($actions),
        'ปิดฟอร์ม → แถบแจ้งผล → โหลดตาราง · ขาดข้อไหนผู้ใช้ก็ไม่รู้ว่าสำเร็จ',
    );

    assertSame('success', $actions[1]['level'], 'แถบแจ้งผลต้องเป็นสีเขียว');
    assertSame('สร้างกล่อง sales@example.com แล้ว', $actions[1]['message'], 'ต้องเป็นข้อความจริงจาก capability');

    // ตารางในหน้านี้ดึงข้อมูลเอง (`data-table="mailboxes"`) จึงสั่งโหลดใหม่ได้ตรง ๆ
    // ต้องเป็นชนิด `refresh` ไม่ใช่ `redirect url=reload` — ชนิดหลังถอยไป
    // reload ทั้งหน้าเมื่อไม่เจอตารางชื่อนี้ในหน้าที่เปิดอยู่
    assertSame('mailboxes', $actions[2]['target'], 'ชื่อต้องตรงกับ data-table ในเทมเพลต ไม่งั้นแถวใหม่ไม่ขึ้น');
});

test('รหัสที่ผู้ดูแลพิมพ์เองต้องไม่เด้งหน้าต่างขึ้นมาให้จดซ้ำ', static function (): void {
    $kinds = mailboxActionKinds(mailboxActions(['password' => 'TypedByAdmin1234', 'password_generated' => false]));

    assertTrue(!in_array('modal:show', $kinds, true), 'หน้าต่างรหัสผ่านต้องไม่เปิดเมื่อผู้ดูแลรู้รหัสอยู่แล้ว');
});

test('รหัสที่ระบบสุ่มให้ต้องแสดงครั้งเดียว หลังแถบแจ้งผล', static function (): void {
    $actions = mailboxActions(['password' => 'S3cretGenerated9', 'password_generated' => true]);

    /*
     * **ห้ามมี `modal:close` นำหน้า `modal:show`** — Modal.hide() ตั้งเวลาไว้ล้าง
     * เนื้อในของตัวเอง 150ms หลังจากนั้น ซึ่งมาถึงหลังเนื้อหาใหม่ถูกใส่ไปแล้ว
     * ผลคือหน้าต่างเปิดขึ้นมา "ว่างเปล่า" และรหัสผ่านหายไปพร้อมกัน · การ show
     * เฉย ๆ คือการสลับเนื้อในของหน้าต่างเดิม ซึ่งเป็นสิ่งที่ตั้งใจไว้แต่แรก
     */
    assertSame(
        ['notification', 'modal:show', 'refresh'],
        mailboxActionKinds($actions),
        'แถบแจ้งผลต้องมาก่อนหน้าต่างรหัสผ่าน และห้ามปิดหน้าต่างก่อนเปิดหน้าต่างใหม่',
    );

    assertTrue(
        str_contains((string) $actions[1]['html'], 'S3cretGenerated9'),
        'รหัสที่ระบบสุ่มให้ไม่มีที่อื่นให้ดูย้อนหลังอีกเลย ต้องอยู่ในหน้าต่างนี้',
    );

    // ปิดเองได้ด้วยปุ่มที่มีป้าย ไม่ใช่แค่กากบาทมุมบนที่ไม่มีคำอธิบาย
    assertTrue(
        str_contains((string) $actions[1]['html'], 'closeModal'),
        'หน้าต่างที่ผู้ใช้ต้องปิดเองต้องมีปุ่มปิดที่มีป้ายกำกับ',
    );
});

test('รหัสที่ไม่ได้เปลี่ยนต้องไม่เปิดหน้าต่างเปล่า', static function (): void {
    // แก้แค่โควตา — capability คืน password เป็นค่าว่าง
    $kinds = mailboxActionKinds(mailboxActions(['password' => '', 'password_generated' => false], 'บันทึกกล่องแล้ว'));

    assertSame(['modal:close', 'notification', 'refresh'], $kinds, 'ไม่มีรหัสใหม่ก็ไม่ควรมีหน้าต่างอะไรเปิดขึ้นมา');
});

test('capability ต้องบอกด้วยว่ารหัสนั้นใครเป็นคนกำหนด', static function (): void {
    // เงื่อนไขของหน้าต่างรหัสผ่านอ่านค่านี้ · capability ไม่ส่งมา = ไม่มีวันเด้ง
    foreach (['MailBoxCreate', 'MailBoxUpdate'] as $capability) {
        $source = (string) file_get_contents(PHPCP_ROOT . '/src/Agent/Capability/' . $capability . '.php');

        // ตัดคอมเมนต์ก่อน — ไม่งั้นคำในคำอธิบายทำให้เทสต์ผ่านทั้งที่โค้ดไม่ได้ส่งอะไร
        $code = (string) preg_replace('~/\*.*?\*/|//[^\n]*~s', '', $source);

        assertTrue(
            str_contains($code, "'password_generated' =>"),
            "{$capability} ต้องส่ง password_generated กลับมาด้วย",
        );
    }
});
