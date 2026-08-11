<?php

declare(strict_types=1);

namespace Phpcp\Agent\Capability;

use Phpcp\Agent\Capability;
use Phpcp\Agent\Context;
use Phpcp\Agent\Executor\Executor;

/**
 * ปิด firewall
 *
 * ไม่ต้องยืนยันภายในเวลาเหมือนคำสั่งอื่นในหน้านี้ เพราะการปิด firewall
 * ไม่มีทางทำให้เข้าเครื่องไม่ได้ — มันเปิดกว้างขึ้น ไม่ใช่แคบลง
 *
 * แต่มันคือการลดความปลอดภัยของเครื่องทั้งเครื่อง หน้าจอจึงถามยืนยันแบบเดียวกับ
 * การลบข้อมูล และ audit log จะบันทึกไว้ว่าใครเป็นคนสั่งเมื่อไร
 */
final class FirewallDisable extends FirewallCapability implements Capability
{
    public static function name(): string
    {
        return 'firewall.disable';
    }

    public function permission(): string
    {
        return 'firewall.manage';
    }

    public function isMutating(): bool
    {
        return true;
    }

    public function summary(): string
    {
        return 'ปิด firewall';
    }

    public function validate(array $args): array
    {
        return [];
    }

    public function run(array $args, Executor $executor, Context $context): array
    {
        $this->assertInstalled($executor);
        $this->assertNoPending($context);

        $driver = $this->driver();

        if (!$driver->status($executor)['active']) {
            return ['message' => 'firewall ปิดอยู่แล้ว'];
        }

        $driver->disable($executor);

        return ['message' => 'ปิด firewall แล้ว — ตอนนี้เครื่องรับการเชื่อมต่อทุกพอร์ตที่มีบริการเปิดฟังอยู่'];
    }
}
