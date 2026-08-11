<?php

declare(strict_types=1);

/**
 * สเปก OpenAPI ต้องตรงกับเส้นทางที่มีอยู่จริง — PLAN-V2 §B4.1 และหลักการ A4
 *
 * "เขียนคู่กับโค้ด ไม่ใช่ตามหลัง" จะเป็นจริงได้ก็ต่อเมื่อมีอะไรบังคับ — ไม่งั้นสเปก
 * จะค่อย ๆ ล้าหลังจนไม่มีใครเชื่อ แล้วกลายเป็นไฟล์ที่ไม่มีใครอ่านและไม่มีใครลบ
 *
 * เทสต์นี้ไม่ได้ตรวจว่าสเปก "ถูกต้องสมบูรณ์" (ต้องใช้ตัว validate ที่เป็น dependency ภายนอก
 * ซึ่งโปรเจกต์นี้ไม่มี) แต่ตรวจข้อที่พลาดบ่อยที่สุดและเจ็บที่สุด: เพิ่ม endpoint แล้วลืมเขียนสเปก
 */

use Phpcp\Http\ApiProblem;
use Phpcp\Kernel\Routes;

group('REST API v2 — สเปกต้องตรงกับโค้ด');

test('ทุกเส้นทางของ v2 มีอยู่ในสเปก OpenAPI', static function (): void {
    $spec = (string) file_get_contents(PHPCP_ROOT . '/docs/openapi.yaml');
    $missing = [];

    foreach (Routes::build()->routes() as $route) {
        if (!str_starts_with($route->path, ApiProblem::PREFIX)) {
            continue;
        }

        $path = substr($route->path, strlen(ApiProblem::PREFIX)) ?: '/';

        // สเปกเขียน path ไว้ใต้ `paths:` ด้วยการเยื้องสองช่อง
        if (!str_contains($spec, "\n  {$path}:")) {
            $missing[] = "{$route->method} {$route->path}";
        }
    }

    assertTrue(
        $missing === [],
        'เส้นทางที่ยังไม่มีในสเปก: ' . implode(', ', $missing),
    );
});

test('สเปกไม่มีเส้นทางที่ไม่มีอยู่จริงในโค้ด', static function (): void {
    $spec = (string) file_get_contents(PHPCP_ROOT . '/docs/openapi.yaml');

    $known = [];
    foreach (Routes::build()->routes() as $route) {
        if (str_starts_with($route->path, ApiProblem::PREFIX)) {
            $known[] = substr($route->path, strlen(ApiProblem::PREFIX)) ?: '/';
        }
    }

    // path ในสเปกคือบรรทัดที่เยื้องสองช่อง ขึ้นต้นด้วย / และลงท้ายด้วย :
    preg_match_all('/^  (\/[^\s:]*):$/m', $spec, $matches);

    $extra = array_values(array_diff($matches[1], $known));

    assertTrue(
        $extra === [],
        'สเปกมีเส้นทางที่ไม่มีในโค้ด (สัญญาที่ไม่มีใครทำตาม): ' . implode(', ', $extra),
    );
});

test('ทุก {พารามิเตอร์} ในเส้นทางต้องจับคู่ได้จริง', static function (): void {
    // **เคยเป็นบั๊กจริงและเทสต์เดิมจับไม่ได้:** `Router::compile()` แปลงเฉพาะ
    // `{[a-z_]+}` — เส้นทางที่เขียน `{siteId}` แบบ camelCase จึงไม่ถูกแปลงเป็น regex
    // และ **ไม่มีวันจับคู่กับคำขอใด ๆ เลย** · ผลคือ 4 เส้นทางของ B3.2/B3.5 ตายสนิท
    // โดยที่เทสต์ contract ยังเขียว เพราะ 404 ก็เป็น JSON ที่ถูกรูปแบบเหมือนกัน
    //
    // เทสต์นี้ตรวจที่ "เส้นทางจับคู่กับ URL จริงได้ไหม" ไม่ใช่แค่รูปแบบของชื่อ
    $router = Routes::build();
    $dead = [];

    foreach ($router->routes() as $route) {
        if (!str_contains($route->path, '{')) {
            continue;
        }

        // แทนค่าพารามิเตอร์ทุกตัวด้วยค่าตัวอย่างแล้วลองจับคู่ดู
        $probe = preg_replace('/\{[^}]+\}/', '123', $route->path) ?? '';
        $match = $router->match($route->method, $probe);

        if ($match === null || $match['route']->path !== $route->path) {
            $dead[] = "{$route->method} {$route->path}";
        }
    }

    assertTrue($dead === [], 'เส้นทางที่จับคู่ไม่ได้เลย (พารามิเตอร์ผิดรูปแบบ): ' . implode(', ', $dead));
});

