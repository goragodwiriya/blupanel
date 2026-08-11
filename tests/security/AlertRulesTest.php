<?php

declare(strict_types=1);

/**
 * เกณฑ์เตือนของเครื่องและกฎกันข้อความซ้ำ — PLAN-V2 เฟส E6
 *
 * **สิ่งที่เทสต์นี้ปกป้องคือ "ความเงียบที่ถูกต้อง" ไม่ใช่ "การตรวจเจอ"** — การตรวจว่า
 * ดิสก์เกิน 85% เป็นเรื่องง่ายและพังยาก ส่วนที่พังง่ายและพังเงียบคือจังหวะการส่ง:
 *
 *   1. **ส่งซ้ำทุกรอบ** → ผู้ดูแลปิดการแจ้งเตือนภายในสัปดาห์เดียว แล้ววันที่เครื่องล่มจริง
 *      จะไม่มีใครเห็น · แย่กว่าไม่มีระบบแจ้งเตือนเลย เพราะเข้าใจว่าตัวเองมีระบบเฝ้าอยู่
 *   2. **เงียบตอนที่แย่ลง** → warning ที่กลายเป็น critical ต้องดังทันที ไม่ใช่รอครบ 6 ชั่วโมง
 *   3. **ไม่บอกว่าหายแล้ว** → ผู้ดูแลไม่รู้ว่าต้องเข้าไปดูอีกไหม
 *   4. **บอกว่าหายแล้วทั้งที่ไม่เคยแจ้งว่าเสีย** → ข้อความที่ไม่มีความหมาย
 *
 * ทุกข้อข้างบนทดสอบด้วยการ**เดินเวลาเอง** (พารามิเตอร์ `$now`) ไม่ใช่รอจริง —
 * ซึ่งเป็นเหตุผลทั้งหมดที่ `AlertRules` แยกออกมาจาก capability ที่อ่านค่าจากเครื่อง
 */

use Phpcp\Domain\AlertRules;
use Phpcp\Kernel\Db;

group('AlertRules — เกณฑ์เตือนและกฎกันข้อความซ้ำ');

function alertDb(): Db
{
    $path = sys_get_temp_dir() . '/phpcp-alert-' . getmypid() . '-' . bin2hex(random_bytes(4)) . '.db';
    $db = new Db($path);
    $db->migrate(PHPCP_ROOT . '/db/migrations');

    register_shutdown_function(static fn () => @unlink($path));

    return $db;
}

test('ผิดปกติครั้งแรกต้องส่ง แล้วเงียบตลอดช่วงที่ยังเหมือนเดิม', static function (): void {
    $rules = new AlertRules(alertDb());
    $t = 1_700_000_000;

    $first = $rules->evaluate('disk', 'warning', 86.0, $t);
    assertTrue($first['notify'], 'ครั้งแรกต้องส่ง');
    assertSame('new', $first['reason'], 'ต้องบอกว่าเป็นครั้งแรก');

    // รอบถัดไปทันที และอีกหลายรอบระหว่างนั้น — ต้องเงียบทั้งหมด
    foreach ([300, 3600, AlertRules::REPEAT_AFTER - 1] as $offset) {
        $again = $rules->evaluate('disk', 'warning', 87.0, $t + $offset);
        assertTrue(!$again['notify'], "ที่วินาที {$offset} ต้องยังเงียบ");
    }
});

test('ครบรอบเตือนซ้ำแล้วต้องส่งอีกครั้ง แล้วเริ่มนับใหม่', static function (): void {
    // ขอบเขตตรงเป๊ะสำคัญ: ถ้าเขียนเป็น `>` แทน `>=` การเตือนซ้ำจะเลื่อนไปหนึ่งรอบเสมอ
    // ซึ่งไม่มีอะไรฟ้อง เพราะรอบถัดไปก็ยังส่งอยู่ดี แค่ช้ากว่าที่ตั้งใจ
    $rules = new AlertRules(alertDb());
    $t = 1_700_000_000;

    $rules->evaluate('disk', 'warning', 86.0, $t);

    $onTime = $rules->evaluate('disk', 'warning', 86.0, $t + AlertRules::REPEAT_AFTER);
    assertTrue($onTime['notify'], 'ครบรอบพอดีต้องส่ง');
    assertSame('reminder', $onTime['reason'], 'ต้องเป็นการเตือนซ้ำ');

    // นับใหม่จากรอบที่เพิ่งส่ง ไม่ใช่จากครั้งแรก
    $tooSoon = $rules->evaluate('disk', 'warning', 86.0, $t + AlertRules::REPEAT_AFTER + 60);
    assertTrue(!$tooSoon['notify'], 'ต้องเริ่มนับรอบใหม่หลังส่ง');
});

