<?php

declare(strict_types=1);

/**
 * สถานะเกณฑ์เตือนบนหน้าเว็บ — `GET /api/v2/alerts` (PLAN-V2 เฟส E6)
 *
 * **ทำไมต้องมีเส้นทางนี้ทั้งที่มีการแจ้งเตือนอยู่แล้ว:** ระบบแจ้งเตือนตั้งใจให้เงียบเมื่อ
 * ปัญหายังเหมือนเดิม (`AlertRules::REPEAT_AFTER` = 6 ชั่วโมง) ผู้ดูแลที่เพิ่งเปิดเครื่อง
 * จึงไม่มีทางรู้ว่าดิสก์ยังเต็มอยู่ไหม ถ้าข้อความรอบล่าสุดถูกส่งไปตั้งแต่เมื่อวาน
 *
 * สิ่งที่เทสต์นี้ตรึงไว้:
 *   1. **อ่านอย่างเดียวจริง** — ไม่มีทางลบสถานะจากหน้าเว็บ · การล้างด้วยมือทำให้ได้
 *      ข้อความ "ผิดปกติครั้งแรก" ซ้ำอีกครั้งทั้งที่ปัญหาเดิมยังอยู่
 *   2. **ลูกค้าเข้าไม่ถึง** — ชื่อบริการกับโดเมนบนเครื่องเป็นข้อมูลของผู้ดูแล
 *   3. **`meta.channels` ต้องบอกความจริง** — ตารางที่ว่างตอนที่ไม่มีช่องทางไหนใช้ได้
 *      ไม่ได้แปลว่าเครื่องปกติ แต่แปลว่าไม่มีใครได้รับข้อความ
 */

use Phpcp\Domain\AlertRules;
use Phpcp\Domain\SettingsRepository;
use Phpcp\Security\Permissions;

group('เกณฑ์เตือน — สถานะบนหน้าเว็บ');

function alertsHarness(): ApiHarness
{
    static $harness = null;

    if ($harness !== null) {
        return $harness;
    }

    $harness = ApiHarness::boot();
    $harness->createUser('alertadmin', 'Alerts-Admin-Pass-11', Permissions::SUPERADMIN);
    $harness->createUser('alertweb', 'Alerts-Web-Pass-22', Permissions::WEBADMIN);

    return $harness;
}

function alertsLogin(string $username, string $password): ApiHarness
{
    $harness = alertsHarness();
    $harness->forget();
    $harness->clearRateLimits();
    $harness->request('GET', '/api/v2/session');
    $harness->request('POST', '/api/v2/session', ['username' => $username, 'password' => $password]);

    return $harness;
}

test('ไม่มีอะไรผิดปกติ = รายการว่าง ไม่ใช่ error', static function (): void {
    // หน้าตั้งค่าโหลดตารางนี้ทุกครั้งที่เปิด — ถ้าตอบ error ตอนที่เครื่องปกติดี
    // (ซึ่งเป็นสถานะปกติของระบบ) ผู้ดูแลจะเห็นข้อความแดงทุกครั้งโดยไม่มีเหตุ
    $harness = alertsLogin('alertadmin', 'Alerts-Admin-Pass-11');

    $response = $harness->request('GET', '/api/v2/alerts');

    assertSame(200, $response->status, 'ต้องตอบ 200');
    assertSame([], $response->json['data'], 'ต้องเป็นรายการว่าง');
    assertSame(0, $response->json['meta']['total'], 'ต้องนับได้ 0');
});

