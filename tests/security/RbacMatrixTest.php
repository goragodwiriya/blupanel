<?php

declare(strict_types=1);

/**
 * ตาราง role × permission — SECURITY §6
 *
 * ยืนยันกฎที่ PROMPT.md กำหนดไว้: การแยก Hosting กับ Server ต้องบังคับด้วยสิทธิ์จริง
 * ไม่ใช่แค่ซ่อนเมนู ผู้ดูแลเว็บไซต์ต้องไม่มี permission ของหมวด SERVER แม้แต่ตัวเดียว
 */

use Phpcp\Agent\Actor;
use Phpcp\Kernel\Routes;
use Phpcp\Security\Permissions;

group('RbacMatrix — ขอบเขตสิทธิ์ของแต่ละบทบาท');

/** permission ที่ถือว่าเป็นของชั้นเซิร์ฟเวอร์ */
const SERVER_PREFIXES = ['server.', 'service.', 'security.', 'firewall.', 'ssh.', 'log.', 'user.', 'settings.', 'audit.'];

test('ผู้ดูแลเว็บไซต์ไม่มี permission ของหมวด SERVER เลย', static function (): void {
    foreach (Permissions::forRole(Permissions::WEBADMIN) as $permission) {
        foreach (SERVER_PREFIXES as $prefix) {
            assertTrue(
                !str_starts_with($permission, $prefix),
                "ผู้ดูแลเว็บไซต์ต้องไม่มี {$permission}",
            );
        }
    }
});

/**
 * เมนูของ SPA — อ่านจาก `public/assets/spa/js/ui.js` ตรง ๆ
 *
 * เมนูย้ายไปอยู่ฝั่งเบราว์เซอร์ตอนลบ UI แบบ HTML · เทสต์จึงอ่านตารางนั้นจากไฟล์
 * แทนที่จะเรียก `Navigation::forRole()` ที่ไม่มีอยู่แล้ว — สิ่งที่ต้องคุ้มครองคือ
 * **"ผู้ใช้ต้องไม่เจอเมนูที่กดแล้วได้ 403"** ซึ่งไม่ได้เปลี่ยนไปตามภาษาที่เขียนเมนู
 *
 * @return list<array{section:string,label:string,url:string,permission:string}>
 */
function spaMenu(): array
{
    static $menu = null;

    if ($menu !== null) {
        return $menu;
    }

    $source = file_get_contents(PHPCP_ROOT . '/public/assets/spa/js/ui.js');
    $block = substr($source, (int) strpos($source, 'const MENU = ['));
    $block = substr($block, 0, (int) strpos($block, "\n  ];"));

    $menu = [];
    $section = '';

    foreach (explode("\n", $block) as $line) {
        if (preg_match("/label: '([^']*)',\s*$/", $line, $m) === 1) {
            $section = $m[1];
            continue;
        }

        if (preg_match("/label: '([^']+)', url: '([^']+)'.*permission: '([^']+)'/", $line, $m) === 1) {
            $menu[] = ['section' => $section, 'label' => $m[1], 'url' => $m[2], 'permission' => $m[3]];
        }
    }

    if (count($menu) < 10) {
        throw new RuntimeException('อ่านเมนูจาก ui.js ไม่ได้ — รูปแบบไฟล์เปลี่ยนไปแล้วหรือเปล่า');
    }

    return $menu;
}

test('ผู้ดูแลเว็บไซต์ไม่เห็นหมวด SERVER ในเมนู', static function (): void {
    $sections = array_column(spaMenu(), 'section');

    assertTrue(in_array('SERVER', $sections, true), 'เมนูต้องมีหมวด SERVER อยู่จริงถึงจะทดสอบได้');
    assertTrue(in_array('HOSTING', $sections, true), 'เมนูต้องมีหมวด HOSTING');

    foreach (spaMenu() as $item) {
        if ($item['section'] !== 'SERVER') {
            continue;
        }

        assertTrue(
            !Permissions::roleHas(Permissions::WEBADMIN, $item['permission']),
            "ผู้ดูแลเว็บไซต์ต้องไม่มีสิทธิ์ของเมนู {$item['label']} ในหมวด SERVER",
        );
    }

    assertTrue(!Permissions::seesServerSection(Permissions::WEBADMIN), 'ต้องไม่เห็นหมวดเซิร์ฟเวอร์');
});

