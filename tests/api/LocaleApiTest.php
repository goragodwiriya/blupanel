<?php

declare(strict_types=1);

/**
 * สองภาษาจริง — ข้อความที่ API ส่งออกไปต้องเปลี่ยนตามภาษาที่ผู้ใช้เลือก
 *
 * **สภาพก่อนหน้านี้ (2026-08-12):** ปุ่มสลับ TH/EN มีอยู่บนหัวจอ แต่ข้อความทุกอย่าง
 * ที่เซิร์ฟเวอร์ส่งมาเป็นภาษาไทยตายตัว · กด EN แล้วได้หน้าจอครึ่งอังกฤษครึ่งไทย
 * ซึ่งแย่กว่าภาษาเดียวล้วน ๆ ทั้งสองแบบ
 *
 * กติกาที่ชุดนี้เฝ้า:
 *   - โค้ดเขียนอังกฤษเสมอ (ข้อความอังกฤษคือคีย์ของคลังคำไปในตัว)
 *   - คลังคำมีไฟล์เดียวทั้งโปรเจกต์ — `public/assets/spa/lang/th.json` ที่หน้าเว็บใช้อยู่
 *   - คีย์ที่โค้ดส่งออกไปต้องมีคำแปลไทยครบ ไม่งั้นผู้ใช้ไทยจะเจออังกฤษโผล่กลางหน้า
 */

use Phpcp\Security\Permissions;
use Phpcp\Support\Translator;

group('สองภาษา — ข้อความจากเซิร์ฟเวอร์ต้องเปลี่ยนตามภาษาที่เลือก');

function localeHarness(): ApiHarness
{
    static $harness = null;

    if ($harness === null) {
        $harness = ApiHarness::boot();
        $harness->createUser('localeadmin', 'Locale-Admin-Pass-11', Permissions::SUPERADMIN);
    }

    return $harness;
}

function localeLogin(): ApiHarness
{
    $harness = localeHarness();
    $harness->forget();
    $harness->clearRateLimits();
    $harness->request('GET', '/api/v2/session');
    $harness->request('POST', '/api/v2/session', ['username' => 'localeadmin', 'password' => 'Locale-Admin-Pass-11']);

    return $harness;
}

test('คลังคำของหน้าเว็บกับของเซิร์ฟเวอร์เป็นไฟล์เดียวกัน', static function (): void {
    // สองไฟล์แยกกันเมื่อไหร่ก็หลุดจากกันเมื่อนั้น — คำแปลที่เพิ่มฝั่งหนึ่งจะหายอีกฝั่ง
    $file = PHPCP_ROOT . '/public/assets/spa/lang/th.json';

    assertTrue(is_file($file), 'ต้องมีคลังคำอยู่ที่เดิมที่หน้าเว็บโหลด');

    $translator = Translator::load('th', dirname($file));

    assertSame('ไม่พบเว็บไซต์ที่ระบุ', $translator->get('Website not found'), 'ต้องอ่านคลังคำเดียวกันได้');
});

test('ทุกข้อความบนหน้าจอต้องมีคำแปลไทย', static function (): void {
    /*
     * เทสต์เดิมตรึงเฉพาะข้อความที่ **controller** ส่งออกไป — แต่ข้อความส่วนใหญ่ที่ผู้ใช้เห็น
     * อยู่ใน**เทมเพลต** และไม่มีอะไรตรวจเลย · ตอนไล่ตรวจทั้งโปรเจกต์เจอค้างอยู่ 50 ข้อความ
     * กระจายใน 9 หน้า ซึ่งขึ้นเป็นภาษาอังกฤษปนอยู่กลางหน้าจอภาษาไทยมาตลอดโดยไม่มีใครเห็น
     *
     * ครอบสองที่ที่ผู้ใช้อ่านจริง: ข้อความในธาตุที่มี `data-i18n` และข้อความยืนยันก่อน
     * ทำคำสั่ง (`data-confirm`) ซึ่งเป็นจุดที่ภาษาผิดแล้วอันตรายที่สุด เพราะคนกำลังจะกด
     * ยืนยันสิ่งที่ตัวเองอ่านไม่ออก
     *
     * `{LNG_...}` เป็นกลไกคนละตัว (แทนค่าฝั่งเซิร์ฟเวอร์) จึงข้ามไป
     */
    $lang = json_decode(
        (string) file_get_contents(PHPCP_ROOT . '/public/assets/spa/lang/th.json'),
        true,
    );

    assertTrue(is_array($lang), 'คลังคำต้องอ่านเป็น JSON ได้');

    $missing = [];

    foreach (glob(PHPCP_ROOT . '/public/assets/spa/templates/*.html') ?: [] as $file) {
        $html = (string) file_get_contents($file);
        $name = basename($file);

        if (preg_match_all('~<([a-z0-9]+)[^>]*\bdata-i18n\b[^>]*>(.*?)</\1>~is', $html, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $hit) {
                $text = trim((string) preg_replace('~\s+~', ' ', strip_tags($hit[2])));

                if ($text === '' || str_contains($text, '{LNG_') || str_contains($text, '{{')) {
                    continue;
                }

                if (!array_key_exists($text, $lang)) {
                    $missing[] = "{$name}: {$text}";
                }
            }
        }

        if (preg_match_all('~data-confirm="([^"]+)"~', $html, $confirms)) {
            foreach ($confirms[1] as $text) {
                $text = html_entity_decode($text);

                if (!array_key_exists($text, $lang)) {
                    $missing[] = "{$name}: {$text}";
                }
            }
        }
    }

    assertSame([], $missing, "ข้อความบนหน้าจอที่ยังไม่มีคำแปลไทย:\n  " . implode("\n  ", $missing));
});

