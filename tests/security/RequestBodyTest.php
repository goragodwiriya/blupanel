<?php

declare(strict_types=1);

/**
 * การอ่าน body และ header ของคำขอ — ต้องตรงกับที่ PHP-FPM ส่งมาจริง
 *
 * **ที่มา: บั๊กที่ทำให้ REST API v2 ทั้งชุดใช้งานไม่ได้บนเซิร์ฟเวอร์จริง**
 *
 * `Request::header('Content-Type')` เคยมองหา `$_SERVER['HTTP_CONTENT_TYPE']` อย่างเดียว
 * แต่ตาม RFC 3875 ตัวจัดการ CGI ทุกตัว (PHP-FPM, mod_cgi, FrankenPHP) ส่ง Content-Type
 * และ Content-Length มาเป็น `CONTENT_TYPE` / `CONTENT_LENGTH` **โดยไม่มีคำนำหน้า `HTTP_`**
 *
 * ผลคือ `json()` เห็น Content-Type เป็นค่าว่าง → ไม่แปลง body → `payload()` คืนค่าว่าง
 * ทุกคำขอที่ส่ง JSON มา **โดยไม่มี error ใด ๆ เลย**: ล็อกอินผ่าน API ไม่ได้ สร้างเว็บไม่ได้
 * แก้ค่าตั้งไม่ได้ ทุกอย่างตอบ "ข้อมูลไม่ถูกต้อง" หรือ 401 เหมือนผู้ใช้กรอกผิดเอง
 *
 * เทสต์ contract 71 เคสผ่านหมดตลอดเวลาที่บั๊กนี้อยู่ เพราะ `Request::make()` เซ็ต
 * `HTTP_CONTENT_TYPE` ให้เอง — เทสต์กับของจริงเดินคนละเส้นทางกันโดยไม่มีใครรู้
 * บทเรียนคือ **ตัวจำลองที่ไม่เหมือนของจริงในจุดเล็ก ๆ ทำให้เทสต์ทั้งชุดไร้ความหมายได้**
 */

use Phpcp\Kernel\Request;

group('RequestBody — คำขอต้องอ่านค่าได้แบบเดียวกับที่เว็บเซิร์ฟเวอร์จริงส่งมา');

/**
 * สร้าง Request จาก $_SERVER ดิบ ๆ แบบที่ capture() จะได้รับจาก PHP-FPM
 *
 * ต้องข้าม make() เพราะสิ่งที่กำลังทดสอบคือ "อ่านค่าจาก $_SERVER รูปแบบไหนได้บ้าง"
 * ถ้าเรียกผ่าน make() จะเป็นการทดสอบตัวแปลงของ make() เอง ไม่ใช่ตัวอ่าน header
 */
function fpmRequest(array $server, string $body): Request
{
    $reflection = new ReflectionClass(Request::class);
    $constructor = $reflection->getConstructor();
    $constructor->setAccessible(true);

    $request = $reflection->newInstanceWithoutConstructor();
    $constructor->invokeArgs($request, [
        'method' => 'POST',
        'path' => '/api/v2/session',
        'query' => [],
        'post' => [],   // PHP ไม่เติม $_POST ให้เมื่อ body เป็น JSON
        'files' => [],
        'cookies' => [],
        'server' => $server,
        'ip' => '127.0.0.1',
        'userAgent' => 'test',
        'requestId' => 'test',
        'rawBody' => $body,
    ]);

    return $request;
}

test('Content-Type ที่ PHP-FPM ส่งมาแบบไม่มี HTTP_ ต้องอ่านได้', static function (): void {
    // นี่คือรูปแบบจริงที่ php-fpm วางไว้ใน $_SERVER — สังเกตว่าไม่มี HTTP_CONTENT_TYPE เลย
    $request = fpmRequest([
        'REQUEST_METHOD' => 'POST',
        'CONTENT_TYPE' => 'application/json',
        'CONTENT_LENGTH' => '42',
        'HTTP_X_CSRF_TOKEN' => 'abc',
    ], '{"username":"demo","password":"ความลับ"}');

    assertSame('application/json', $request->header('Content-Type'), 'ต้องอ่าน Content-Type ได้');
    assertSame('42', $request->header('Content-Length'), 'ต้องอ่าน Content-Length ได้');
    assertSame('abc', $request->header('X-CSRF-Token'), 'header อื่นยังอ่านแบบเดิม');

    // และผลลัพธ์ที่สำคัญที่สุด: body ต้องถูกแปลงจริง
    assertSame('demo', $request->payloadString('username'), 'ต้องอ่านค่าจาก JSON body ได้');
    assertSame('ความลับ', $request->payloadString('password'), 'รหัสผ่านต้องมาถึงตัวควบคุมครบ');
});

