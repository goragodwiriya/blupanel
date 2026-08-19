<?php

declare(strict_types=1);

/**
 * สัญญาของหน้าจอ SFTP — หน้าเดียวที่แสดงต่างกันตามบทบาท
 *
 * หัวใจของชุดนี้คือเส้นแบ่งสองเส้นที่ต้องไม่พราน:
 *   1. ลูกค้าเห็นได้แค่บัญชีของตัวเอง (`own`) — ตารางรวมทุกบัญชีต้อง 403
 *   2. การเปลี่ยนรหัสของตัวเองผูกกับ session เท่านั้น — endpoint ไม่มี {id}
 *      ให้ชี้ไปที่บัญชีคนอื่นตั้งแต่แรก
 */

use Phpcp\Http\ApiProblem;
use Phpcp\Security\Permissions;

group('REST API v2 — หน้าจอ SFTP');

function sftpHarness(): ApiHarness
{
    static $harness = null;

    if ($harness !== null) {
        return $harness;
    }

    $harness = ApiHarness::boot();
    $harness->createUser('sftpadmin', 'Sftp-Admin-Pass-11', Permissions::SUPERADMIN);
    $harness->createHostingUser('sftpweb', 'Sftp-Web-Pass-22');

    return $harness;
}

function sftpLogin(string $username, string $password): ApiHarness
{
    $harness = sftpHarness();
    $harness->forget();
    $harness->clearRateLimits();
    $harness->request('GET', '/api/v2/session');

    $login = $harness->request('POST', '/api/v2/session', ['username' => $username, 'password' => $password]);

    if ($login->status !== 200) {
        throw new RuntimeException("เตรียมเทสต์ไม่สำเร็จ: ล็อกอินได้ {$login->status}");
    }

    return $harness;
}

test('ลูกค้าเห็นบัญชี SFTP ของตัวเอง แต่ต้องไม่มีตารางรวมทุกบัญชี', static function (): void {
    $harness = sftpLogin('sftpweb', 'Sftp-Web-Pass-22');

    $response = $harness->request('GET', '/api/v2/sftp');

    assertSame(200, $response->status, 'บัญชีตัวเองต้องดูได้');
    assertTrue(is_array($response->data('own')), 'ต้องมี own สำหรับบัญชีโฮสติ้ง');
    assertSame('sftpweb', $response->data('own')['username'], 'ต้องเป็นชื่อของตัวเอง');
    assertTrue(!array_key_exists('accounts', $response->json['data']), 'รายการของทุกคนต้องไม่ติดมาเลย');
});

test('ผู้ดูแลไม่มี own (ไม่มี SFTP ของตัวเอง) แต่เห็นตารางได้', static function (): void {
    $harness = sftpLogin('sftpadmin', 'Sftp-Admin-Pass-11');

    $page = $harness->request('GET', '/api/v2/sftp');
    assertSame(200, $page->status, 'ผู้ดูแลต้องเปิดหน้า SFTP ได้');
    assertSame(null, $page->data('own'), 'บัญชีผู้ดูแลไม่มี SFTP ของตัวเอง');

    $table = $harness->request('GET', '/api/v2/sftp/accounts');
    assertSame(200, $table->status, 'ตารางรวมเป็นของผู้ดูแล');
    assertTrue(is_array($table->json['data']), 'ต้องเป็นรายการ');

    // แถวต้องพร้อมแสดง — ป้ายสถานะประกอบมาจากเซิร์ฟเวอร์ ไม่ใช่ให้หน้าจอเดา
    foreach ($table->json['data'] as $row) {
        assertTrue(isset($row['status_label']) && isset($row['status_tone']), 'แต่ละแถวต้องมีป้ายสถานะพร้อมแสดง');
        assertTrue(isset($row['can_manage']), 'แถวต้องบอกว่าปุ่มแสดงได้หรือไม่');
    }
});

test('ตารางบัญชีทั้งหมดเป็นของผู้ดูแล — ลูกค้าต้องได้ 403', static function (): void {
    $harness = sftpLogin('sftpweb', 'Sftp-Web-Pass-22');

    $response = $harness->request('GET', '/api/v2/sftp/accounts');

    assertSame(403, $response->status, 'รายชื่อลูกค้าคนอื่นไม่ใช่ของลูกค้า');
    assertSame(ApiProblem::Forbidden->value, $response->errorCode(), 'ต้องเป็น FORBIDDEN');
});

test('เปลี่ยนรหัส SFTP ตัวเอง: รหัสสั้นต้องได้ 422 ก่อนถึงชั้นอื่น', static function (): void {
    $harness = sftpLogin('sftpweb', 'Sftp-Web-Pass-22');

    $response = $harness->request('PUT', '/api/v2/sftp/password', ['password' => 'short']);

    assertSame(422, $response->status, 'รหัสสั้นกว่า 12 ตัวต้องถูกปฏิเสธที่ชั้นเว็บ');
    assertSame(ApiProblem::ValidationError->value, $response->errorCode(), 'ต้องเป็น VALIDATION_ERROR');
});

test('เปลี่ยนรหัส SFTP ตัวเอง: รหัสยาวพอต้องผ่านสิทธิ์ไปถึงชั้น agent', static function (): void {
    $harness = sftpLogin('sftpweb', 'Sftp-Web-Pass-22');

    // ในสภาพแวดล้อมทดสอบไม่มี agent — ได้ 503 ซึ่งพิสูจน์ว่าคำขอผ่านทุกชั้นตรวจ
    // ไปถึงการสั่งงานจริง (จุดตัดสิทธิ์อยู่ที่ route + capability ไม่ใช่ตรงนี้)
    $response = $harness->request('PUT', '/api/v2/sftp/password', ['password' => 'LongEnoughPass123']);

    assertSame(503, $response->status, 'ต้องไปติดที่ agent ไม่ใช่ 403/404: ' . $response->status);
    assertSame(ApiProblem::AgentUnavailable->value, $response->errorCode(), 'ต้องเป็น AGENT_UNAVAILABLE');
});

test('หน้าจอ SFTP ต้องมีทั้งเมนูและเส้นทางของ SPA', static function (): void {
    // เมนูและ route ของ SPA ต้องครบ — ขาดข้างใดข้างหนึ่งคือหน้าที่เข้าไม่ได้
    // (เจอจริง: ปุ่มกดแล้วเงียบ เพราะเพิ่ม route แต่ลืมเมนู)
    $menu = (string) file_get_contents(PHPCP_ROOT . '/public/assets/spa/js/ui.js');
    assertTrue(str_contains($menu, "url: '/sftp'"), 'เมนูต้องมีรายการ /sftp');

    $routes = (string) file_get_contents(PHPCP_ROOT . '/public/assets/spa/js/main.js');
    assertTrue(str_contains($routes, "'/sftp':"), 'SPA route ต้องมี /sftp');

    $template = PHPCP_ROOT . '/public/assets/spa/templates/sftp.html';
    assertTrue(is_file($template), 'ต้องมีไฟล์เทมเพลต sftp.html');
});
