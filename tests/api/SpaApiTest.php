<?php

declare(strict_types=1);

/**
 * สิ่งที่เฟส C เพิ่มเข้ามา — PLAN-V2 เฟส C
 *
 * สามเรื่องที่เทสต์ชุดนี้ตรึงไว้ และเหตุผลที่แต่ละเรื่องคุ้มค่าที่จะมีเทสต์:
 *
 * 1. **shell ของ SPA ต้องเปิดได้โดยไม่ต้องล็อกอิน และต้องไม่รั่วอะไรออกไป** —
 *    มันเป็นเส้นทางสาธารณะเส้นเดียวที่เพิ่มเข้ามาในระบบตั้งแต่เฟส B
 * 2. **`GET /dashboard` ต้องตอบ 200 แม้ agent ล่ม** — เป็นข้อยกเว้นที่ตั้งใจให้ต่างจาก
 *    เส้นทางอื่นของ v2 ถ้าใครมาแก้ให้ล้มตาม agent หน้าแรกจะว่างในจังหวะที่ต้องการ
 *    ข้อมูลที่สุด · เทสต์นี้คือสิ่งเดียวที่กันการถอยกลับนั้น
 * 3. **ไฟล์ของ Now.js ต้องตรงกับ SHA256SUMS** — การตัดสินใจ N8 · `phpcp doctor`
 *    ตรวจบนเครื่องที่ติดตั้งแล้ว แต่เทสต์ตรวจตั้งแต่ก่อน commit
 */

use Phpcp\Http\ApiProblem;
use Phpcp\Security\Permissions;

group('SPA และสิ่งที่เฟส C เพิ่ม');

function spaHarness(): ApiHarness
{
    static $harness = null;

    if ($harness !== null) {
        return $harness;
    }

    $harness = ApiHarness::boot();
    $harness->createUser('spaadmin', 'Spa-Admin-Pass-11', Permissions::SUPERADMIN);
    $harness->createUser('spaweb', 'Spa-Web-Pass-22', Permissions::WEBADMIN);

    return $harness;
}

function spaLogin(string $username, string $password): ApiHarness
{
    $harness = spaHarness();
    $harness->forget();
    $harness->clearRateLimits();
    $harness->request('GET', '/api/v2/session');

    $login = $harness->request('POST', '/api/v2/session', ['username' => $username, 'password' => $password]);

    if ($login->status !== 200) {
        throw new RuntimeException("เตรียมเทสต์ไม่สำเร็จ: ล็อกอินได้ {$login->status}");
    }

    return $harness;
}

test('shell ของ SPA เปิดได้โดยไม่ต้องล็อกอิน และมีแต่โครงเปล่า', static function (): void {
    $harness = spaHarness();
    $harness->forget();

    // รูปที่ลงท้ายด้วย / ต้องใช้ได้ด้วย เพราะผู้ใช้พิมพ์กันเป็นปกติ
    foreach (['/app', '/app/', '/app/sites', '/app/sites/', '/app/logs'] as $path) {
        $response = $harness->request('GET', $path);

        assertSame(200, $response->status, "{$path} ต้องเปิดได้โดยไม่ต้องล็อกอิน");
    }

    $body = $harness->request('GET', '/app')->body;

    // ไม่มีข้อมูลของผู้ใช้อยู่ในไฟล์นี้เลย — ทุกอย่างมาจาก /api/v2/* ตอนรันจริง
    assertTrue(str_contains($body, '/assets/spa/js/main.js'), 'shell ต้องโหลดสคริปต์ของแอป');
    // ตัดคอมเมนต์ออกก่อน — คอมเมนต์ในไฟล์อธิบายไว้ว่า "ไม่มี <meta name=csrf-token> ที่นี่โดยตั้งใจ"
    // ซึ่งเป็นข้อความที่ต้องอยู่ต่อ ไม่ใช่แท็กจริงที่ต้องห้าม
    $markup = preg_replace('/<!--.*?-->/s', '', $body) ?? $body;

    assertTrue(
        preg_match('/<meta[^>]+name=["\']csrf-token/', $markup) !== 1,
        'shell ต้องไม่ฝัง CSRF token — SPA ขอเองจาก /session',
    );
    assertTrue(!str_contains($body, 'spaadmin'), 'shell ต้องไม่มีชื่อผู้ใช้คนใดอยู่ในนั้น');
});

test('shell ใช้เส้นทางสัมบูรณ์ทุกไฟล์ ไม่งั้นหน้าย่อยจะโหลดสคริปต์ไม่เจอ', static function (): void {
    // /app/sites ถูกเสิร์ฟด้วยไฟล์เดียวกับ /app · เส้นทางสัมพัทธ์จะกลายเป็น
    // เส้นทางที่ผิดทันทีที่ผู้ใช้กดรีเฟรชบนหน้าย่อย และ <base href> ใช้แก้ไม่ได้
    // เพราะ CSP ของ panel ตั้ง base-uri 'none'
    $html = (string) file_get_contents(PHPCP_ROOT . '/public/assets/spa/index.html');

    preg_match_all('/(?:src|href)="([^"]+)"/', $html, $matches);

    foreach ($matches[1] as $url) {
        assertTrue(
            str_starts_with($url, '/') || str_starts_with($url, 'http'),
            "shell อ้างไฟล์ด้วยเส้นทางสัมพัทธ์: {$url}",
        );
    }
});

test('แดชบอร์ดตอบ 200 แม้ agent ล่ม — หน้าแรกต้องเปิดได้เสมอ', static function (): void {
    $harness = spaLogin('spaadmin', 'Spa-Admin-Pass-11');

    $response = $harness->request('GET', '/api/v2/dashboard');

    assertSame(200, $response->status, 'แดชบอร์ดต้องไม่ล้มตาม agent');
    assertSame(false, $response->data('agent')['available'], 'ต้องรายงานว่า agent ใช้งานไม่ได้');
    assertTrue(is_array($response->data('counts')), 'ตัวเลขจากฐานข้อมูลของ panel ต้องยังใช้ได้');
    assertTrue(is_array($response->data('activity')), 'กิจกรรมล่าสุดต้องยังอ่านได้');
});

test('แดชบอร์ดของลูกค้าไม่มีบริการและ audit log ของทั้งเครื่อง', static function (): void {
    $harness = spaLogin('spaweb', 'Spa-Web-Pass-22');

    $response = $harness->request('GET', '/api/v2/dashboard');

    assertSame(200, $response->status, 'ลูกค้าต้องเปิดหน้าแรกได้');
    assertSame([], $response->data('services'), 'ลูกค้าไม่มีสิทธิ์ service.view');
    assertSame([], $response->data('activity'), 'ลูกค้าไม่มีสิทธิ์ audit.view');
    assertSame(0, $response->data('counts')['users'], 'ลูกค้าต้องไม่เห็นจำนวนผู้ใช้ทั้งระบบ');
});

