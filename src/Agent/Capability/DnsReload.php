<?php

declare(strict_types=1);

namespace Phpcp\Agent\Capability;

use Phpcp\Agent\Capability;
use Phpcp\Agent\Context;
use Phpcp\Agent\Executor\Executor;
use Phpcp\Driver\Dns\BindZoneManager;

/**
 * เขียน zone ของทุกโดเมนใหม่ทั้งหมดแล้วสั่ง BIND9 โหลดครั้งเดียว — PLAN-V2 เฟส E3
 *
 * ใช้เมื่อ: เพิ่งเปิด `dns.enabled` เป็นครั้งแรก (มีเรกคอร์ดที่เพิ่มไว้ก่อนหน้าค้างอยู่ที่ยัง
 * ไม่เคยถูกส่งออกเลย) หรือมีใครแก้ไฟล์ของ BIND9 ตรง ๆ แล้วต้องการให้ panel เขียนทับคืนสภาพ
 * ที่ควรจะเป็นทั้งหมด
 *
 * **สิทธิ์ระดับเครื่อง ไม่ใช่ระดับโดเมน** — ต่างจาก `dns.zone_write` (ใช้ `domain.manage`
 * ซึ่งผู้ดูแลเว็บไซต์มีสำหรับโดเมนของตัวเอง) ตัวนี้เขียนทับ `named.conf.local` ทั้งไฟล์และ
 * กระทบ zone ของลูกค้าทุกรายพร้อมกันในคำสั่งเดียว จึงต้องเป็นสิทธิ์ของทั้งเครื่องเท่านั้น
 */
final class DnsReload implements Capability
{
    public static function name(): string
    {
        return 'dns.reload';
    }

    public function permission(): string
    {
        return 'dns.manage';
    }

    public function isMutating(): bool
    {
        return true;
    }

    public function summary(): string
    {
        return 'ซิงก์ zone ของทุกโดเมนกับ BIND9 ใหม่ทั้งหมด';
    }

    public function validate(array $args): array
    {
        return [];
    }

    public function run(array $args, Executor $executor, Context $context): array
    {
        $manager = new BindZoneManager($executor, $context->config, $context->db);

        return $manager->reloadAll();
    }
}
