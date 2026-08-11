<?php

declare(strict_types=1);

/**
 * site.rebuild — สร้างไฟล์ตั้งค่าของทุกเว็บใหม่หลังเปลี่ยนค่า `webserver`
 *
 * **เจอจากการใช้งานจริง (2026-08-11):** `etc/config.example.php` บอกให้รัน
 * `phpcp sites:rebuild` หลังเปลี่ยนเว็บเซิร์ฟเวอร์มาตั้งแต่ต้น แต่คำสั่งนั้น
 * **ไม่เคยมีอยู่จริง** — ผู้ดูแลทำตามเอกสารแล้วได้ "ไม่รู้จักคำสั่ง" กลับมา
 * แล้วเครื่องค้างอยู่ครึ่งทาง: config บอกว่าโหมดใหม่ แต่ไฟล์ vhost ยังเป็นของเก่า
 *
 * เทสต์ชุดนี้เฝ้าสิ่งที่ทำให้คำสั่งนี้ไม่ใช่แค่ "เขียนไฟล์วนลูป"
 */

use Phpcp\Agent\Executor\SandboxExecutor;
use Phpcp\Agent\CapabilityRegistry;
use Phpcp\Agent\Capability\SiteRebuild;
use Phpcp\Security\Permissions;

group('site.rebuild — เปลี่ยนเว็บเซิร์ฟเวอร์แล้วต้องมีคำสั่งให้เขียนไฟล์ใหม่');

test('คำสั่ง sites:rebuild ที่เอกสารอ้างถึงต้องมีอยู่จริง', static function (): void {
    $source = file_get_contents(PHPCP_ROOT . '/src/Cli/Application.php') ?: '';

    assertTrue(
        str_contains($source, "'sites:rebuild' =>"),
        'เอกสารสองที่ใน config.example.php สั่งให้รันคำสั่งนี้ — ต้องมีจริง',
    );
});

test('capability ถูกลงทะเบียนไว้ ไม่งั้น CLI เรียกแล้วได้ denied', static function (): void {
    $registry = new CapabilityRegistry();

    assertTrue($registry->has('site.rebuild'), 'ต้องอยู่ในทะเบียน: ' . implode(', ', $registry->names()));
});

test('ต้องใช้สิทธิ์ระดับเครื่อง ไม่ใช่สิทธิ์แก้เว็บของตัวเอง', static function (): void {
    $permission = (new SiteRebuild())->permission();

    // เขียนทับไฟล์ตั้งค่าของลูกค้าทุกราย + ports.conf ของทั้งเครื่อง
    assertTrue(
        in_array($permission, Permissions::forRole(Permissions::SUPERADMIN), true),
        'superadmin ต้องทำได้',
    );
    assertTrue(
        !in_array($permission, Permissions::forRole(Permissions::WEBADMIN), true),
        'เจ้าของเว็บต้องสั่งเขียนทับ config ของคนอื่นไม่ได้',
    );
    assertTrue(
        !in_array($permission, Permissions::forRole(Permissions::SYSADMIN), true),
        'sysadmin ไม่มี settings.manage — งานนี้ตามมาจากการแก้ไฟล์ตั้งค่าโดยตรง',
    );
});

test('เก็บกวาดเฉพาะไฟล์ของ panel ในไดเรกทอรีของเซิร์ฟเวอร์ที่เลิกใช้แล้ว', static function (): void {
    // ใช้ sandbox ที่สร้างไฟล์เองทั้งหมด — ถ้าอ่าน /etc จริงของเครื่องที่รันเทสต์
    // ผลจะขึ้นกับว่าเครื่องนั้นติดตั้งอะไรไว้ ซึ่งแปลว่าเทสต์ไม่ได้ตรวจโค้ด
    $root = sys_get_temp_dir() . '/phpcp-rebuild-' . getmypid();
    @mkdir($root . '/etc/nginx/conf.d', 0755, true);
    @mkdir($root . '/etc/apache2/sites-enabled', 0755, true);

    file_put_contents($root . '/etc/nginx/conf.d/phpcp-a.test.conf', '# ของ panel');
    file_put_contents($root . '/etc/nginx/conf.d/mine.conf', '# ผู้ดูแลเขียนเอง');
    file_put_contents($root . '/etc/apache2/sites-enabled/phpcp-b.test.conf', '# ของ panel');

    $executor = new SandboxExecutor($root);
    $method = new ReflectionMethod(SiteRebuild::class, 'staleFiles');

    // โหมด apache → กวาดของ nginx ทิ้ง แต่ห้ามแตะไฟล์ของ Apache เองและของผู้ดูแล
    $stale = (array) $method->invoke(new SiteRebuild(), $executor, 'apache');

    assertSame(['/etc/nginx/conf.d/phpcp-a.test.conf'], $stale, 'ต้องกวาดเฉพาะไฟล์ของ panel ฝั่ง nginx');

    // โหมด nginx → กลับกัน
    $stale = (array) $method->invoke(new SiteRebuild(), $executor, 'nginx');
    assertSame(['/etc/apache2/sites-enabled/phpcp-b.test.conf'], $stale, 'ต้องกวาดเฉพาะไฟล์ของ panel ฝั่ง Apache');

    // โหมด nginx-proxy ใช้ทั้งสองไดเรกทอรี จึงต้องไม่กวาดอะไรเลย
    assertSame([], (array) $method->invoke(new SiteRebuild(), $executor, 'nginx-proxy'), 'โหมด proxy ใช้ทั้งสองชั้น');

    array_map(unlink(...), glob($root . '/etc/*/*/*.conf') ?: []);
});