test('กิจกรรมล่าสุดต้องไม่ส่งกลไกภายในของ hash-chain ออกไป', static function (): void {
    $harness = spaLogin('spaadmin', 'Spa-Admin-Pass-11');

    // การล็อกอินข้างบนเขียน audit log ไปแล้ว จึงมีอย่างน้อยหนึ่งรายการเสมอ
    $activity = $harness->request('GET', '/api/v2/dashboard')->data('activity');

    assertTrue($activity !== [], 'ต้องมีรายการอย่างน้อยหนึ่งรายการจากการล็อกอิน');

    foreach ($activity as $entry) {
        assertTrue(!array_key_exists('hash', $entry), 'ต้องไม่ส่ง hash ของ audit chain');
        assertTrue(!array_key_exists('prev_hash', $entry), 'ต้องไม่ส่ง prev_hash');
        assertTrue(!array_key_exists('detail_json', $entry), 'ต้องไม่ส่ง argument ดิบของคำสั่ง');
    }
});

test('ลูกค้าดูงานตามเวลาของระบบไม่ได้', static function (): void {
    $harness = spaLogin('spaweb', 'Spa-Web-Pass-22');

    $response = $harness->request('GET', '/api/v2/scheduled-jobs');

    assertSame(403, $response->status, 'งานตามเวลาเป็นของหมวด SERVER');
    assertSame(ApiProblem::Forbidden->value, $response->errorCode(), 'ต้องเป็น FORBIDDEN');
});

test('งานตามเวลาบอกได้ว่าตัวจับเวลายังเดินอยู่ไหม', static function (): void {
    $harness = spaLogin('spaadmin', 'Spa-Admin-Pass-11');

    $response = $harness->request('GET', '/api/v2/scheduled-jobs');

    assertSame(200, $response->status, 'ผู้ดูแลต้องดูได้');
    assertTrue(is_array($response->json['data']), 'ต้องเป็นรายการ');
    assertTrue(is_bool($response->json['meta']['stale'] ?? null), 'ต้องบอกได้ว่าตัวจับเวลาค้างหรือไม่');
});

test('ไฟล์ของ Now.js ตรงกับ SHA256SUMS ที่ commit ไว้', static function (): void {
    // การตัดสินใจ N8 — ถ้าไฟล์ dist ถูกอัปเดตโดยไม่อัปเดต checksum เทสต์นี้จะจับได้
    // ก่อนขึ้นเซิร์ฟเวอร์ ซึ่งเป็นจังหวะเดียวที่ยังแก้ได้ง่าย
    $dir = PHPCP_ROOT . '/public/assets/spa/vendor/now';
    $sums = (string) file_get_contents($dir . '/SHA256SUMS');

    assertTrue(trim($sums) !== '', 'ต้องมี SHA256SUMS ที่ไม่ว่าง');

    foreach (preg_split('/\R/', trim($sums)) ?: [] as $line) {
        [$expected, $name] = preg_split('/\s+/', trim($line), 2);
        $file = $dir . '/' . $name;

        assertTrue(is_file($file), "ไม่มีไฟล์ {$name} ที่ SHA256SUMS อ้างถึง");

        /*
         * บอกวิธีแก้มาด้วย — **บนเครื่องนักพัฒนาเท่านั้น**
         *
         * `icons.css` เป็นไฟล์ที่โปรเจกต์นี้แก้เองได้ (คัดมาจากต้นน้ำแล้วตัด @import
         * ของ Google Fonts ออก) การเพิ่มไอคอนจึงเป็นงานปกติที่จบด้วยการอัปเดตบรรทัด
         * ของมันในไฟล์นี้ · ถ้าไม่บอกไว้ คนที่เจอเทสต์แดงจะไม่รู้ว่าต้องพิมพ์อะไร
         *
         * ข้อความนี้อยู่ในเทสต์ ไม่ใช่ใน `phpcp doctor` โดยตั้งใจ — บนเครื่องที่
         * ติดตั้งแล้ว checksum ไม่ตรงแปลว่า **มีคนแก้ไฟล์บนดิสก์** ซึ่งคำแนะนำที่ถูก
         * คือไปหาว่าใครแก้ ไม่ใช่เขียนทับ hash ให้ตรงกับของที่ถูกแก้ไปแล้ว
         */
        assertSame(
            $expected,
            hash_file('sha256', $file),
            "checksum ของ {$name} ไม่ตรง — ถ้าตั้งใจแก้ไฟล์นี้ ให้อัปเดต SHA256SUMS ด้วย:\n"
            . "  cd public/assets/spa/vendor/now && awk '{print \$2}' SHA256SUMS"
            . " | xargs -d '\\n' sha256sum > SHA256SUMS.new && mv SHA256SUMS.new SHA256SUMS",
        );
    }
});

test('SPA ไม่ดึงอะไรจาก CDN เลย', static function (): void {
    // หลัก supply-chain เดิมของโปรเจกต์: ติดตั้งบนเครื่องที่ไม่มีเน็ตแล้วต้องใช้ได้
    // และ CSP ของ panel เป็น default-src 'none' อยู่แล้ว การอ้าง origin ภายนอกจึงพังเงียบ ๆ
    $files = array_merge(
        glob(PHPCP_ROOT . '/public/assets/spa/*.html') ?: [],
        glob(PHPCP_ROOT . '/public/assets/spa/css/*.css') ?: [],
        glob(PHPCP_ROOT . '/public/assets/spa/vendor/now/*.css') ?: [],
    );

    foreach ($files as $file) {
        $content = (string) file_get_contents($file);
        $name = basename($file);

        assertTrue(!str_contains($content, '@import url(\'http'), "{$name} มี @import จาก origin ภายนอก");
        assertTrue(!str_contains($content, 'fonts.googleapis.com'), "{$name} อ้าง Google Fonts");
        assertTrue(!str_contains($content, 'cdn.jsdelivr'), "{$name} อ้าง CDN");
        assertTrue(!str_contains($content, 'unpkg.com'), "{$name} อ้าง CDN");
    }
});

