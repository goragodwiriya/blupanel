<?php

declare(strict_types=1);

namespace Phpcp\Agent\Capability;

use Phpcp\Agent\Capability;
use Phpcp\Agent\Context;
use Phpcp\Agent\Executor\Executor;

/**
 * เปิดใช้งาน firewall
 *
 * นี่คือคำสั่งที่ทำให้ผู้ดูแลหลุดจากเครื่องบ่อยที่สุดในบรรดาทั้งหมด: ufw ตั้งค่าเริ่มต้น
 * ให้ปฏิเสธขาเข้าทั้งหมด พอสั่ง enable ทั้งที่ยังไม่มีกฎเปิดพอร์ต SSH
 * การเชื่อมต่อก็ขาดทันทีและกลับเข้าไปแก้ไม่ได้อีกถ้าไม่มีคอนโซลจริง
 *
 * ระบบจึงเปิดพอร์ตของ panel และพอร์ต SSH ให้ก่อนเสมอ แล้วค่อย enable
 * และยังบังคับให้ยืนยันภายในเวลาอีกชั้น เผื่อกรณีที่กฎถูกต้องแต่มีอย่างอื่นผิดพลาด
 */
final class FirewallEnable extends FirewallCapability implements Capability
{
    public static function name(): string
    {
        return 'firewall.enable';
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
        return 'เปิดใช้งาน firewall (มีการคืนค่าอัตโนมัติถ้าไม่ยืนยันภายในเวลา)';
    }

    public function validate(array $args): array
    {
        return ['window' => $this->window($args)];
    }

    public function run(array $args, Executor $executor, Context $context): array
    {
        $this->assertInstalled($executor);
        $this->assertNoPending($context);

        $driver = $this->driver();
        $status = $driver->status($executor);

        if ($status['active']) {
            return ['rollback_id' => 0, 'opened' => [], 'message' => 'firewall เปิดใช้งานอยู่แล้ว'];
        }

        $opened = [];

        // เปิดเส้นชีวิตก่อน enable เสมอ — ลำดับนี้สำคัญ ถ้าสลับกันคือหลุดทันที
        foreach ([$this->panelPort($context), $this->sshPort($executor)] as $port) {
            if ($this->alreadyAllowed($status['rules'], $port)) {
                continue;
            }

            $driver->rule($executor, 'allow', $port, 'tcp', '', 'phpcp lifeline');
            $opened[] = $port;
        }

        $driver->enable($executor);

        $rollbackId = $this->guard($context)->arm(
            action: 'firewall.enable',
            description: 'เปิดใช้งาน firewall',
            files: [],
            reloadUnits: [],
            window: $args['window'],
            actorId: $context->actor->userId,
            // ย้อนกลับแค่การเปิดใช้งาน ไม่ลบกฎเส้นชีวิตที่เพิ่งเปิดไว้ —
            // กฎพวกนั้นไม่ได้ทำให้เสียหายอะไร และมีประโยชน์ตอนเปิด firewall ครั้งถัดไป
            undo: [['type' => 'ufw.disable']],
        );

        $note = $opened === []
            ? ''
            : ' (เปิดพอร์ต ' . implode(', ', $opened) . ' ไว้ให้ก่อนแล้วเพื่อไม่ให้เข้าเครื่องไม่ได้)';

        return [
            'rollback_id' => $rollbackId,
            'opened' => $opened,
            'window' => $args['window'],
            'message' => sprintf(
                'เปิดใช้งาน firewall แล้ว%s — ต้องกดยืนยันว่ายังใช้งานได้ภายใน %d วินาที '
                . 'ไม่อย่างนั้นระบบจะปิด firewall กลับให้อัตโนมัติ',
                $note,
                $args['window'],
            ),
        ];
    }

    /** @param list<array<string,mixed>> $rules */
    private function alreadyAllowed(array $rules, string $port): bool
    {
        foreach ($rules as $rule) {
            if ($rule['action'] === 'ALLOW' && $rule['direction'] === 'IN' && $this->covers((string) $rule['port'], $port)) {
                return true;
            }
        }

        return false;
    }
}