test('รหัสข้อผิดพลาดในสเปกตรงกับ enum ในโค้ดทุกตัว', static function (): void {
    $spec = (string) file_get_contents(PHPCP_ROOT . '/docs/openapi.yaml');

    foreach (ApiProblem::cases() as $case) {
        assertTrue(
            str_contains($spec, '- ' . $case->value),
            "รหัส {$case->value} มีในโค้ดแต่ไม่ได้ประกาศไว้ในสเปก",
        );
    }

    // และในทางกลับกัน: สเปกต้องไม่สัญญารหัสที่โค้ดไม่มีวันส่งออกมา
    preg_match_all('/^                - ([A-Z_]+)$/m', $spec, $matches);

    foreach (array_unique($matches[1]) as $code) {
        assertTrue(
            ApiProblem::tryFrom($code) !== null,
            "สเปกประกาศรหัส {$code} ที่ไม่มีอยู่ใน enum ของโค้ด",
        );
    }
});

test('คำตอบของคำสั่งต้องไม่ซ้อนคีย์ data', static function (): void {
    // Now.js แกะคำตอบด้วย `response.data.data ?? response.data` — มันเลือกชั้นในทันที
    // ที่มี `data` ซ้อนอยู่ · คำตอบของคำสั่งที่ส่งทั้ง payload และ `actions` มาพร้อมกัน
    // จะทำให้ `actions` ไม่ถูกเห็นเลย แล้วผลลัพธ์ที่ผู้ใช้ต้องอ่าน (รหัสผ่านที่สุ่มให้)
    // หายไปเงียบ ๆ ทั้งที่คำขอรายงานว่าสำเร็จ
    //
    // กฎคือ **คำตอบหนึ่งทำหน้าที่เดียว** — `ok()` ใช้กับ endpoint แบบอ่านเท่านั้น
    // ส่วนคำสั่งใช้ `done()` / `completed()` ซึ่งไม่มีทางสร้างคีย์ `data` ได้เลย
    // ข้อยกเว้นที่ตั้งใจ — POST ที่คืน **สถานะให้โปรแกรมอ่าน** ไม่ใช่คำสั่งให้หน้าจอทำ
    //
    //   session       จุด bootstrap ของ SPA — `auth.js` อ่าน user/permissions จากคำตอบนี้
    //                 โดยตรง ถ้าเปลี่ยนเป็น actions หน้าเว็บจะไม่รู้ว่าตัวเองเป็นใคร
    //   phpmyadmin    คืน URL ปลายทางให้หน้าเว็บพาไปต่อ (คุกกี้ signon ถูกตั้งจากคำตอบนี้)
    $stateReturning = [
        'SessionController.php::create()',
        'SessionController.php::verifyTwoFactor()',
        'PhpMyAdminController.php::create()',
    ];

    $offenders = [];

    foreach (glob(PHPCP_ROOT . '/src/Http/V2/*.php') ?: [] as $file) {
        $source = (string) file_get_contents($file);
        $name = basename($file);

        // เมธอดที่ผูกกับเส้นทางแบบเปลี่ยนสถานะต้องไม่เรียก ok()
        foreach (commandMethodsOf($name) as $method) {
            if (preg_match('/function ' . preg_quote($method, '/') . '\(.*?\n    \}/s', $source, $m) !== 1) {
                continue;
            }

            $label = "{$name}::{$method}()";

            if (str_contains($m[0], '$this->ok(') && !in_array($label, $stateReturning, true)) {
                $offenders[] = $label;
            }
        }
    }

    assertSame([], $offenders, "เมธอดของคำสั่งต้องใช้ done()/completed() ไม่ใช่ ok():\n  " . implode("\n  ", $offenders));
});

/**
 * ชื่อเมธอดที่ผูกกับเส้นทางแบบเปลี่ยนสถานะของ controller หนึ่ง
 *
 * @return list<string>
 */
function commandMethodsOf(string $controllerFile): array
{
    $class = 'Phpcp\\Http\\V2\\' . basename($controllerFile, '.php');
    $methods = [];

    foreach (Phpcp\Kernel\Routes::build()->routes() as $route) {
        if ($route->controller === $class && $route->method !== 'GET') {
            $methods[] = $route->action;
        }
    }

    return array_values(array_unique($methods));
}