test('ทุกแถวของตารางในเทมเพลตมีทั้งป้ายและช่องข้อมูล', static function (): void {
    // เจอจากของจริง: regex ที่ตั้งใจถอดค่าออกจาก `data-i18n` เผลอโลภ แล้วกิน
    // `Hostname</th><td class="mono" data-text="hostname"` ไปด้วยทั้งท่อน เหลือ
    // `<tr><th data-i18n></td></tr>` · หน้ายังเรนเดอร์ครบ ตารางยังขึ้นทุกแถว console
    // สะอาด **แต่ทุกช่องว่างเปล่า** — ไม่มีอะไรในระบบฟ้องเลยนอกจากเปิดดูด้วยตา
    //
    // เทสต์นี้จึงตรวจสองอย่างที่ตารางต้องมีเสมอ: `<th>` ต้องมีข้อความ และแถวที่มี
    // `<th>` ต้องมี `<td>` คู่กัน
    $problems = [];

    foreach (glob(PHPCP_ROOT . '/public/assets/spa/templates/*.html') ?: [] as $file) {
        $name = basename($file);

        foreach (explode("\n", (string) file_get_contents($file)) as $number => $line) {
            if (!str_contains($line, '<th')) {
                continue;
            }

            $at = $name . ':' . ($number + 1);

            // `<th ...>ข้อความ</th>` — หัวตารางที่ไม่มีข้อความคือหัวที่ผู้ใช้อ่านไม่ออก
            if (preg_match('#<th[^>]*>\s*</th>#', $line) === 1) {
                $problems[] = "{$at} มี <th> ที่ไม่มีข้อความ";
            }

            // แถวที่เขียนจบในบรรทัดเดียวต้องมี <td> ด้วย ไม่งั้นคือแถวที่เสียรูป
            if (str_contains($line, '<tr>') && str_contains($line, '</tr>') && !str_contains($line, '<td')) {
                $problems[] = "{$at} เป็นแถวที่มีแต่ <th> ไม่มี <td>";
            }

            // ปิดแท็กผิดชนิดในบรรทัดเดียวกัน = ร่องรอยของการถูกตัดกลางทาง
            if (preg_match('#<th[^>]*>[^<]*</td>#', $line) === 1) {
                $problems[] = "{$at} เปิด <th> แล้วปิดด้วย </td>";
            }
        }
    }

    assertSame([], $problems, "เทมเพลตเสียรูป:\n  " . implode("\n  ", $problems));
});

test('คำสั่งที่ตั้งเวลาถอนคืนต้องทำให้แถบรอการยืนยันขึ้นทันที', static function (): void {
    /*
     * **กลไกความปลอดภัยทั้งหมดของ RollbackGuard พังทันทีถ้าผู้ใช้ไม่เห็นแถบ**
     *
     * แถบถามสถานะเองทุก 15 วินาที ซึ่งนานพอที่ผู้ใช้จะกดบันทึกแล้วปิดหน้าไปก่อน —
     * แล้วค่าที่เพิ่งตั้งจะถูกคืนกลับเงียบ ๆ โดยเขาไม่รู้เลยว่าต้องกดยืนยัน และไม่รู้ว่า
     * ทำไมค่าที่ตั้งไว้หายไป
     *
     * ต้องยิงสัญญาณให้แถบถามใหม่ทันทีทั้งสองเส้นทาง เพราะสองเส้นทางนี้ไม่ผ่านโค้ด
     * ชุดเดียวกันเลย: `requestApi` **ไม่เรียก ResponseHandler**, ส่วนฟอร์มกับปุ่มในแถว
     * ตารางเรียก
     */
    $ui = (string) file_get_contents(PHPCP_ROOT . '/public/assets/spa/js/ui.js');

    // เส้นทางที่ 1 — ปุ่มที่เปลี่ยนแปลงข้อมูล (apiRefresh)
    // ตัดตั้งแต่ต้นฟังก์ชันจนถึง action ตัวถัดไป — ไม่ผูกกับความยาวคอมเมนต์ที่เปลี่ยนได้
    $start = strpos($ui, "registerAction('apiRefresh'") ?: 0;
    $next = strpos($ui, 'registerAction(', $start + 20);
    $apiRefresh = substr($ui, $start, ($next === false ? strlen($ui) : $next) - $start);

    assertTrue(
        str_contains($apiRefresh, "emit('phpcp:rollback'"),
        'apiRefresh ต้องยิงสัญญาณให้แถบรอการยืนยันถามสถานะใหม่',
    );

    // เส้นทางที่ 2 — ฟอร์มและปุ่มในแถวตาราง (ResponseHandler.process)
    $begin = strpos($ui, 'ResponseHandler.process = async function') ?: 0;
    $process = substr($ui, $begin, (strpos($ui, 'const Ui = {', $begin) ?: strlen($ui)) - $begin);

    // ตัดคอมเมนต์ก่อนตรวจ — คำอธิบายในไฟล์ยกรูปแบบที่ห้ามเขียนมาให้ดูตรง ๆ
    // ถ้าไม่ตัด เทสต์จะฟ้องคำอธิบายที่ดีแทนที่จะฟ้องโค้ดที่ผิด
    $code = (string) preg_replace('~/\*.*?\*/|//[^\n]*~s', '', $process);

    assertTrue(
        str_contains($process, "emit('phpcp:rollback'"),
        'คำตอบที่แนบผลการตั้งเวลาถอนคืนต้องทำให้แถบขึ้นทันที',
    );
    assertTrue(
        str_contains($process, 'rollback_id') && str_contains($process, 'pending_rollback'),
        'ต้องรู้จักทั้งสองชื่อ — capability คืน rollback_id ส่วน controller แนบ pending_rollback',
    );

    /*
     * **ห้ามเขียน `payload.data || payload`** — FormManager ส่งผลจาก
     * `normalizeSubmitBindingPayload()` ซึ่งใส่ `data: []` ให้เสมอเมื่อคำตอบไม่มีคีย์นั้น
     * · อาร์เรย์ว่างเป็น truthy ใน JS การเขียนแบบนั้นจึงไปอ่านอาร์เรย์ว่างแทนตัว payload
     * แล้วเงื่อนไขไม่มีวันเป็นจริง — แถบไม่ขึ้นและไม่มี error ให้เห็นเลย
     */
    assertTrue(
        !str_contains($code, 'payload.data || payload'),
        'อาร์เรย์ว่างเป็น truthy — `payload.data || payload` จะไปอ่านอาร์เรย์ว่างแทน payload',
    );
    assertTrue(
        str_contains($code, 'Array.isArray(body.data)'),
        'ต้องแยกกรณี data ที่เป็นอาร์เรย์ออกจากอ็อบเจกต์ก่อนอ่านค่าข้างใน',
    );

    // และแถบต้องฟังสัญญาณนั้นจริง ไม่ใช่ยิงไปแล้วไม่มีใครรับ
    assertTrue(
        str_contains($ui, "EventManager.on('phpcp:rollback'"),
        'แถบรอการยืนยันต้องฟังสัญญาณนี้',
    );
});