test('ผู้ดูแลเซิร์ฟเวอร์ดู Hosting ได้แต่แก้ไม่ได้', static function (): void {
    $role = Permissions::SYSADMIN;

    foreach (['site.view', 'domain.view', 'db.view', 'backup.view'] as $permission) {
        assertTrue(Permissions::roleHas($role, $permission), "ผู้ดูแลเซิร์ฟเวอร์ต้องดูได้: {$permission}");
    }

    foreach (['site.create', 'site.delete', 'site.edit', 'db.manage', 'file.manage', 'backup.restore'] as $permission) {
        assertTrue(!Permissions::roleHas($role, $permission), "ผู้ดูแลเซิร์ฟเวอร์ต้องแก้ไม่ได้: {$permission}");
    }
});

test('ผู้ดูแลเซิร์ฟเวอร์จัดการผู้ใช้ panel ไม่ได้', static function (): void {
    // กันการยกระดับสิทธิ์ตัวเองด้วยการสร้างบัญชี superadmin ใหม่ (SECURITY §2.5)
    assertTrue(Permissions::roleHas(Permissions::SYSADMIN, 'user.view'), 'ดูรายชื่อผู้ใช้ได้');
    assertTrue(!Permissions::roleHas(Permissions::SYSADMIN, 'user.manage'), 'แก้ไขผู้ใช้ไม่ได้');
    assertTrue(!Permissions::roleHas(Permissions::SYSADMIN, 'settings.manage'), 'แก้ค่าตั้งระบบไม่ได้');
});

test('ผู้ดูแลระบบมีสิทธิ์ครบทุกตัว', static function (): void {
    $all = array_keys(Permissions::all());
    $granted = Permissions::forRole(Permissions::SUPERADMIN);

    assertSame([], array_values(array_diff($all, $granted)), 'ผู้ดูแลระบบต้องมีสิทธิ์ครบ');
});

test('บทบาทที่ไม่มีอยู่จริงไม่ได้สิทธิ์ใด ๆ', static function (): void {
    foreach (['root', 'admin', '', 'SUPERADMIN', 'superadmin '] as $role) {
        assertSame([], Permissions::forRole($role), "บทบาท '{$role}' ต้องไม่ได้สิทธิ์อะไรเลย");
        assertTrue(!Permissions::isValidRole($role), "'{$role}' ต้องไม่ใช่บทบาทที่ถูกต้อง");
    }
});

test('ทุกเส้นทางต้องประกาศ permission อย่างจงใจ', static function (): void {
    // เส้นทางสาธารณะที่อนุญาตให้ permission เป็น null ได้ ต้องระบุไว้ที่นี่เท่านั้น
    //
    // /api/v2/session — จุด bootstrap ของ SPA: ต้องเรียกได้ก่อนล็อกอินเพื่อรู้ว่า
    //   ล็อกอินอยู่หรือยัง และเพื่อรับ CSRF token มาใช้กับฟอร์มล็อกอิน (PLAN-V2 §4.4)
    //   ทั้ง POST (เข้าสู่ระบบ) และ DELETE (ออกจากระบบ) ก็ต้องเรียกได้โดยไม่มีสิทธิ์ใด ๆ
    //   ด้วยเหตุผลเดียวกับ /login และ /logout ของ UI เดิม
    //
    // /app และ /app/{page} — shell ของ SPA (เฟส C): เป็นไฟล์ HTML นิ่งไฟล์เดียวที่ไม่มี
    //   ข้อมูลของผู้ใช้อยู่เลยแม้แต่ชื่อผู้ใช้ · ทุกอย่างที่แสดงบนหน้าจอมาจาก
    //   /api/v2/* ซึ่งบังคับสิทธิ์เต็มรูปแบบเหมือนเดิมทุกเส้นทาง · ต้องเปิดสาธารณะ
    //   เพราะหน้าล็อกอินเองก็อยู่ใน shell ตัวนี้
    //
    // / — เด้งไป /app/ เฉย ๆ ไม่แตะข้อมูลอะไรเลย · ถ้าปิดไว้ คนที่ยังไม่ล็อกอิน
    //   จะเปิดรากโดเมนแล้วได้ 401 แทนที่จะได้หน้าล็อกอิน
    $publicPaths = [
        '/', '/api/v2/session', '/api/v2/session/2fa',
        // รูปที่ลงท้ายด้วย / มีเพราะ mod_dir ของ Apache เปลี่ยน /app เป็น /app/ ให้เอง
        '/app', '/app/', '/app/{page}', '/app/{page}/',
    ];
    $known = array_keys(Permissions::all());

    foreach (Routes::build()->routes() as $route) {
        if ($route->permission === null) {
            assertTrue(
                in_array($route->path, $publicPaths, true),
                "เส้นทาง {$route->method} {$route->path} ไม่ได้ประกาศ permission และไม่ได้อยู่ในรายการสาธารณะ",
            );
            continue;
        }

        assertTrue(
            in_array($route->permission, $known, true),
            "เส้นทาง {$route->path} อ้าง permission ที่ไม่มีอยู่จริง: {$route->permission}",
        );
    }
});

