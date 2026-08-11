<?php

declare(strict_types=1);

namespace Phpcp\Agent\Capability;

use Phpcp\Agent\Capability;
use Phpcp\Agent\Context;
use Phpcp\Agent\Executor\Executor;
use Phpcp\Agent\ValidationError;
use Phpcp\Driver\Firewall\UfwDriver;

/**
 * เพิ่มกฎ firewall หนึ่งข้อ
 *
 * กฎ allow เพิ่มแล้วไม่มีทางทำให้เข้าเครื่องไม่ได้ จึงมีผลทันทีไม่ต้องยืนยัน
 * ส่วนกฎ deny ปิดทางเข้าได้จริง จึงเข้ากลไกยืนยันภายในเวลาเหมือนการแก้ SSH
 *
 * ความต่างนี้ตั้งใจให้เห็นชัดบนหน้าจอ — ไม่บังคับนับถอยหลังกับทุกอย่าง
 * เพราะถ้าต้องยืนยันแม้แต่ตอนเปิดพอร์ต 80 ผู้ใช้จะเริ่มกดยืนยันโดยไม่อ่าน
 */
final class FirewallRuleAdd extends FirewallCapability implements Capability
{
    public static function name(): string
    {
        return 'firewall.rule_add';
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
        return 'เพิ่มกฎ firewall';
    }

    public function validate(array $args): array
    {
        $action = UfwDriver::assertAction((string) ($args['action'] ?? 'allow'));
        $protocol = (string) ($args['protocol'] ?? 'tcp');

        // แบบฟอร์มให้เลือกได้แค่สองค่านี้ — ไม่รับ 'any' ที่ driver ยอมรับสำหรับกฎเดิม
        if (!in_array($protocol, ['tcp', 'udp'], true)) {
            throw new ValidationError('โปรโตคอลต้องเป็น tcp หรือ udp');
        }

        return [
            'action' => $action,
            'port' => UfwDriver::assertPort(trim((string) ($args['port'] ?? ''))),
            'protocol' => $protocol,
            'source' => UfwDriver::assertSource(trim((string) ($args['source'] ?? ''))),
            'comment' => trim((string) ($args['comment'] ?? '')),
            'window' => $this->window($args),
        ];
    }

    public function run(array $args, Executor $executor, Context $context): array
    {
        $this->assertInstalled($executor);

        $driver = $this->driver();
        $guarded = $args['action'] === 'deny';

        if ($guarded) {
            $this->assertNoPending($context);
            $this->assertNotLifeline($args['port'], $context, $executor, 'ปิดกั้น');
        }

        $driver->rule($executor, $args['action'], $args['port'], $args['protocol'], $args['source'], $args['comment']);

        $where = $args['source'] === '' ? 'ทุกที่' : $args['source'];
        $label = sprintf(
            '%s %s/%s จาก %s',
            $args['action'] === 'allow' ? 'อนุญาต' : 'ปิดกั้น',
            $args['port'],
            $args['protocol'],
            $where,
        );

        if (!$guarded) {
            return [
                'rollback_id' => 0,
                'rule' => $label,
                'message' => 'เพิ่มกฎแล้ว: ' . $label,
            ];
        }

        $rollbackId = $this->guard($context)->arm(
            action: 'firewall.rule_add',
            description: 'เพิ่มกฎ firewall: ' . $label,
            files: [],
            reloadUnits: [],
            window: $args['window'],
            actorId: $context->actor->userId,
            undo: [[
                'type' => 'ufw.rule_remove',
                'action' => $args['action'],
                'port' => $args['port'],
                'protocol' => $args['protocol'],
                'source' => $args['source'],
            ]],
        );

        return [
            'rollback_id' => $rollbackId,
            'rule' => $label,
            'window' => $args['window'],
            'message' => sprintf(
                'เพิ่มกฎปิดกั้นแล้ว: %s — ต้องกดยืนยันว่ายังใช้งานได้ภายใน %d วินาที '
                . 'ไม่อย่างนั้นระบบจะลบกฎนี้ออกให้อัตโนมัติ',
                $label,
                $args['window'],
            ),
        ];
    }
}