test('ฟอร์มใน Modal ต้องไม่มีตัวแปรค้างในเส้นทางที่ส่ง', static function (): void {
    /*
     * **`{id}` ถูกเติมตอนเทมเพลตของ*หน้า*ยังเป็นสตริง** (`RouterManager.render`) ·
     * เทมเพลตที่ถูกเปิดเป็น Modal ทีหลังไม่ผ่านขั้นนั้น ตัวแปรในเส้นทางจึงถูกส่งไป
     * ตรง ๆ เป็นตัวอักษร แล้วปลายทางปฏิเสธด้วยข้อความที่ชวนงงอย่าง "รหัสไม่ถูกต้อง"
     *
     * เจอจริงตอนทำหน้าไฟล์ตั้งค่า: กดบันทึกแล้วขึ้น "รหัสไฟล์ตั้งค่าไม่ถูกต้อง" ทุกครั้ง
     * ไม่ว่าจะกรอกอะไร เพราะสิ่งที่ส่งไปคือสตริง `{key}` ตามตัวอักษร
     *
     * ค่าที่ต้องส่งให้ใช้ช่องซ่อนที่ผูกค่าด้วยกลไกเดียวกับช่องอื่นในฟอร์มแทน
     */
    $problems = [];

    // เทมเพลตที่ถูกเปิดเป็น Modal — ประกาศไว้ฝั่ง controller
    $modalTemplates = [];

    foreach (glob(PHPCP_ROOT . '/src/Http/V2/*.php') ?: [] as $file) {
        preg_match_all("/'template'\s*=>\s*'([\w.-]+\.html)'/", (string) file_get_contents($file), $matches);
        $modalTemplates = array_merge($modalTemplates, $matches[1]);
    }

    foreach (array_unique($modalTemplates) as $template) {
        $path = PHPCP_ROOT . '/public/assets/spa/templates/' . $template;

        if (!is_file($path)) {
            continue;
        }

        $html = (string) preg_replace('/<!--.*?-->/s', '', (string) file_get_contents($path));

        if (preg_match_all('/<form[^>]*action="([^"]*)"/i', $html, $forms) === 0) {
            continue;
        }

        foreach ($forms[1] as $action) {
            if (str_contains($action, '{')) {
                $problems[] = $template . ': ' . $action;
            }
        }
    }

    assertSame(
        [],
        array_values(array_unique($problems)),
        "ฟอร์มใน Modal เหล่านี้มีตัวแปรค้างในเส้นทาง ไม่มีใครเติมค่าให้ — ส่งเป็นช่องซ่อนแทน:\n  "
        . implode("\n  ", array_unique($problems)),
    );
});

test('ฟอร์มที่เปิดใน Modal ต้องสั่งปิด Modal เมื่อบันทึกสำเร็จ', static function (): void {
    /*
     * **Modal ถูกเปิดด้วยคำสั่งจากเซิร์ฟเวอร์ การปิดจึงต้องเป็นคำสั่งจากเซิร์ฟเวอร์ด้วย**
     *
     * ไม่มีคำสั่งปิด ฟอร์มจะค้างอยู่บนจอทั้งที่บันทึกสำเร็จไปแล้ว · ผู้ใช้เห็นฟอร์มเดิม
     * พร้อมข้อความว่าสำเร็จ แล้วมักกดบันทึกซ้ำเพราะเข้าใจว่ายังไม่ติด — ได้ข้อมูลซ้ำ
     * สองรายการ · เดิมเป็นแบบนี้ทุกฟอร์มในระบบ ไม่ใช่หน้าใดหน้าหนึ่ง
     *
     * แยก "Modal ที่เป็นฟอร์ม" ออกจาก "Modal ที่แสดงผลอย่างเดียว" ด้วยวิธีที่โค้ดบอกเอง:
     * ฟอร์มเปิดด้วย `'template' => 'x-form.html'` ส่วนกล่องแสดงผล (รหัสผ่านที่สุ่มให้ ·
     * เนื้อเมลในคิว) เปิดด้วย `'content'`/`'html'` ซึ่งผู้ใช้ปิดเองเมื่ออ่านเสร็จ
     */
    $problems = [];

    foreach (glob(PHPCP_ROOT . '/src/Http/V2/*.php') ?: [] as $file) {
        $source = (string) file_get_contents($file);
        $code = (string) preg_replace('~/\*.*?\*/|//[^\n]*~s', '', $source);

        // เปิด Modal ที่เป็นฟอร์มหรือเปล่า
        if (preg_match("/'template'\s*=>\s*'[\w.-]+\.html'/", $code) !== 1) {
            continue;
        }

        // ปิดได้สองทาง: สั่งตรง ๆ หรือผ่านตัวช่วย saved() ที่ใส่คำสั่งปิดให้เอง
        if (str_contains($code, "'action' => 'close'") || str_contains($code, '$this->saved(')) {
            continue;
        }

        $problems[] = basename($file);
    }

    assertSame(
        [],
        $problems,
        "controller เหล่านี้เปิดฟอร์มใน Modal แต่ไม่เคยสั่งปิด — ใช้ saved() หรือใส่ "
        . "['type' => 'modal', 'action' => 'close'] เป็นคำสั่งแรก:\n  " . implode("\n  ", $problems),
    );
});

test('ปุ่มที่เปลี่ยนแปลงข้อมูลต้องใช้ apiRefresh ไม่ใช่ requestApi', static function (): void {
    /*
     * **`requestApi` ไม่อ่าน `actions` ที่เซิร์ฟเวอร์ส่งกลับมาเลย**
     *
     * มันขึ้นข้อความแจ้งผลแล้วจบ · คำสั่ง "โหลดใหม่" ที่ controller อุตส่าห์ส่งมาถูกทิ้ง
     * ทั้งหมด — ต่างจากปุ่มในแถวตารางซึ่ง `TableManager` ส่งต่อให้ `ResponseHandler`
     * (ยืนยันจากซอร์สของเฟรมเวิร์ก 2026-08-13)
     *
     * อาการคือ **กดแล้วขึ้นว่าสำเร็จ แต่หน้าจอยังโชว์ของเดิม** — ผู้ใช้กดซ้ำเพราะคิดว่า
     * ไม่ติด · โปรเจกต์มี `apiRefresh` ที่ห่อ `requestApi` แล้วรีเฟรชต่อให้อยู่แล้ว
     *
     * ปุ่มที่ "ทำแล้วไม่มีอะไรบนจอต้องเปลี่ยน" (ส่งเมลทดสอบ · เปิด Modal) ใช้
     * `requestApi` ได้ตามเดิม จึงยกเว้นด้วย `data-notify-success` และเส้นทาง `/form`
     */
    $problems = [];

    foreach (glob(PHPCP_ROOT . '/public/assets/spa/templates/*.html') ?: [] as $file) {
        $html = (string) preg_replace('/<!--.*?-->/s', '', (string) file_get_contents($file));

        if (preg_match_all('/<button[^>]*>/i', $html, $tags) === 0) {
            continue;
        }

        foreach ($tags[0] as $tag) {
            if (!str_contains($tag, 'click.prevent:requestApi')) {
                continue;
            }

            preg_match('/data-api-method="(\w+)"/', $tag, $method);
            preg_match('/data-api-url="([^"]*)"/', $tag, $url);

            $verb = strtolower($method[1] ?? 'get');
            $target = $url[1] ?? '';

            // อ่านอย่างเดียว = ไม่มีอะไรเปลี่ยน · `/form` เปิด Modal · `data-notify-success`
            // คือปุ่มที่ผลลัพธ์เป็นข้อความอย่างเดียว (ส่งเมล/ข้อความทดสอบ)
            if (!in_array($verb, ['post', 'put', 'patch', 'delete'], true)
                || str_contains($target, '/form')
                || str_contains($tag, 'data-notify-success')
            ) {
                continue;
            }

            $problems[] = basename($file) . ': ' . strtoupper($verb) . ' ' . ($target !== '' ? $target : '(ตั้งค่าเส้นทางตอนเรนเดอร์)');
        }
    }

    assertSame(
        [],
        $problems,
        "ปุ่มเหล่านี้เปลี่ยนแปลงข้อมูลแต่หน้าจอจะไม่อัปเดตหลังกด — ใช้ apiRefresh แทน:\n  "
        . implode("\n  ", $problems),
    );
});