test('รูปแบบ HTTP_CONTENT_TYPE ก็ต้องยังอ่านได้ — เว็บเซิร์ฟเวอร์บางตัวส่งมาแบบนั้น', static function (): void {
    $request = fpmRequest([
        'REQUEST_METHOD' => 'POST',
        'HTTP_CONTENT_TYPE' => 'application/json; charset=utf-8',
    ], '{"name":"x"}');

    assertTrue(str_contains($request->header('Content-Type'), 'application/json'), 'ต้องอ่านได้ทั้งสองแบบ');
    assertSame('x', $request->payloadString('name'), 'body ต้องถูกแปลง');
});

test('body ที่ไม่ใช่ JSON ต้องไม่ถูกแปลง และ payload ต้องตกไปใช้ฟอร์ม', static function (): void {
    $request = fpmRequest([
        'REQUEST_METHOD' => 'POST',
        'CONTENT_TYPE' => 'application/x-www-form-urlencoded',
    ], 'username=demo');

    assertSame([], $request->json(), 'body ที่ไม่ได้ประกาศเป็น JSON ต้องไม่ถูกแปลง');
    assertTrue(!$request->hasBrokenJson(), 'และต้องไม่ถือว่าเป็น JSON ที่เสีย');
});

test('JSON ที่เสียต้องถูกจับได้ ไม่ใช่กลายเป็นคำขอที่ไม่มีข้อมูล', static function (): void {
    // ถ้าไม่แยกสองกรณีนี้ ผู้เรียกที่ส่ง JSON ผิดรูปแบบจะได้ 401/422 ที่ชี้ไปผิดทาง
    // แทนที่จะได้ 400 ที่บอกว่า "body ของคุณแปลงไม่ได้"
    $broken = fpmRequest(['CONTENT_TYPE' => 'application/json'], '{"username": ');

    assertTrue($broken->hasBrokenJson(), 'JSON ที่เสียต้องถูกรายงาน');
    assertSame([], $broken->json(), 'และต้องไม่ได้ค่ามั่ว ๆ ออกมา');

    $empty = fpmRequest(['CONTENT_TYPE' => 'application/json'], '');
    assertTrue(!$empty->hasBrokenJson(), 'body ว่างไม่ใช่ JSON ที่เสีย');
});

test('Request::make() ของเทสต์ต้องวาง header แบบเดียวกับ PHP-FPM', static function (): void {
    // ถ้าตัวจำลองวาง header คนละแบบกับของจริง เทสต์ contract ทั้งชุดจะเดินคนละเส้นทาง
    // แล้วบั๊กชนิดนี้จะหลุดผ่านไปได้อีกโดยที่เทสต์ยังเขียวทั้งกระดาน
    $request = Request::make(
        'POST',
        '/api/v2/sites',
        headers: ['Content-Type' => 'application/json', 'X-CSRF-Token' => 'tok'],
        rawBody: '{"domain":"example.test"}',
    );

    $server = (new ReflectionProperty(Request::class, 'server'))->getValue($request);

    assertTrue(isset($server['CONTENT_TYPE']), 'ต้องวางเป็น CONTENT_TYPE แบบ CGI');
    assertTrue(!isset($server['HTTP_CONTENT_TYPE']), 'ต้องไม่วางแบบ HTTP_CONTENT_TYPE ซึ่งของจริงไม่มี');
    assertTrue(isset($server['HTTP_X_CSRF_TOKEN']), 'header อื่นยังต้องมีคำนำหน้า HTTP_');

    assertSame('example.test', $request->payloadString('domain'), 'และต้องอ่าน body ได้จริง');
});