test('ภาษาอังกฤษไม่ต้องมีคลังคำ — ข้อความในโค้ดคือคำตอบอยู่แล้ว', static function (): void {
    $translator = Translator::load('en', PHPCP_ROOT . '/public/assets/spa/lang');

    assertSame('Website not found', $translator->get('Website not found'), 'ต้องคืนตัวมันเอง');
});

test('คีย์ที่ยังไม่มีคำแปลต้องคืนภาษาอังกฤษ ไม่ใช่ค่าว่าง', static function (): void {
    // ค่าว่างคือกล่องแจ้งเตือนที่ไม่มีข้อความ ซึ่งแย่กว่าข้อความผิดภาษา
    $translator = Translator::load('th', PHPCP_ROOT . '/public/assets/spa/lang');

    assertSame(
        'Nothing translated this yet',
        $translator->get('Nothing translated this yet'),
        'คีย์ที่ไม่มีคำแปลต้องคืนตัวมันเอง',
    );
});

test('ค่าที่แทรกใช้รูปเดียวกับฝั่ง Now.js', static function (): void {
    $translator = new Translator('th', ['Website {domain} created' => 'สร้างเว็บไซต์ {domain} แล้ว']);

    assertSame(
        'สร้างเว็บไซต์ shop.example.com แล้ว',
        $translator->get('Website {domain} created', ['domain' => 'shop.example.com']),
        'ต้องแทนค่าหลังแปล ไม่ใช่ก่อน — ไม่งั้นคีย์จะไม่ตรงกับคลังคำ',
    );
});

test('คุกกี้ภาษาเปลี่ยนภาษาของคำตอบจริง', static function (): void {
    $harness = localeLogin();

    // ค่าเริ่มต้นคืออังกฤษ — ข้อความต้นทางในโค้ดออกไปตรง ๆ
    $english = $harness->request('GET', '/api/v2/sites/999999');

    assertSame(404, $english->status, 'เว็บไซต์ที่ไม่มีอยู่ต้องเป็น 404');
    assertSame('Website not found', $english->json['error']['message'] ?? '', 'ค่าเริ่มต้นต้องเป็นอังกฤษ');

    $thai = $harness->withCookie('phpcp_lang', 'th')->request('GET', '/api/v2/sites/999999');

    assertSame(
        'ไม่พบเว็บไซต์ที่ระบุ',
        $thai->json['error']['message'] ?? '',
        'คุกกี้ที่หน้าเว็บเขียนไว้ต้องเปลี่ยนภาษาของข้อความจากเซิร์ฟเวอร์',
    );
});

test('ภาษาที่ไม่รู้จักต้องตกกลับไปอังกฤษ ไม่ใช่พังทั้งคำขอ', static function (): void {
    // ค่านี้มาจากคุกกี้ที่ผู้ใช้แก้เองได้ — `../../etc/passwd` ต้องไม่กลายเป็นชื่อไฟล์
    $harness = localeLogin();

    $response = $harness->withCookie('phpcp_lang', '../../etc/passwd')->request('GET', '/api/v2/sites/999999');

    assertSame(404, $response->status, 'ต้องยังตอบตามปกติ');
    assertSame('Website not found', $response->json['error']['message'] ?? '', 'ต้องได้อังกฤษ');
});

test('ทุกข้อความที่ controller ส่งออกไปต้องมีคำแปลไทย', static function (): void {
    /*
     * นี่คือเทสต์ที่กันไม่ให้ผู้ใช้ไทยเจออังกฤษโผล่กลางหน้า
     *
     * ข้อความใหม่ที่เขียนเป็นอังกฤษอย่างเดียวจะผ่านทุกเทสต์อื่นได้สบาย ๆ เพราะระบบ
     * ทำงานถูกต้องทุกอย่าง — สิ่งเดียวที่ผิดคือคนไทยอ่านไม่ออก ซึ่งไม่มีอะไรจับได้
     * นอกจากตรวจตรงนี้
     */
    $translator = Translator::load('th', PHPCP_ROOT . '/public/assets/spa/lang');
    $missing = [];

    foreach (glob(PHPCP_ROOT . '/src/Http/V2/*.php') ?: [] as $file) {
        $source = (string) file_get_contents($file);

        // ตัดคอมเมนต์ออกก่อน — ข้อความในคำอธิบายไม่ได้ถูกส่งไปไหน
        $source = (string) preg_replace('~/\*.*?\*/|//[^\n]*~s', '', $source);

        // ข้อความที่ส่งถึงผู้ใช้จริง ๆ เท่านั้น: done/problem/completed/refreshed/t()
        preg_match_all(
            "~(?:->done\(|->completed\(|->refreshed\(|->problem\([^,]+,\s*|->t\(|'message'\s*=>\s*)'([^']{4,})'~",
            $source,
            $matches,
        );

        foreach ($matches[1] as $key) {
            // ข้ามข้อความที่ประกอบจากตัวแปร และคีย์ที่หน้าเว็บแปลเอง
            if (str_contains($key, '$') || str_starts_with($key, '{LNG_')) {
                continue;
            }

            if (!$translator->has($key)) {
                $missing[] = basename($file) . ': ' . $key;
            }
        }
    }

    assertSame([], $missing, "ข้อความเหล่านี้ยังไม่มีคำแปลไทยใน th.json:\n  " . implode("\n  ", $missing));
});