test('ปุ่มลบที่ปลายทางบังคับให้ยืนยันชื่อโดเมน ต้องส่งชื่อนั้นไปด้วย', static function (): void {
    /*
     * **ปุ่มที่ยิงคำขอโดยขาดค่าที่ปลายทางบังคับ = ปุ่มที่กดยังไงก็ล้ม**
     *
     * `ssl.delete` กับ `site.delete` เทียบ `confirm_domain` กับโดเมนจริงก่อนลบ — ด่านนี้
     * ถูกต้องแล้วและต้องอยู่ · แต่ปุ่ม Remove ในหน้า SSL ไม่เคยส่งค่านั้นมาเลย กดแล้วได้
     * "ชื่อโดเมนที่ยืนยันไม่ตรงกับเว็บไซต์ปลายทาง" ทุกครั้งตั้งแต่วันแรก ไม่มีทางสำเร็จ
     *
     * เทสต์นี้อ่านจากโค้ดจริงสองฝั่งมาเทียบกัน ไม่ได้ฮาร์ดโค้ดรายชื่อปุ่มไว้ — ปุ่มใหม่ที่
     * ยิงไปยังปลายทางแบบเดียวกันจะถูกจับได้ทันทีโดยไม่ต้องมีใครนึกออกว่าต้องมาแก้เทสต์
     */
    $routes = (string) file_get_contents(PHPCP_ROOT . '/src/Kernel/Routes.php');

    // เส้นทาง DELETE ทั้งหมด → [path, controller, method]
    preg_match_all(
        "/new Route\(\s*'DELETE'\s*,\s*'([^']+)'\s*,\s*([A-Za-z0-9_]+)::class\s*,\s*'([^']+)'/",
        $routes,
        $matches,
        PREG_SET_ORDER,
    );

    /** ดึงเนื้อเมธอดออกมาด้วยการนับวงเล็บปีกกา — เทียบทั้งไฟล์จะติดเมธอดอื่นที่ไม่เกี่ยว */
    $methodBody = static function (string $source, string $name): string {
        $start = strpos($source, 'function ' . $name . '(');

        if ($start === false) {
            return '';
        }

        $open = strpos($source, '{', $start);

        if ($open === false) {
            return '';
        }

        $depth = 0;

        for ($i = $open, $len = strlen($source); $i < $len; $i++) {
            $depth += $source[$i] === '{' ? 1 : ($source[$i] === '}' ? -1 : 0);

            if ($depth === 0) {
                return substr($source, $open, $i - $open + 1);
            }
        }

        return '';
    };

    // เส้นทางไหนบังคับ confirm_domain บ้าง
    $needsConfirm = [];

    foreach ($matches as [, $path, $controller, $action]) {
        foreach (glob(PHPCP_ROOT . '/src/Http/**/*.php') ?: [] as $file) {
            if (basename($file, '.php') !== $controller) {
                continue;
            }

            if (str_contains($methodBody((string) file_get_contents($file), $action), 'confirm_domain')) {
                $needsConfirm[] = $path;
            }
        }
    }

    assertTrue($needsConfirm !== [], 'ต้องเจอเส้นทางที่บังคับ confirm_domain อย่างน้อยหนึ่งเส้น ไม่งั้นเทสต์นี้ผ่านฟรี');

    /** เส้นทางนี้ตรงกับเส้นทางที่บังคับยืนยันไหม — เทียบทีละส่วน `{...}` แทนอะไรก็ได้หนึ่งส่วน */
    $matchesRoute = static function (string $url, string $route): bool {
        $url = strtok($url, '?') ?: $url;
        $left = explode('/', trim($url, '/'));
        $right = explode('/', trim($route, '/'));

        if (count($left) !== count($right)) {
            return false;
        }

        foreach ($right as $i => $segment) {
            if (!str_starts_with($segment, '{') && $segment !== $left[$i]) {
                return false;
            }
        }

        return true;
    };

    $problems = [];

    foreach (glob(PHPCP_ROOT . '/public/assets/spa/templates/*.html') ?: [] as $file) {
        $html = (string) preg_replace('/<!--.*?-->/s', '', (string) file_get_contents($file));
        $actions = [];

        // 1) ปุ่มในตาราง — อ่านจาก JSON ของ data-row-actions ตรง ๆ ไม่เดาจากข้อความรอบ ๆ
        if (preg_match_all("/data-row-actions='([^']*)'/s", $html, $blocks) > 0) {
            foreach ($blocks[1] as $json) {
                foreach ((array) json_decode($json, true) as $name => $action) {
                    if (!is_array($action)) {
                        continue;
                    }

                    $actions[] = [
                        'label' => (string) $name,
                        'method' => strtolower((string) ($action['method'] ?? 'get')),
                        'url' => (string) ($action['url'] ?? ''),
                        'sends' => array_key_exists('confirm_domain', (array) ($action['params'] ?? [])),
                    ];
                }
            }
        }

        // 2) ปุ่มเดี่ยว — ดูเฉพาะภายในแท็กนั้นแท็กเดียว ไม่ใช่หน้าต่างรอบ ๆ
        if (preg_match_all('/<[a-z]+[^>]*data-api-url="[^"]*"[^>]*>/i', $html, $tags) > 0) {
            foreach ($tags[0] as $tag) {
                preg_match('/data-api-url="([^"]*)"/', $tag, $url);
                preg_match('/data-api-method="([^"]*)"/', $tag, $method);

                $actions[] = [
                    'label' => 'ปุ่มเดี่ยว',
                    'method' => strtolower($method[1] ?? 'get'),
                    'url' => $url[1] ?? '',
                    // ส่งได้สองทาง: data-param-* ตรง ๆ หรือ data-attr เติมให้ตอนเรนเดอร์
                    'sends' => str_contains($tag, 'confirm_domain'),
                ];
            }
        }

        foreach ($actions as $action) {
            if ($action['method'] !== 'delete' || $action['sends']) {
                continue;
            }

            foreach ($needsConfirm as $route) {
                if ($matchesRoute($action['url'], $route)) {
                    $problems[] = basename($file) . ': ' . $action['label'] . ' → ' . $action['url'];
                }
            }
        }
    }

    assertSame(
        [],
        array_values(array_unique($problems)),
        "ปุ่มลบเหล่านี้ไม่ส่ง confirm_domain ปลายทางจะปฏิเสธทุกครั้ง:\n  "
        . implode("\n  ", array_unique($problems)),
    );
});

