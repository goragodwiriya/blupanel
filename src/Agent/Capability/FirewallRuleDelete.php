<?php

declare(strict_types=1);

namespace Phpcp\Agent\Capability;

use Phpcp\Agent\Capability;
use Phpcp\Agent\Context;
use Phpcp\Agent\Executor\Executor;
use Phpcp\Agent\ValidationError;

/**
 * ลบกฎ firewall — เป็นการเปลี่ยนแปลงที่ต้องยืนยันเสมอ
 *
 * ลบกฎ allow ทิ้ง = ปิดพอร์ตนั้น ซึ่งอาจเป็นพอร์ตที่ตัวเองกำลังใช้อยู่
 * จึงเข้ากลไกยืนยันภายในเวลาทุกครั้ง ไม่มีข้อยกเว้น
 *
 * ผู้ใช้เลือกกฎจากหมายเลขที่เห็นบนหน้าจอ แต่หมายเลขของ ufw เลื่อนได้ตลอด
 * จึงต้องส่งข้อความของกฎที่เห็นมาด้วยเพื่อยืนยันว่ากำลังลบกฎเดียวกับที่ตั้งใจจริง —
 * เป็นการกันกรณีที่มีคนแก้กฎจากอีกหน้าต่างหนึ่งระหว่างที่หน้านี้เปิดค้างอยู่
 */
final class FirewallRuleDelete extends FirewallCapability implements Capability
{
    public static function name(): string
    {
        return 'firewall.rule_delete';
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
        return 'ลบกฎ firewall (มีการคืนค่าอัตโนมัติถ้าไม่ยืนยันภายในเวลา)';
    }

    public function validate(array $args): array
    {
        $number = (int) ($args['number'] ?? 0);

        if ($number < 1) {
            throw new ValidationError('ไม่ได้ระบุกฎที่จะลบ');
        }

        $expect = trim((string) ($args['expect'] ?? ''));

        if ($expect === '') {
            throw new ValidationError('ไม่ได้ระบุกฎที่คาดหวัง — โหลดหน้าใหม่แล้วลองอีกครั้ง');
        }

        return ['number' => $number, 'expect' => $expect, 'window' => $this->window($args)];
    }

    public function run(array $args, Executor $executor, Context $context): array
    {
        $this->assertInstalled($executor);
        $this->assertNoPending($context);

        $driver = $this->driver();
        $rules = $driver->status($executor)['rules'];
        $target = null;

        foreach ($rules as $rule) {
            if ($rule['number'] === $args['number']) {
                $target = $rule;
                break;
            }
        }

        if ($target === null) {
            throw new ValidationError('ไม่พบกฎหมายเลข ' . $args['number'] . ' แล้ว — โหลดหน้าใหม่เพื่อดูรายการล่าสุด');
        }

        $signature = $this->signature($target);

        if ($signature !== $args['expect']) {
            throw new ValidationError(
                "กฎหมายเลข {$args['number']} เปลี่ยนไปแล้ว (ตอนนี้คือ \"{$signature}\") — "
                . 'มีคนแก้กฎระหว่างที่หน้านี้เปิดค้างอยู่ โหลดหน้าใหม่ก่อนลบ',
            );
        }

        if (!$target['manageable']) {
            throw new ValidationError('กฎข้อนี้ไม่ได้อยู่ในรูปแบบที่ระบบสร้างคืนให้ได้ จึงไม่เปิดให้ลบผ่านหน้าเว็บ');
        }

        $this->assertNotLifeline($target['port'], $context, $executor, 'ลบกฎที่เปิด');

        $action = strtolower($target['action']);

        $driver->removeRule($executor, $action, $target['port'], $target['protocol'], $target['source_spec']);

        $rollbackId = $this->guard($context)->arm(
            action: 'firewall.rule_delete',
            description: 'ลบกฎ firewall: ' . $signature,
            files: [],
            reloadUnits: [],
            window: $args['window'],
            actorId: $context->actor->userId,
            undo: [[
                'type' => 'ufw.rule_add',
                'action' => $action,
                'port' => $target['port'],
                'protocol' => $target['protocol'],
                'source' => $target['source_spec'],
            ]],
        );

        return [
            'rollback_id' => $rollbackId,
            'rule' => $signature,
            'window' => $args['window'],
            'message' => sprintf(
                'ลบกฎแล้ว: %s — ต้องกดยืนยันว่ายังใช้งานได้ภายใน %d วินาที '
                . 'ไม่อย่างนั้นระบบจะเพิ่มกฎนี้กลับให้อัตโนมัติ',
                $signature,
                $args['window'],
            ),
        ];
    }

    /** ข้อความสั้น ๆ ที่ระบุกฎได้ไม่ซ้ำ ใช้เทียบว่าหน้าจอกับเซิร์ฟเวอร์ยังตรงกัน */
    public static function signature(array $rule): string
    {
        return sprintf('%s %s %s', $rule['action'], $rule['target'], $rule['source']);
    }
}