test('เกณฑ์ที่ค้างอยู่ต้องโผล่พร้อมชื่อที่คนอ่านได้', static function (): void {
    $harness = alertsLogin('alertadmin', 'Alerts-Admin-Pass-11');
    $rules = new AlertRules($harness->app->db());
    $now = time();

    $rules->evaluate('disk', 'critical', 96.5, $now - 7200);
    $rules->evaluate('service:mariadb', 'critical', 0.0, $now - 3600);
    $rules->evaluate('cert:example.com', 'warning', 12.0, $now - 600);

    $response = $harness->request('GET', '/api/v2/alerts');
    $rows = $response->json['data'];

    assertSame(3, count($rows), 'ต้องได้ครบสามรายการ');
    assertSame(3, $response->json['meta']['total'], 'meta ต้องตรงกับจำนวนแถว');
    assertSame(2, $response->json['meta']['critical'], 'ต้องนับเฉพาะที่วิกฤต');

    $byKey = [];
    foreach ($rows as $row) {
        $byKey[$row['alert_key']] = $row;
    }

    // คีย์ดิบอย่าง `service:mariadb` อ่านรู้เรื่องสำหรับคนเขียนโค้ด ไม่ใช่ผู้ดูแลที่เปิดหน้าเว็บ
    assertSame('พื้นที่ดิสก์', $byKey['disk']['label'], 'ต้องแปลงคีย์เป็นชื่อ');
    assertSame('บริการ mariadb', $byKey['service:mariadb']['label'], 'ต้องแยกชื่อบริการออกมา');
    assertSame('ใบรับรองของ example.com', $byKey['cert:example.com']['label'], 'ต้องแยกชื่อโดเมนออกมา');

    // สีของป้ายมาจากฝั่งเซิร์ฟเวอร์ เทมเพลตจึงเขียน `pill-${level_tone}` ได้ตรง ๆ
    assertSame('danger', $byKey['disk']['level_tone'], 'วิกฤตต้องเป็นสีแดง');
    assertSame('warn', $byKey['cert:example.com']['level_tone'], 'เตือนต้องเป็นสีเหลือง');

    // ระยะเวลาที่ค้างคือตัวเลขที่บอกว่าเรื่องนี้ถูกปล่อยทิ้งไว้หรือเปล่า
    assertTrue($byKey['disk']['duration'] >= 7200, 'ต้องนับเวลาตั้งแต่ครั้งแรกที่พบ');
});

test('รายการต้องเรียงให้เรื่องวิกฤตอยู่บน', static function (): void {
    // ผู้ดูแลที่เปิดหน้าเว็บตอนเครื่องมีปัญหาหลายเรื่องพร้อมกัน ต้องเห็นเรื่องที่ทำให้
    // เว็บล่มทั้งเครื่องก่อนเรื่องที่แค่ควรจับตา
    $harness = alertsLogin('alertadmin', 'Alerts-Admin-Pass-11');
    $harness->app->db()->run('DELETE FROM alert_state');

    $rules = new AlertRules($harness->app->db());
    $now = time();

    $rules->evaluate('memory', 'warning', 91.0, $now - 9000);
    $rules->evaluate('disk', 'critical', 96.0, $now - 60);

    $rows = $harness->request('GET', '/api/v2/alerts')->json['data'];

    assertSame('critical', $rows[0]['level'], 'วิกฤตต้องอยู่แถวแรก แม้จะเพิ่งเกิด');
});

test('ลูกค้าเข้าไม่ถึงสถานะของเครื่อง', static function (): void {
    // ชื่อบริการที่รันอยู่และโดเมนที่มีใบรับรองบนเครื่อง เป็นข้อมูลตั้งต้นของการเลือก
    // เป้าโจมตี · webadmin ไม่มี settings.view อยู่แล้วตาม Permissions::forRole()
    $harness = alertsLogin('alertweb', 'Alerts-Web-Pass-22');

    $response = $harness->request('GET', '/api/v2/alerts');

    assertSame(403, $response->status, 'ลูกค้าต้องได้ 403');
    assertTrue($response->isJson(), 'ต้องเป็น JSON ไม่ใช่หน้า HTML');
});

test('ไม่มีทางลบสถานะเตือนจากหน้าเว็บ', static function (): void {
    // การล้างด้วยมือทำให้รอบถัดไปส่งข้อความ "ผิดปกติครั้งแรก" ซ้ำอีกครั้งทั้งที่
    // ปัญหาเดิมยังอยู่ — ซึ่งคือสแปมแบบที่ AlertRules ถูกเขียนขึ้นมาเพื่อกันโดยเฉพาะ
    $harness = alertsLogin('alertadmin', 'Alerts-Admin-Pass-11');

    foreach (['DELETE', 'POST', 'PATCH', 'PUT'] as $method) {
        $response = $harness->request($method, '/api/v2/alerts');

        assertTrue($response->status >= 400, "{$method} /api/v2/alerts ต้องไม่สำเร็จ");
        assertTrue($response->isJson(), "{$method} ต้องตอบ JSON");
    }
});