test('`permissions[...]` ใช้ได้เฉพาะหน้าที่โหลด /api/v2/session มาเท่านั้น', static function (): void {
    /*
     * **ปุ่มที่ผูกกับสิ่งที่ไม่มีในขอบเขต จะหายไปทั้งปุ่มโดยไม่มี error ให้เห็น**
     *
     * `permissions` ไม่ใช่ของกลางของแอป — มันคือฟิลด์หนึ่งในคำตอบของ `/api/v2/session`
     * คอมโพเนนต์ที่โหลด endpoint อื่นจึงไม่มีมันในขอบเขตเลย · หน้าที่ต้องใช้ทั้งข้อมูลของ
     * ตัวเองและสิทธิ์ ต้องให้ **endpoint ของตัวเองตอบสิทธิ์มาด้วย** (`meta.can_*` หรือ
     * `can[...]`) แบบที่ SitesController::show ทำอยู่แล้ว
     *
     * เจอตอนทำ M3: ปุ่มผูกใบรับรองไม่ขึ้นบนหน้าจอเลย ทั้งที่โค้ดถูกทุกอย่าง เพราะมันอยู่
     * ในคอมโพเนนต์ซ้อนที่โหลด `/api/v2/mail/readiness` · ไล่ดูแล้วพบว่ามีปุ่มแบบเดียวกัน
     * ที่ตายอยู่เงียบ ๆ มาก่อนแล้วในสองหน้า
     */
    $problems = [];

    foreach (glob(PHPCP_ROOT . '/public/assets/spa/templates/*.html') ?: [] as $file) {
        // ตัดคอมเมนต์ก่อน — คำอธิบายในไฟล์พูดถึง `permissions[...]` ตรง ๆ
        $html = (string) preg_replace('/<!--.*?-->/s', '', (string) file_get_contents($file));

        if (!str_contains($html, 'permissions[')) {
            continue;
        }

        // ทุก endpoint ที่หน้านี้โหลด · ใช้ `permissions[...]` ได้ต่อเมื่อมี session อยู่ด้วย
        preg_match_all('/data-endpoint="([^"]*)"/', $html, $endpoints);

        if (!in_array('/api/v2/session', $endpoints[1], true)) {
            $problems[] = basename($file) . ': โหลดแต่ ' . (implode(', ', $endpoints[1]) ?: 'ไม่มี endpoint เลย');
        }
    }

    assertSame(
        [],
        $problems,
        "หน้าเหล่านี้ใช้ permissions[...] ทั้งที่ไม่มีในขอบเขต — ให้ endpoint ตอบสิทธิ์มาเองแทน:\n  "
        . implode("\n  ", $problems),
    );
});

test('data-i18n ต้องไม่มีค่า — Now.js เติมคีย์จากข้อความในแท็กเอง', static function (): void {
    // ใส่ค่าเองแล้วมีสองแหล่งความจริง: ค่าในแอตทริบิวต์กับข้อความในแท็ก ซึ่งจะค่อย ๆ
    // ไม่ตรงกันเมื่อมีคนแก้ข้อความแต่ลืมแก้คีย์ แล้วป้ายนั้นจะไม่ถูกแปลโดยไม่มีใครรู้
    $problems = [];

    foreach (glob(PHPCP_ROOT . '/public/assets/spa/templates/*.html') ?: [] as $file) {
        if (preg_match_all('#data-i18n="[^"]*"#', (string) file_get_contents($file), $matches) > 0) {
            $problems[] = basename($file) . ': ' . implode(', ', array_slice($matches[0], 0, 3));
        }
    }

    assertSame([], $problems, "data-i18n ต้องเขียนเปล่า ๆ:\n  " . implode("\n  ", $problems));
});

test('ห้ามมีข้อความไทยฝังในนิพจน์ของ data-text — ข้อความแบบนั้นไม่ผ่านตัวแปลภาษา', static function (): void {
    // ระบบแปลใช้**ข้อความอังกฤษเป็นคีย์** แล้วเทียบกับ lang/th.json (en.json จึงว่างเปล่า
    // โดยตั้งใจ) · ข้อความที่เขียนตรง ๆ ในนิพจน์ เช่น `data-text="x ? 'ใช้งานอยู่' : '—'"`
    // ไม่ผ่านตัวแปลเลย จึงค้างเป็นภาษาเดียวแม้ผู้ใช้สลับเป็นอังกฤษ
    //
    // ทางที่ถูกคือแยกเป็นหลาย <span> ที่มี `data-if` + `data-i18n` ตามปกติ
    // (เจอจากการเผลอเขียนเองตอนแก้ตารางโควตา 2026-08-10)
    $problems = [];

    foreach (glob(PHPCP_ROOT . '/public/assets/spa/templates/*.html') ?: [] as $file) {
        $html = (string) preg_replace('/<!--.*?-->/s', '', (string) file_get_contents($file));

        // มองเฉพาะค่าในแอตทริบิวต์ที่เป็นนิพจน์ ไม่ใช่ข้อความในแท็ก (ซึ่งแปลได้ปกติ)
        if (preg_match_all('/data-(?:text|attr|class|style)="([^"]*)"/', $html, $matches) === 0) {
            continue;
        }

        foreach ($matches[1] as $expression) {
            // จับอักษรไทยที่อยู่ในเครื่องหมายคำพูดเดี่ยว = ข้อความคงที่ในนิพจน์
            if (preg_match("/'[^']*[\u{0E00}-\u{0E7F}][^']*'/u", $expression) === 1) {
                $problems[] = basename($file) . ': ' . mb_substr($expression, 0, 70);
            }
        }
    }

    assertSame([], $problems, "ข้อความไทยในนิพจน์ต้องย้ายไปเป็น <span data-i18n>:\n  " . implode("\n  ", $problems));
});

test('SPA ทับตัวจัดรูป datetime ของเฟรมเวิร์ก', static function (): void {
    // `Utils.string.applyFormatters()` ค้นตามลำดับ filters ของ context → builtinFormatters
    // → `window.formatters` · ชื่อ `datetime` ชนกับตัวที่มีมากับเฟรมเวิร์ก ตัวของเราจึง
    // ไม่เคยถูกเรียก และ `| datetime` ทุกที่แสดงเป็นปี 1970 เพราะตัวเดิมตีความตัวเลข
    // เป็น**มิลลิวินาที** ส่วน API ของเราส่งเป็น**วินาที** ทั้งระบบ
    //
    // บั๊กนี้ไม่มีอะไรฟ้อง — หน้าเรนเดอร์ครบ console สะอาด เห็นทางเดียวคืออ่านวันที่
    // บนหน้าจอแล้วเทียบกับค่าที่ API ส่งมา · เทสต์นี้จึงเฝ้าว่าการทับยังอยู่
    $source = (string) file_get_contents(PHPCP_ROOT . '/public/assets/spa/js/formatters.js');

    assertTrue(
        str_contains($source, 'window.Utils.string.builtinFormatters.datetime = datetime'),
        'formatters.js ต้องทับ datetime ของเฟรมเวิร์ก ไม่งั้นทุกวันที่บนหน้าจอกลายเป็นปี 1970',
    );

    // ใช้จริงอยู่หลายหน้า — ถ้าวันหนึ่งไม่มีใครใช้แล้วก็ไม่ต้องทับอีก
    $used = 0;

    foreach (glob(PHPCP_ROOT . '/public/assets/spa/templates/*.html') ?: [] as $file) {
        $used += substr_count((string) file_get_contents($file), '| datetime');
    }

    assertTrue($used > 0, 'ไม่มีเทมเพลตไหนใช้ | datetime แล้ว — ลบการทับออกได้');
});

