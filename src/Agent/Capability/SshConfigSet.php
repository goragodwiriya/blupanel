<?php

declare(strict_types=1);

namespace Phpcp\Agent\Capability;

use Phpcp\Agent\Capability;
use Phpcp\Agent\Context;
use Phpcp\Agent\ExecutionFailed;
use Phpcp\Agent\Executor\Executor;
use Phpcp\Agent\ValidationError;
use Phpcp\Driver\RollbackGuard;
use Phpcp\Driver\SshManager;

/**
 * แก้ค่าตั้ง SSH พร้อมกลไกคืนค่าอัตโนมัติ — ARCHITECTURE §5.4
 *
 * นี่คือการเปลี่ยนแปลงที่อันตรายที่สุดที่ทำผ่านหน้าเว็บได้:
 * ตั้งพอร์ตผิด ปิด password auth ทั้งที่ยังไม่ได้ใส่กุญแจ หรือ firewall ไม่เปิดพอร์ตใหม่
 * ล้วนทำให้เข้าเครื่องไม่ได้อีกเลยถ้าไม่มีทางเข้าทางอื่น
 *
 * จึงไม่ยอมให้ "เปลี่ยนแล้วจบ" — ต้องกดยืนยันว่ายังเชื่อมต่อได้ภายในเวลาที่กำหนด
 * ไม่กด = ระบบคืนค่าเดิมให้เอง เหมือนที่ `netplan try` ทำกับการตั้งค่าเครือข่าย
 */
final class SshConfigSet implements Capability
{
    public static function name(): string
    {
        return 'ssh.config_set';
    }

    public function permission(): string
    {
        return 'ssh.manage';
    }

    public function isMutating(): bool
    {
        return true;
    }

    public function summary(): string
    {
        return 'แก้ค่าตั้ง SSH (มีการคืนค่าอัตโนมัติถ้าไม่ยืนยันภายในเวลา)';
    }

    public function validate(array $args): array
    {
        $changes = [];

        foreach (SshManager::keys() as $key) {
            if (!isset($args[$key]) || $args[$key] === '') {
                continue;
            }

            $changes[$key] = SshManager::assertValue($key, (string) $args[$key]);
        }

        if ($changes === []) {
            throw new ValidationError('ไม่มีค่าที่จะเปลี่ยน');
        }

        $window = isset($args['window'])
            ? max(30, min(900, (int) $args['window']))
            : RollbackGuard::DEFAULT_WINDOW;

        return ['changes' => $changes, 'window' => $window];
    }

    public function run(array $args, Executor $executor, Context $context): array
    {
        $manager = new SshManager();
        $guard = new RollbackGuard($context->db);

        if (!$manager->isInstalled($executor)) {
            throw new ValidationError('ไม่พบไฟล์ตั้งค่า SSH บนเครื่องนี้');
        }

        if ($guard->pending() !== null) {
            throw new ValidationError(
                'มีการเปลี่ยนแปลงที่รอการยืนยันอยู่แล้ว — ยืนยันหรือรอให้คืนค่าก่อนจึงจะแก้ใหม่ได้',
            );
        }

        $before = $manager->read($executor);
        $applied = $manager->apply($executor, $args['changes']);

        // ตรวจไฟล์ด้วย sshd เอง ถ้าไม่ผ่านให้คืนทันที ไม่ต้องรอหมดเวลา
        [$ok, $output] = $manager->testConfig($executor);

        if (!$ok) {
            $executor->writeFile($executor->path(SshManager::CONFIG), $applied['original'], 0600);

            throw new ExecutionFailed("ค่าตั้ง SSH ที่สร้างขึ้นไม่ผ่านการตรวจสอบ จึงคืนค่าเดิมแล้ว\n\n" . trim($output));
        }

        $rollbackId = $guard->arm(
            action: 'ssh.config_set',
            description: 'แก้ค่าตั้ง SSH: ' . implode(', ', array_map(
                static fn (string $k, string $v): string => SshManager::label($k) . ' = ' . $v,
                array_keys($args['changes']),
                array_values($args['changes']),
            )),
            files: [SshManager::CONFIG => $applied['original']],
            reloadUnits: ['ssh'],
            window: $args['window'],
            actorId: $context->actor->userId,
        );

        // reload หลังบันทึกรายการรอยืนยันแล้วเท่านั้น — ถ้าการเชื่อมต่อขาดตรงนี้
        // รายการก็ยังอยู่ในฐานข้อมูลและจะถูกคืนค่าเมื่อหมดเวลา
        $executor->exec([$executor->path('/usr/bin/systemctl'), 'reload-or-restart', 'ssh'], timeout: 30);

        return [
            'rollback_id' => $rollbackId,
            'window' => $args['window'],
            'changes' => $args['changes'],
            'before' => array_map(static fn (array $v): string => $v['value'], $before),
            'message' => sprintf(
                'เปลี่ยนค่าตั้ง SSH แล้ว — ต้องกดยืนยันว่ายังเชื่อมต่อได้ภายใน %d วินาที '
                . 'ไม่อย่างนั้นระบบจะคืนค่าเดิมให้อัตโนมัติ',
                $args['window'],
            ),
        ];
    }
}