test('meta ต้องบอกว่าตอนนี้มีช่องทางไหนส่งออกได้จริง', static function (): void {
    // ตารางที่ว่างตอนที่ไม่มีช่องทางไหนใช้ได้ ไม่ได้แปลว่าเครื่องปกติ
    // แต่แปลว่าไม่มีใครได้รับข้อความ — หน้าจอต้องแยกสองกรณีนี้ออก
    //
    // เขียนค่าตั้งลงฐานข้อมูลตรง ๆ ไม่ผ่าน `PATCH /api/v2/settings` เพราะ agent
    // ไม่ทำงานในชุดทดสอบ คำขอนั้นจึงจบที่ 503 โดยไม่ได้บันทึกอะไรเลย
    $harness = alertsLogin('alertadmin', 'Alerts-Admin-Pass-11');
    $settings = new SettingsRepository($harness->app->db());

    $before = $harness->request('GET', '/api/v2/alerts')->json['meta'];
    assertSame([], $before['channels'], 'ยังไม่ตั้งค่าอะไร = ไม่มีช่องทาง');
    assertSame(AlertRules::REPEAT_AFTER, $before['repeat_after'], 'ต้องบอกรอบเตือนซ้ำให้หน้าจอ');

    // เปิดสวิตช์ไว้แต่ยังไม่กรอก token ต้องยังไม่นับว่าใช้งานได้ — ไม่งั้นหน้าจอจะบอกว่า
    // ระบบแจ้งเตือนพร้อมแล้วทั้งที่ไม่มีอะไรส่งออกได้เลย
    $settings->save(['notify.telegram.enabled' => '1']);
    $halfway = $harness->request('GET', '/api/v2/alerts')->json['meta'];
    assertSame([], $halfway['channels'], 'เปิดสวิตช์อย่างเดียวยังส่งไม่ได้');

    $settings->save([
        'notify.telegram.token' => '123456:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA',
        'notify.telegram.chat_id' => '-1001234567890',
    ]);
    assertSame(['telegram'], $harness->request('GET', '/api/v2/alerts')->json['meta']['channels'], 'ตั้งครบแล้วต้องนับว่าใช้งานได้');

    // webhook ที่เปิดแล้วมี URL ก็ต้องนับด้วย — ทุกช่องทางที่เปิดจะได้รับข้อความ
    // ไม่ใช่ช่องแรกที่สำเร็จ (ผู้ดูแลตั้งสองช่องเพราะตั้งใจให้ได้ทั้งสองทาง)
    $settings->save(['notify.webhook.enabled' => '1', 'notify.webhook.url' => 'https://example.com/hook']);
    assertSame(
        ['telegram', 'webhook'],
        $harness->request('GET', '/api/v2/alerts')->json['meta']['channels'],
        'ต้องนับทุกช่องทางที่พร้อม',
    );
});

test('ปุ่มทดสอบต้องรับเฉพาะช่องทางที่มีจริง', static function (): void {
    // ทดสอบที่ตัว capability ตรง ๆ ไม่ผ่าน HTTP เพราะการตรวจอาร์กิวเมนต์อยู่ใน
    // `validate()` ซึ่งรันฝั่ง agent — และ agent ไม่ทำงานในชุดทดสอบ คำขอผ่าน HTTP
    // จึงจบที่ 503 ทุกกรณี ทั้งช่องที่ถูกและช่องที่มั่ว แยกกันไม่ออก
    $capability = new Phpcp\Agent\Capability\NotifyTest();

    foreach (['telegram', 'email', 'webhook'] as $channel) {
        assertSame($channel, $capability->validate(['channel' => $channel])['channel'], "{$channel} ต้องผ่าน");
    }

    // ไม่ระบุ = telegram เพื่อความเข้ากันได้กับผู้เรียกเดิมที่มีช่องทางเดียว
    assertSame('telegram', $capability->validate([])['channel'], 'ไม่ระบุต้องได้ telegram');
    assertSame('telegram', $capability->validate(['channel' => ''])['channel'], 'ค่าว่างต้องได้ telegram');

    foreach (['ไม่มีช่องนี้', 'sms', 'TELEGRAM'] as $bad) {
        $rejected = false;

        try {
            $capability->validate(['channel' => $bad]);
        } catch (Phpcp\Agent\ValidationError) {
            $rejected = true;
        }

        assertTrue($rejected, "ช่องทาง '{$bad}' ต้องถูกปฏิเสธ");
    }
});

test('เส้นทางทดสอบการแจ้งเตือนยังต้องผ่านด่านสิทธิ์เหมือนเดิม', static function (): void {
    // การเพิ่มพารามิเตอร์ `channel` ต้องไม่เผลอเปิดเส้นทางนี้ให้ลูกค้า —
    // การส่งข้อความออกนอกเครื่องในนามระบบเป็นสิทธิ์ของผู้ดูแลเท่านั้น
    $harness = alertsLogin('alertweb', 'Alerts-Web-Pass-22');

    $response = $harness->request('POST', '/api/v2/settings/notification-test', ['channel' => 'webhook']);

    assertSame(403, $response->status, 'ลูกค้าต้องได้ 403');
});