test('ทุกไฟล์ที่ SPA อ้างถึงมีอยู่จริงบนดิสก์', static function (): void {
    // เจอจากการลบ UI แบบ HTML: `app.css` ของ SPA อ้าง `/assets/images/logo.svg` ซึ่งอยู่
    // นอกโฟลเดอร์ของ SPA · พอลบชุดของเก่าทิ้ง โลโก้ก็หายไปโดยที่ไม่มีอะไรพังให้เห็น
    // — หน้ายังเรนเดอร์ครบ เทสต์ยังเขียว เห็นแค่ตอนเปิดดูด้วยตาหรือดู console
    //
    // เทสต์นี้จึงตามทุก URL ที่ขึ้นต้นด้วย `/assets/` ไปดูว่ามีไฟล์นั้นจริงไหม
    $public = PHPCP_ROOT . '/public';
    $files = array_merge(
        glob(PHPCP_ROOT . '/public/assets/spa/*.html') ?: [],
        glob(PHPCP_ROOT . '/public/assets/spa/css/*.css') ?: [],
        glob(PHPCP_ROOT . '/public/assets/spa/js/*.js') ?: [],
        glob(PHPCP_ROOT . '/public/assets/spa/templates/*.html') ?: [],
    );

    $checked = 0;

    foreach ($files as $file) {
        $content = (string) file_get_contents($file);
        $name = basename($file);

        preg_match_all('#/assets/[A-Za-z0-9_./-]+\.[a-z0-9]{2,5}#', $content, $matches);

        foreach (array_unique($matches[0]) as $url) {
            assertTrue(is_file($public . $url), "{$name} อ้าง {$url} แต่ไม่มีไฟล์นั้นอยู่จริง");
            $checked++;
        }
    }

    assertTrue($checked > 10, 'ต้องเจอไฟล์ที่ถูกอ้างถึงจริง ๆ ไม่ใช่ผ่านเพราะหาไม่เจอสักอัน');
});

test('ทุกคลาสไอคอนที่ SPA ใช้ มีอยู่จริงในชุด icomoon', static function (): void {
    // ไอคอนที่ไม่มีอยู่จริงไม่ทำให้อะไรพัง มันแค่**หายไปเงียบ ๆ** ซึ่งแปลว่าไม่มีใคร
    // สังเกตจนกว่าจะมีคนเปิดหน้านั้นด้วยตา · เฟส C เสียเวลากับเรื่องนี้ไปหนึ่งรอบแล้ว
    $app = PHPCP_ROOT . '/public/assets/spa';
    $css = (string) file_get_contents($app . '/vendor/now/icons.css');

    $sources = array_merge(
        glob($app . '/templates/*.html') ?: [],
        glob($app . '/js/*.js') ?: [],
    );

    $used = [];
    foreach ($sources as $file) {
        preg_match_all('/\bicon-[a-z0-9-]+/', (string) file_get_contents($file), $matches);
        $used = array_merge($used, $matches[0]);
    }

    $missing = [];
    foreach (array_unique($used) as $icon) {
        if (!str_contains($css, "\n.{$icon}:before")) {
            $missing[] = $icon;
        }
    }

    assertSame([], $missing, 'ไอคอนที่ไม่มีอยู่ในชุด: ' . implode(', ', $missing));
});

test('อ่าน log ได้โดยไม่ต้องระบุแหล่ง — ค่าเริ่มต้นที่ใช้งานได้ ไม่ใช่ข้อผิดพลาด', static function (): void {
    // หน้า Logs ของ SPA โหลดตารางก่อนที่ผู้ใช้จะเลือกอะไร ถ้าเส้นทางนี้ตอบ 403
    // เมื่อไม่มี `source` หน้าจะขึ้นพร้อม error ทุกครั้งที่เปิด ทั้งที่ยังไม่มีใครทำอะไรผิด
    $harness = spaLogin('spaadmin', 'Spa-Admin-Pass-11');

    $response = $harness->request('GET', '/api/v2/logs');

    // agent ไม่ทำงานในชุดทดสอบ จึงได้ 503 — สิ่งที่ตรึงไว้คือ **ต้องไม่ใช่ 403**
    // แปลว่าผ่านด่านเลือกแหล่งไปแล้วจริง
    assertTrue($response->status !== 403, 'ไม่ระบุแหล่งต้องไม่ถูกปฏิเสธด้วยสิทธิ์');

    $denied = $harness->request('GET', '/api/v2/logs', [], [], ['source' => 'ไม่มีจริง']);

    assertSame(403, $denied->status, 'แหล่งที่ไม่รู้จักยังต้องเป็น 403 เหมือนเดิม');
    assertSame(ApiProblem::Forbidden->value, $denied->errorCode(), 'ต้องเป็น FORBIDDEN');
});

test('ไม่มีตารางไหนมี data-field ซ้ำกัน', static function (): void {
    // TableManager ทำดัชนีคอลัมน์ด้วยชื่อฟิลด์ — สอง `<th>` ที่ใช้ฟิลด์เดียวกัน
    // ทำให้คอลัมน์แรก**หายไปเงียบ ๆ** ไม่มี error ใด ๆ ให้เห็น
    //
    // เจอจริงตอนเพิ่มคอลัมน์ปุ่ม: หน้าฐานข้อมูลผูกทั้งคอลัมน์ชื่อและคอลัมน์ปุ่มไว้กับ
    // `name` ผลคือชื่อฐานข้อมูลหายไปทั้งคอลัมน์ · ทางแก้คือให้คอลัมน์ปุ่มผูกกับฟิลด์
    // ที่ไม่ได้แสดงที่อื่น แล้วอ่านค่าที่ต้องใช้จาก `row` ที่ formatter ได้รับอยู่แล้ว
    $problems = [];

    foreach (glob(PHPCP_ROOT . '/public/assets/spa/templates/*.html') ?: [] as $file) {
        $html = (string) file_get_contents($file);

        // ตัดคอมเมนต์ HTML ออกก่อนเสมอ — `<th>` ที่ถูกคอมเมนต์ไว้ไม่ได้เรนเดอร์จริง
        // จึงชนกับคอลัมน์ที่ใช้งานอยู่ไม่ได้ · เคยทำให้เทสต์แดงทั้งที่เทมเพลตถูกต้อง
        $html = (string) preg_replace('/<!--.*?-->/s', '', $html);

        preg_match_all('/<table\b.*?<\/table>/s', $html, $tables);

        foreach ($tables[0] as $table) {
            preg_match_all('/<th\b[^>]*data-field="([^"]+)"/', $table, $fields);

            foreach (array_count_values($fields[1]) as $field => $count) {
                if ($count > 1) {
                    $problems[] = basename($file) . ' → ' . $field;
                }
            }
        }
    }

    assertSame([], $problems, 'มี data-field ซ้ำในตารางเดียวกัน: ' . implode(', ', $problems));
});

