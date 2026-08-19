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

test('คำสั่ง refresh ที่เซิร์ฟเวอร์ส่ง ต้องมีตัวรับอยู่ฝั่ง SPA จริง', static function (): void {
    /*
     * `refresh` เป็นชนิดของโปรเจกต์นี้เอง ไม่ใช่ของ Now.js — `ResponseHandler`
     * เจอชนิดที่ไม่รู้จักจะ `console.warn` แล้วทิ้งเงียบ ๆ · อาการคือ "กดแล้วขึ้นว่า
     * สำเร็จ แต่ตารางยังเป็นของเดิม" ซึ่งผู้ใช้อ่านว่าคำสั่งไม่ติดแล้วกดซ้ำ
     *
     * ผูกสองฝั่งไว้ด้วยกัน: ตัวส่งอยู่ใน ApiController ตัวรับอยู่ใน ui.js
     */
    assertTrue(
        str_contains(apiControllerSource(), "return ['type' => 'refresh', 'target' => \$table];"),
        'ตัวประกอบคำสั่งรีเฟรชหายไปจาก ApiController',
    );

    $ui = (string) file_get_contents(PHPCP_ROOT . '/public/assets/spa/js/ui.js');

    assertTrue(
        str_contains($ui, "registerHandler('refresh'"),
        'ui.js ต้องลงทะเบียนตัวรับชนิด refresh ไม่งั้นคำสั่งถูกทิ้งเงียบ ๆ',
    );
});

test('ห้ามใช้ redirect url=reload อีก — มันถอยไป reload ทั้งหน้าเมื่อหาตารางไม่เจอ', static function (): void {
    /*
     * `ResponseHandler` จัดการ `{"type":"redirect","url":"reload"}` ด้วย
     * `tryHandleReload()` ซึ่งคืน false เมื่อไม่มีตารางชื่อนั้นในหน้าที่เปิดอยู่ แล้ว
     * ตกไปที่ `window.location.reload()` — **โหลดใหม่ทั้งเบราว์เซอร์**
     *
     * ไม่ใช่กรณีหายาก: endpoint เดียวถูกเรียกจากหลายหน้า (เพิ่มโดเมนตอบชื่อตาราง
     * `sites` แต่ถูกกดจากหน้าเว็บไซต์รายตัว · เปิด SFTP ถูกกดทั้งจากหน้า SFTP และ
     * หน้าผู้ใช้) · และการโหลดใหม่ทั้งหน้าทิ้งหน้าต่างรหัสผ่านที่เพิ่งเปิดไปด้วย
     * ซึ่งแปลว่ารหัสนั้นหายไปจากโลกนี้เลย เพราะ panel ไม่ได้เก็บไว้ที่ไหน
     */
    $offenders = [];

    foreach (glob(PHPCP_ROOT . '/src/Http/V2/*.php') ?: [] as $file) {
        $code = (string) preg_replace('~/\*.*?\*/|//[^\n]*~s', '', (string) file_get_contents($file));

        if (preg_match("/'url'\s*=>\s*'reload'/", $code) === 1) {
            $offenders[] = basename($file);
        }
    }

    assertSame([], $offenders, "ใช้ ['type' => 'refresh', 'target' => ...] แทน — controller เหล่านี้ยังใช้ของเดิม:\n  " . implode("\n  ", $offenders));
});

test('ปุ่มในกล่องค่าที่ดูได้ครั้งเดียว ต้องเรียก action ที่ ui.js ลงทะเบียนไว้จริง', static function (): void {
    // กล่องนี้เป็น HTML ที่เซิร์ฟเวอร์ประกอบเอง ไม่ผ่านเทมเพลต — ถ้าชื่อ action
    // ไม่ตรงกับที่ลงทะเบียนไว้ ปุ่มจะกดแล้วเงียบสนิท ไม่มีข้อผิดพลาดให้เห็น
    $server = apiControllerSource();
    $ui = (string) file_get_contents(PHPCP_ROOT . '/public/assets/spa/js/ui.js');

    foreach (['closeModal', 'copyToClipboard'] as $action) {
        assertTrue(
            str_contains($server, 'click.prevent:' . $action),
            "กล่องต้องมีปุ่มที่เรียก {$action}",
        );
    }

    // closeModal เป็นของโปรเจกต์นี้ · copyToClipboard เป็นของ Now.js เองอยู่แล้ว
    assertTrue(
        str_contains($ui, "registerAction('closeModal'"),
        'ui.js ต้องลงทะเบียน closeModal ไม่งั้นปุ่มปิดกดไม่ได้',
    );
    assertTrue(
        str_contains((string) file_get_contents(PHPCP_ROOT . '/public/assets/spa/vendor/now/now.core.min.js'), 'copyToClipboard'),
        'Now.js ต้องยังมี copyToClipboard อยู่ในเวอร์ชันที่ vendor ไว้',
    );
});
