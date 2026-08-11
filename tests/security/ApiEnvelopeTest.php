<?php

declare(strict_types=1);

/**
 * ซองของคำตอบ API ที่ฝั่งเซิร์ฟเวอร์เขียน ต้องตรงกับที่ฝั่ง SPA อ่าน
 *
 * **เจอจากการใช้งานจริง (2026-08-11):** แถบคะแนนในหน้า Security ไม่แสดงผล
 * ต้นเหตุคือ `Api.unwrap()` ตรวจ `body.success === true` แต่ `ApiController`
 * เขียนซองเป็น `{ok: true, ...}` มาตลอด · ทุกคำตอบที่ *สำเร็จ* จึงถูกโยนเป็น error
 * แล้วผู้เรียกที่ดักไว้เงียบ ๆ ก็แสดงผลว่างเปล่าโดยไม่มีอะไรฟ้องสักอย่าง
 *
 * กระทบทุกการเรียกที่เขียนด้วยมือ (auth, เปลี่ยนรหัสผ่าน, rollback bar, phpMyAdmin,
 * สำเนาไปปลายทางนอก) ไม่ใช่แค่หน้าเดียว — เทสต์นี้ผูกสองฝั่งไว้ด้วยกันไม่ให้หลุดอีก
 */

group('ซองคำตอบ API — ฝั่งเซิร์ฟเวอร์กับฝั่ง SPA ต้องพูดภาษาเดียวกัน');

function apiControllerSource(): string
{
    return (string) file_get_contents(PHPCP_ROOT . '/src/Http/ApiController.php');
}

function apiClientSource(): string
{
    return (string) file_get_contents(PHPCP_ROOT . '/public/assets/spa/js/api.js');
}

test('เซิร์ฟเวอร์ยังเขียนซองเป็น ok', static function (): void {
    assertTrue(
        str_contains(apiControllerSource(), "'ok' => true"),
        'ถ้าเปลี่ยนคีย์นี้ต้องแก้ api.js ให้ตรงกันด้วย',
    );
});

test('ตัวอ่านฝั่ง SPA ต้องรับคีย์ ok ที่เซิร์ฟเวอร์ส่งจริง', static function (): void {
    $client = apiClientSource();

    assertTrue(
        str_contains($client, 'body.ok === true'),
        'unwrap() ต้องรับ {ok:true} ไม่งั้นคำตอบที่สำเร็จทุกอันกลายเป็น error',
    );

    // ยังต้องรับของเดิมด้วย เผื่อ endpoint เก่าหรือของนอกระบบ
    assertTrue(str_contains($client, 'body.success === true'), 'ต้องยังรับ {success:true} ต่อไป');
});

test('คำตอบแบบ done() ไม่มีคีย์ data — ต้องคืนทั้งก้อน ไม่ใช่ undefined', static function (): void {
    // ApiController::done() ส่ง {ok, message, ...} โดยไม่มี data
    // ผู้เรียกอ่าน result.url / result.message ตรง ๆ (ui.js: openPhpMyAdmin, offsite-copy)
    assertTrue(
        str_contains(apiControllerSource(), "\$body = ['ok' => true, 'message' => \$message]"),
        'รูปแบบของ done() เปลี่ยนไปแล้ว — ตรวจ unwrap() ด้วย',
    );

    assertTrue(
        str_contains(apiClientSource(), 'body.data !== undefined ? body.data : body'),
        'unwrap() ต้องคืน body ทั้งก้อนเมื่อไม่มีคีย์ data ไม่งั้น result.url เป็น undefined',
    );
});
