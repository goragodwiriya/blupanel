<?php

declare(strict_types=1);

/**
 * การหมุน session id ต้องทนคำขอที่ยิงพร้อมกัน
 *
 * **บั๊กจริงที่เทสต์นี้กันไว้ (พบ 2026-08-07 จากรายงานผู้ใช้ว่า "คลิกไปเรื่อย ๆ แล้วเด้ง
 * ไปหน้า login เอง"):** `rotate()` คืน id ใหม่**เสมอ** โดยไม่ดูว่า UPDATE โดนแถวไหนหรือไม่
 *
 * SPA ยิงหลายคำขอพร้อมกันต่อหนึ่งหน้า ทุกคำขอเห็นว่าถึงเวลาหมุนพร้อมกัน · ตัวแรกหมุน
 * X→Y สำเร็จ ที่เหลือ UPDATE ไม่โดนแถวไหนแต่ยังคืน id ที่สุ่มใหม่ของตัวเองออกไปตั้งเป็น
 * คุกกี้ · คำตอบที่ถึงเบราว์เซอร์ทีหลังชนะ เบราว์เซอร์จึงถือ id ที่ไม่มีอยู่ในฐานข้อมูล
 * แล้วถูกเด้งออกทันทีทุก ๆ รอบการหมุน (ค่าเริ่มต้น 15 นาที)
 *
 * UI แบบ HTML เดิมยิงคำขอเดียวต่อหน้า จึงไม่เคยชนกันเลยตลอด 6 เฟสที่ผ่านมา
 */

use Phpcp\Kernel\App;
use Phpcp\Security\SessionStore;

group('SessionStore — การหมุน id ต้องทนคำขอพร้อมกัน');

function rotationStore(): array
{
    static $made = null;

    if ($made !== null) {
        return $made;
    }

    $app = App::boot();
    $store = new SessionStore($app->db(), $app->config);
    $userId = (int) $app->db()->value('SELECT id FROM users ORDER BY id LIMIT 1', [], 0);

    return $made = [$store, $userId, $app];
}

test('หมุนซ้ำด้วย id เดิมต้องคืน null ไม่ใช่ id ใหม่ที่ไม่มีอยู่จริง', static function (): void {
    [$store, $userId] = rotationStore();

    $id = $store->create($userId, '127.0.0.1', SessionStore::hashUserAgent('test'), false);

    $first = $store->rotate($id);
    assertTrue(is_string($first) && $first !== '', 'การหมุนครั้งแรกต้องสำเร็จและคืน id ใหม่');

    // คำขอที่สองที่ถือ id เดิมอยู่ — ต้องรู้ว่ามีคนหมุนไปแล้ว ไม่ใช่สร้าง id ลอย ๆ ขึ้นมา
    $second = $store->rotate($id);
    assertSame(null, $second, 'การหมุนซ้ำด้วย id เดิมต้องคืน null');

    $store->destroy($first);
});

test('id เดิมยังใช้ได้ในช่วงผ่อนผัน — คำขอที่ค้างอยู่ต้องไม่ถูกเด้งออก', static function (): void {
    [$store, $userId] = rotationStore();

    $ua = SessionStore::hashUserAgent('test');
    $id = $store->create($userId, '127.0.0.1', $ua, false);
    $new = $store->rotate($id);

    assertTrue($store->load($new, '127.0.0.1', $ua) !== null, 'id ใหม่ต้องใช้ได้');
    assertTrue(
        $store->load($id, '127.0.0.1', $ua) !== null,
        'id เดิมต้องยังใช้ได้ในช่วงผ่อนผัน — ไม่งั้นคำขอที่ออกไปก่อนหน้าเสี้ยววินาทีจะถูกตีเป็นหมดอายุ',
    );

    $store->destroy($new);
});

test('ออกจากระบบด้วย id เดิมในช่วงผ่อนผันต้องลบ session จริง', static function (): void {
    // "ออกจากระบบแล้วแต่ไม่ได้ออกจริง" เป็นข้อบกพร่องด้านความปลอดภัย ไม่ใช่ความไม่สะดวก
    [$store, $userId] = rotationStore();

    $ua = SessionStore::hashUserAgent('test');
    $id = $store->create($userId, '127.0.0.1', $ua, false);
    $new = $store->rotate($id);

    $store->destroy($id);

    assertSame(null, $store->load($new, '127.0.0.1', $ua), 'session ต้องถูกลบแม้สั่งลบด้วย id เดิม');
    assertSame(null, $store->load($id, '127.0.0.1', $ua), 'id เดิมต้องใช้ไม่ได้แล้วเช่นกัน');
});

// --- การผูก session กับ IP / User-Agent ----------------------------------------
//
// **เจอจากการใช้งานจริง (2026-08-11):** ผู้ดูแลย่อหน้าจอทดสอบ responsive ใน DevTools
// แล้วถูกเด้งไปหน้าล็อกอินเป็นบางครั้ง · โหมดจำลองอุปกรณ์ของ Chrome ปลอม User-Agent
// ให้ด้วย ซึ่งเดิมทำให้ session **ถูกทำลายทิ้ง** ไม่ใช่แค่ถูกปฏิเสธ

group('SessionStore — ผูกกับ IP อย่างเดียว และห้ามทำลาย session ของคนที่ถูกต้อง');

function bindingStore(): array
{
    $app = App::boot();

    return [new SessionStore($app->db(), $app->config), $app];
}

test('เปลี่ยน User-Agent ต้องใช้งานต่อได้ — ไม่ใช่เด้งออกกลางคัน', static function (): void {
    [$store, $app] = bindingStore();
    $userId = (int) $app->db()->value('SELECT id FROM users ORDER BY id LIMIT 1', [], 0);

    $desktop = SessionStore::hashUserAgent('Mozilla/5.0 (X11; Linux x86_64) Chrome/141');
    $mobile = SessionStore::hashUserAgent('Mozilla/5.0 (iPhone; CPU iPhone OS 17_0) Safari');

    $id = $store->create($userId, '10.0.0.9', $desktop, false);

    assertTrue($store->load($id, '10.0.0.9', $mobile) !== null, 'UA ต่างต้องยังใช้ได้');
    assertTrue($store->load($id, '10.0.0.9', $desktop) !== null, 'และต้องไม่ถูกทำลายทิ้ง');

    $store->destroy($id);
});

test('คุกกี้ที่ถูกนำไปใช้จาก IP อื่นต้องใช้ไม่ได้ แต่ต้องไม่เตะเจ้าของออก', static function (): void {
    [$store, $app] = bindingStore();
    $userId = (int) $app->db()->value('SELECT id FROM users ORDER BY id LIMIT 1', [], 0);
    $ua = SessionStore::hashUserAgent('Mozilla/5.0 (X11; Linux x86_64) Chrome/141');

    $id = $store->create($userId, '10.0.0.9', $ua, false);

    assertTrue($store->load($id, '203.0.113.7', $ua) === null, 'IP อื่นต้องใช้ไม่ได้');

    // เดิมการยิงจาก IP อื่นครั้งเดียวทำลาย session ทิ้ง = ใครก็เตะเจ้าของออกได้
    assertTrue($store->load($id, '10.0.0.9', $ua) !== null, 'เจ้าของต้องยังใช้งานต่อได้');

    $store->destroy($id);
});
