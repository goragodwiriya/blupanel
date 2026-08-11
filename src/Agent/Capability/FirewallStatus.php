<?php

declare(strict_types=1);

namespace Phpcp\Agent\Capability;

use Phpcp\Agent\Capability;
use Phpcp\Agent\Context;
use Phpcp\Agent\Executor\Executor;
use Phpcp\Driver\Firewall\UfwDriver;

/**
 * สถานะและกฎ firewall — อ่านอย่างเดียว
 *
 * ทำเครื่องหมายกฎที่อนุญาตพอร์ตของ panel ไว้ด้วย เพราะกฎนั้นลบไม่ได้
 * และหน้าจอต้องบอกเหตุผลให้ชัด ไม่ใช่แค่ปิดปุ่มเฉย ๆ
 */
final class FirewallStatus implements Capability
{
    public static function name(): string
    {
        return 'firewall.status';
    }

    public function permission(): string
    {
        return 'firewall.view';
    }

    public function isMutating(): bool
    {
        return false;
    }

    public function summary(): string
    {
        return 'อ่านสถานะและกฎของ firewall';
    }

    public function validate(array $args): array
    {
        return [];
    }

    public function run(array $args, Executor $executor, Context $context): array
    {
        $driver = new UfwDriver();
        $status = $driver->status($executor);
        $panelPort = (string) $context->config->int('panel.port', 8443);

        foreach ($status['rules'] as $index => $rule) {
            // กฎที่เปิดพอร์ตของ panel เอง — ลบแล้วจะเข้าหน้าเว็บไม่ได้อีก
            $status['rules'][$index]['is_panel_port'] = $rule['port'] === $panelPort;
        }

        return [
            'installed' => $status['installed'],
            'active' => $status['active'],
            'readable' => $status['readable'],
            'note' => $status['note'],
            'rules' => $status['rules'],
            'total' => count($status['rules']),
            'panel_port' => $panelPort,
        ];
    }
}