test('แย่ลงจาก warning เป็น critical ต้องส่งทันที ไม่รอครบรอบ', static function (): void {
    $rules = new AlertRules(alertDb());
    $t = 1_700_000_000;

    $rules->evaluate('disk', 'warning', 86.0, $t);

    $worse = $rules->evaluate('disk', 'critical', 96.0, $t + 60);
    assertTrue($worse['notify'], 'สถานการณ์แย่ลงต้องดังทันที');
    assertSame('escalated', $worse['reason'], 'ต้องบอกว่าแย่ลง');

    // ดีขึ้นกลับมาเป็น warning แต่ยังผิดปกติ — ไม่ใช่เรื่องด่วน จึงต้องเงียบ
    $better = $rules->evaluate('disk', 'warning', 88.0, $t + 120);
    assertTrue(!$better['notify'], 'ดีขึ้นแต่ยังผิดปกติต้องไม่ส่ง');
});

test('กลับมาปกติต้องส่งครั้งเดียวแล้วลืมสถานะ', static function (): void {
    $db = alertDb();
    $rules = new AlertRules($db);
    $t = 1_700_000_000;

    $rules->evaluate('disk', 'critical', 96.0, $t);

    $recovered = $rules->evaluate('disk', null, 40.0, $t + 60);
    assertTrue($recovered['notify'], 'ต้องบอกว่าหายแล้ว');
    assertSame('recovered', $recovered['reason'], 'ต้องเป็น recovered');

    assertSame(0, count($rules->active()), 'สถานะต้องถูกลบทิ้ง ไม่ใช่ค้างไว้');

    // รอบถัดไปที่ยังปกติต้องไม่ส่งซ้ำ
    $stillOk = $rules->evaluate('disk', null, 40.0, $t + 120);
    assertTrue(!$stillOk['notify'], 'ปกติต่อเนื่องต้องไม่ส่งอะไรเลย');
});

test('ปกติมาตลอดต้องไม่ส่ง "หายแล้ว" ทั้งที่ไม่เคยแจ้งว่าเสีย', static function (): void {
    // ถ้าพลาดข้อนี้ ทุกเกณฑ์ที่ปกติจะยิงข้อความ "กลับสู่ปกติ" ทุก 5 นาที
    // — คือสแปมที่หนักกว่าการแจ้งซ้ำตอนเสียอีก เพราะเกิดกับทุกเกณฑ์พร้อมกัน
    $rules = new AlertRules(alertDb());

    $result = $rules->evaluate('memory', null, 30.0, 1_700_000_000);

    assertTrue(!$result['notify'], 'ไม่เคยผิดปกติต้องไม่มีข้อความ');
});

test('แต่ละเกณฑ์นับรอบของตัวเอง ไม่ปนกัน', static function (): void {
    // คีย์ของบริการและใบรับรองสร้างจากชื่อ (`service:nginx`) จึงมีได้ไม่จำกัด
    // ถ้าสถานะปนกัน ปัญหาของเว็บหนึ่งจะกลบการแจ้งเตือนของอีกเว็บเงียบ ๆ
    $rules = new AlertRules(alertDb());
    $t = 1_700_000_000;

    $rules->evaluate('service:nginx', 'critical', 0.0, $t);
    $other = $rules->evaluate('service:mariadb', 'critical', 0.0, $t + 60);

    assertTrue($other['notify'], 'บริการอีกตัวต้องแจ้งของตัวเอง');
    assertSame('new', $other['reason'], 'ต้องเป็นครั้งแรกของคีย์นั้น');
    assertSame(2, count($rules->active()), 'ต้องเก็บสถานะแยกกันสองแถว');
});