test('หน้าเว็บทุกหน้าต้องมีเนื้อหา ไม่ใช่มีแต่หัวเรื่องกับปุ่ม', static function (): void {
    /*
     * ตอนย้ายฟอร์มออกไปเป็นเทมเพลตของตัวเอง ตารางรายชื่อฐานข้อมูลถูกลบติดไปด้วย
     * เหลือแต่หัวเรื่องกับปุ่ม "เพิ่มฐานข้อมูล" · แท็กยังปิดครบทุกตัวและทุกเทสต์
     * ยังเขียว เพราะเทสต์ที่มีอยู่ล้วนตรวจ "ตารางที่มี" ว่าถูกต้องไหม ไม่มีตัวไหน
     * ถามว่า "หน้านี้ยังมีตารางอยู่หรือเปล่า"
     *
     * หน้าเต็ม (หน้าที่มี sidebar) ต้องมีอย่างน้อยหนึ่งอย่างที่เป็นเนื้อหาจริง
     */
    $problems = [];

    foreach (glob(PHPCP_ROOT . '/public/assets/spa/templates/*.html') ?: [] as $file) {
        $html = (string) preg_replace('/<!--.*?-->/s', '', (string) file_get_contents($file));

        // เทมเพลตของฟอร์มและชิ้นส่วนย่อยไม่ใช่หน้าเต็ม จึงไม่มี sidebar
        if (!str_contains($html, 'data-component="sidebar"')) {
            continue;
        }

        // นับเฉพาะสิ่งที่เป็นเนื้อหาของหน้าจริง ๆ — หัวเรื่องกับปุ่มไม่นับ
        // (แดชบอร์ดใช้การ์ดในกริดแทนตาราง จึงต้องรับรูปแบบนั้นด้วย)
        $hasContent = str_contains($html, 'data-table')
            || str_contains($html, '<form')
            || str_contains($html, 'content-body')
            || str_contains($html, 'ggrid');

        if (!$hasContent) {
            $problems[] = basename($file);
        }
    }

    assertSame([], $problems, 'หน้าเหล่านี้เปิดมาแล้วว่างเปล่า: ' . implode(', ', $problems));
});

test('ปุ่มในแถวที่ใช้ GET ต้องบอกว่าไม่ใช่การเปลี่ยนหน้า', static function (): void {
    /*
     * TableManager ตีความ `method: get` ว่า "เปิดหน้าใหม่" มาแต่ไหนแต่ไร (ปุ่มแบบ
     * "เปิดหน้าแก้ไข") · ปุ่มที่ตั้งใจจะ **เรียก API แล้วให้เซิร์ฟเวอร์สั่งเปิด Modal**
     * จึงต้องประกาศ `navigate: false` ให้ชัด
     *
     * เจอจากการกดจริง: ปุ่มแก้ไขในหน้า /cron-jobs พาไปที่ /api/v2/cron-jobs/1 แล้ว
     * router ของ SPA ตอบ not-found.html — ไม่มี error ให้เห็นสักบรรทัด
     */
    $problems = [];

    foreach (glob(PHPCP_ROOT . '/public/assets/spa/templates/*.html') ?: [] as $file) {
        $html = (string) preg_replace('/<!--.*?-->/s', '', (string) file_get_contents($file));

        preg_match_all("/data-row-actions='([^']+)'/s", $html, $matches);

        foreach ($matches[1] as $json) {
            $actions = json_decode(html_entity_decode($json, ENT_QUOTES), true);

            if (!is_array($actions)) {
                $problems[] = basename($file) . ' → data-row-actions ไม่ใช่ JSON ที่อ่านได้';
                continue;
            }

            foreach ($actions as $key => $cfg) {
                if (!is_array($cfg) || strtolower((string) ($cfg['method'] ?? '')) !== 'get') {
                    continue;
                }

                // ปุ่มที่พาไปหน้าอื่นของ SPA (`/sites/12`) ตั้งใจให้เปลี่ยนหน้าอยู่แล้ว
                // ที่ผิดคือปุ่มที่ชี้ไปที่ API ซึ่งไม่มีหน้าให้ไป
                if (!str_starts_with((string) ($cfg['url'] ?? ''), '/api/')) {
                    continue;
                }

                if (($cfg['navigate'] ?? null) !== false) {
                    $problems[] = basename($file) . ' → ' . $key;
                }
            }
        }
    }

    assertSame(
        [],
        $problems,
        'ปุ่ม GET เหล่านี้จะพาผู้ใช้ออกจากหน้าไปที่ URL ของ API: ' . implode(', ', $problems),
    );
});

test('บันเดิลของ Now.js ที่ ship ไปด้วยต้องมีตัวเลือกชั้นที่ถือ actions', static function (): void {
    /*
     * `payloadOf()` คือจุดที่ตัดสินว่า ResponseHandler จะได้ซองที่มี `actions` หรือได้
     * แค่ก้อนข้อมูล · ก่อนหน้านี้บันเดิลแกะลึกไปหนึ่งชั้น (`data.data`) ทำให้คำสั่ง
     * modal/redirect/reload ที่ API ส่งมา **หายไปทั้งหมดโดยไม่มีข้อผิดพลาด**
     *
     * ถ้าใครเอาบันเดิลเก่ากลับมาวาง ปุ่ม Add/Edit ทุกหน้าจะเงียบสนิทอีกครั้ง — เทสต์นี้
     * จับได้ทันทีว่าไฟล์ที่ commit ไว้ build มาจากเฟรมเวิร์กรุ่นที่แก้แล้วหรือยัง
     */
    foreach (['now.core.min.js', 'now.table.min.js'] as $bundle) {
        $path = PHPCP_ROOT . '/public/assets/spa/vendor/now/' . $bundle;

        assertTrue(
            str_contains((string) file_get_contents($path), 'payloadOf'),
            $bundle . ' เป็นรุ่นก่อนแก้ — actions ที่เซิร์ฟเวอร์สั่งมาจะถูกทิ้ง',
        );
    }
});

test('หัวตารางไม่มีอักขระเกินติดมากับข้อความ', static function (): void {
    // ตัวสร้างเทมเพลตรอบแรกเติม `></th>` ซ้ำ ทำให้หัวตารางอ่านว่า "Name>" ทุกคอลัมน์
    // — ไม่พังอะไร แต่เห็นเต็มหน้าจอทุกหน้าที่มีตาราง
    $problems = [];

    foreach (glob(PHPCP_ROOT . '/public/assets/spa/templates/*.html') ?: [] as $file) {
        if (preg_match('/data-i18n="([^"]*)">\1>/', (string) file_get_contents($file)) === 1) {
            $problems[] = basename($file);
        }
    }

    assertSame([], $problems, 'หัวตารางมี > เกิน: ' . implode(', ', $problems));
});