test('ทุกเมนูอ้าง permission ที่มีอยู่จริง', static function (): void {
    // สะกดผิดหนึ่งตัวใน ui.js = เมนูนั้นหายไปจากทุกบทบาทเงียบ ๆ เพราะ `can()`
    // ของสิทธิ์ที่ไม่มีอยู่จริงคืน false เสมอ — ไม่มีอะไรฟ้องนอกจากเทสต์นี้
    $known = array_keys(Permissions::all());

    foreach (spaMenu() as $item) {
        assertTrue(
            in_array($item['permission'], $known, true),
            "เมนู {$item['label']} อ้าง permission ที่ไม่มีอยู่จริง: {$item['permission']}",
        );
    }
});

test('ทุกเมนูชี้ไปหน้าที่มีอยู่ และใช้สิทธิ์เดียวกับหน้านั้น', static function (): void {
    // เมนูกับตารางเส้นทางอยู่คนละไฟล์ (ui.js / main.js) · ถ้าสองที่ไม่ตรงกัน ผู้ใช้จะ
    // กดเมนูแล้วได้หน้า "ไม่พบ" หรือแย่กว่านั้นคือเห็นเมนูที่กดเข้าไปแล้วโดนเด้งออก
    $main = file_get_contents(PHPCP_ROOT . '/public/assets/spa/js/main.js');

    preg_match_all(
        "/'([^']+)': \{ template: '[^']+', title: '[^']*'(?:, permission: '([^']+)')? \}/",
        $main,
        $matches,
        PREG_SET_ORDER,
    );

    $routes = [];

    foreach ($matches as $match) {
        $routes[$match[1]] = $match[2] ?? null;
    }

    assertTrue(count($routes) > 20, 'อ่านตารางเส้นทางจาก main.js ไม่ได้ — รูปแบบไฟล์เปลี่ยนไปแล้วหรือเปล่า');

    foreach (spaMenu() as $item) {
        assertTrue(
            array_key_exists($item['url'], $routes),
            "เมนู {$item['label']} ชี้ไป {$item['url']} ซึ่งไม่มีในตารางเส้นทางของ SPA",
        );
        assertSame(
            $routes[$item['url']],
            $item['permission'],
            "เมนู {$item['label']} ใช้สิทธิ์ไม่ตรงกับหน้า {$item['url']}",
        );
    }
});

test('ทุกบทบาทเห็นเฉพาะเมนูที่ตัวเองมีสิทธิ์', static function (): void {
    // ผู้ใช้ต้องไม่เจอเมนูที่กดแล้วได้ 403 — ตัวกรองจริงคือ `data-can` ใน ui.js
    // ที่เรียก `PhpcpAuth.can()` ต่อรายการ · เทสต์นี้ตรึงว่าอย่างน้อยหนึ่งบทบาท
    // ต้องเห็นเมนูนั้น ไม่งั้นมันคือเมนูตายที่ไม่มีใครกดได้เลย
    foreach (spaMenu() as $item) {
        $visible = array_filter(
            [Permissions::SUPERADMIN, Permissions::SYSADMIN, Permissions::WEBADMIN],
            static fn (string $role): bool => Permissions::roleHas($role, $item['permission']),
        );

        assertTrue($visible !== [], "เมนู {$item['label']} ไม่มีบทบาทไหนเห็นเลย");
    }
});

test('Actor คำนวณสิทธิ์จาก role ไม่ใช่จากค่าที่ส่งมา', static function (): void {
    // ฝั่งเว็บส่ง permission เพิ่มมาเองไม่ได้ agent คำนวณใหม่จาก role เสมอ
    $webadmin = Actor::fromArray([
        'user_id' => 5,
        'username' => 'somchai',
        'role' => Permissions::WEBADMIN,
        'permissions' => ['service.control', 'user.manage'],   // ค่าที่แอบใส่มา
    ]);

    assertTrue(!$webadmin->can('service.control'), 'ต้องไม่ได้สิทธิ์ที่แอบใส่มาใน payload');
    assertTrue(!$webadmin->can('user.manage'), 'ต้องไม่ได้สิทธิ์ที่แอบใส่มาใน payload');
    assertTrue($webadmin->can('site.view'), 'ต้องได้สิทธิ์ตามบทบาทจริง');
});

test('Actor ปฏิเสธบทบาทที่ไม่ถูกต้อง', static function (): void {
    assertRejects(
        \Phpcp\Agent\ValidationError::class,
        static fn () => Actor::fromArray(['user_id' => 1, 'username' => 'x', 'role' => 'root']),
        'ต้องปฏิเสธบทบาทที่ไม่มีในระบบ',
    );
});
