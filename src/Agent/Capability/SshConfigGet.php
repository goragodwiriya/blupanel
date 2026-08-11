<?php

declare(strict_types=1);

namespace Phpcp\Agent\Capability;

use Phpcp\Agent\Capability;
use Phpcp\Agent\Context;
use Phpcp\Agent\Executor\Executor;
use Phpcp\Driver\SshManager;

/** อ่านค่าตั้ง SSH ปัจจุบัน — อ่านอย่างเดียว */
final class SshConfigGet implements Capability
{
    public static function name(): string
    {
        return 'ssh.config_get';
    }

    public function permission(): string
    {
        return 'ssh.view';
    }

    public function isMutating(): bool
    {
        return false;
    }

    public function summary(): string
    {
        return 'อ่านค่าตั้ง SSH';
    }

    public function validate(array $args): array
    {
        return [];
    }

    public function run(array $args, Executor $executor, Context $context): array
    {
        $manager = new SshManager();
        $values = $manager->read($executor);

        // ประเมินความเสี่ยงจากค่าที่อ่านได้ เพื่อให้หน้าจอเตือนได้ตรงจุด
        $warnings = [];

        if (in_array($values['PermitRootLogin']['value'], ['yes'], true)) {
            $warnings[] = 'อนุญาตให้ root เข้าสู่ระบบด้วยรหัสผ่าน — ควรปิด';
        }

        if ($values['PasswordAuthentication']['value'] === 'yes') {
            $warnings[] = 'เปิดให้เข้าสู่ระบบด้วยรหัสผ่าน — ควรใช้กุญแจแทนเพื่อกันการเดารหัส';
        }

        if ($values['PermitEmptyPasswords']['value'] === 'yes') {
            $warnings[] = 'อนุญาตรหัสผ่านว่าง — อันตรายมาก ควรปิดทันที';
        }

        if ($values['PubkeyAuthentication']['value'] === 'no') {
            $warnings[] = 'ปิดการเข้าสู่ระบบด้วยกุญแจ — ทำให้เหลือแค่รหัสผ่านเป็นทางเข้าเดียว';
        }

        return [
            'installed' => $manager->isInstalled($executor),
            'config' => SshManager::CONFIG,
            'values' => $values,
            'warnings' => $warnings,
        ];
    }
}