test('เกณฑ์เปอร์เซ็นต์ต้องตัดที่ค่าที่ประกาศไว้พอดี', static function (): void {
    [$warning, $critical] = AlertRules::THRESHOLDS['disk'];

    assertSame(null, AlertRules::levelForPercent('disk', $warning - 0.1), 'ต่ำกว่าเกณฑ์ = ปกติ');
    assertSame('warning', AlertRules::levelForPercent('disk', $warning), 'ที่เกณฑ์พอดีต้องเตือน');
    assertSame('warning', AlertRules::levelForPercent('disk', $critical - 0.1), 'ใต้วิกฤต = เตือน');
    assertSame('critical', AlertRules::levelForPercent('disk', $critical), 'ที่วิกฤตพอดีต้องวิกฤต');

    // หน่วยความจำใช้เกณฑ์สูงกว่าโดยตั้งใจ — Linux ใช้ที่ว่างเป็น cache เป็นปกติ
    assertSame(null, AlertRules::levelForPercent('memory', 86.0), 'แรม 86% ยังปกติ');

    assertSame(null, AlertRules::levelForPercent('ไม่มีเกณฑ์นี้', 100.0), 'ชนิดที่ไม่รู้จักต้องไม่เตือน');
});

test('load ต้องเทียบต่อคอร์ ไม่ใช่ค่าดิบ', static function (): void {
    // นี่คือข้อที่พลาดแล้วเสียหายชัดที่สุด: load 4.0 บนเครื่อง 8 คอร์คือสบาย ๆ
    // แต่ถ้าเทียบค่าดิบจะเตือนทุก 5 นาทีตลอดไป จนคนปิดการแจ้งเตือนทิ้ง
    assertSame(null, AlertRules::levelForLoad(4.0, 8), 'เครื่อง 8 คอร์ที่ load 4 ยังปกติ');
    assertSame('critical', AlertRules::levelForLoad(4.0, 1), 'เครื่องคอร์เดียวที่ load 4 คือวิกฤต');

    assertSame('warning', AlertRules::levelForLoad(AlertRules::LOAD_WARNING * 4, 4), 'ที่เกณฑ์เตือนพอดี');
    assertSame('critical', AlertRules::levelForLoad(AlertRules::LOAD_CRITICAL * 4, 4), 'ที่เกณฑ์วิกฤตพอดี');

    // จำนวนคอร์ที่อ่านไม่ได้ต้องไม่ทำให้หารด้วยศูนย์
    assertSame('critical', AlertRules::levelForLoad(10.0, 0), 'คอร์ 0 ต้องไม่ระเบิด');
});

test('ใบรับรองต้องเตือนก่อนที่ certbot จะสายเกินแก้', static function (): void {
    // certbot ต่ออายุที่ 30 วัน · เกณฑ์เตือนต้องต่ำกว่านั้น ไม่งั้นจะเตือนทุกใบตลอดเวลา
    // ทั้งที่ระบบทำงานปกติ — แต่ต้องไม่ต่ำจนไม่เหลือเวลาให้แก้ด้วยมือ
    assertTrue(AlertRules::CERT_WARNING_DAYS < 30, 'เกณฑ์เตือนต้องต่ำกว่าจุดที่ certbot ต่ออายุ');
    assertTrue(AlertRules::CERT_CRITICAL_DAYS > 0, 'ต้องเตือนก่อนหมดอายุจริง');

    assertSame(null, AlertRules::levelForCertDays(AlertRules::CERT_WARNING_DAYS + 1), 'ยังเหลือเวลาพอ');
    assertSame('warning', AlertRules::levelForCertDays(AlertRules::CERT_WARNING_DAYS), 'ที่เกณฑ์พอดีต้องเตือน');
    assertSame('critical', AlertRules::levelForCertDays(AlertRules::CERT_CRITICAL_DAYS), 'ที่วิกฤตพอดี');
    assertSame('critical', AlertRules::levelForCertDays(-3), 'หมดอายุไปแล้วต้องวิกฤต ไม่ใช่ปกติ');
});
