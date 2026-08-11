<?php

declare(strict_types=1);

namespace Phpcp\Agent\Executor;

/**
 * จำลอง useradd / userdel / id และคำสั่งเปลี่ยนเจ้าของไฟล์ในโหมด sandbox
 *
 * จำเป็นต้องจำลองเพราะสองเหตุผล:
 *   1. การสร้างผู้ใช้ระบบจริงต้องใช้ root และแตะ /etc/passwd ของเครื่อง
 *      ซึ่งขัดกับหลักของโหมด sandbox โดยตรง
 *   2. chown/chgrp บนไฟล์ใน sandbox ต้องใช้ root เช่นกัน — ปล่อยให้รันจริงจะล้มเสมอ
 *      แล้วทำให้ทดสอบขั้นตอนสร้างเว็บไซต์ไม่ได้เลย
 *
 * uid ที่คืนมาเป็นค่าจำลองที่คงที่ต่อชื่อผู้ใช้ เพื่อให้ผลลัพธ์ซ้ำเดิมทุกครั้ง
 */
final class SystemUserSimulator implements Simulator
{
    private const UID_BASE = 3000;

    public function handles(string $binary): bool
    {
        return in_array(basename($binary), ['useradd', 'userdel', 'usermod', 'id', 'chown', 'chgrp'], true);
    }

    public function simulate(array $argv, SandboxState $state, ?string $stdin = null): ExecResult
    {
        return match (basename($argv[0])) {
            'useradd' => $this->useradd($argv, $state),
            'userdel' => $this->userdel($argv, $state),
            'id' => $this->id($argv, $state),
            // เปลี่ยนเจ้าของไฟล์ไม่มีผลใน sandbox แต่ต้องตอบว่าสำเร็จ
            // ไม่อย่างนั้นขั้นตอนสร้างเว็บไซต์จะสะดุดตั้งแต่ต้น
            'chown', 'chgrp', 'usermod' => $this->ok($argv),
            default => $this->fail($argv, 'sandbox: ยังไม่รองรับคำสั่งนี้'),
        };
    }

    private function useradd(array $argv, SandboxState $state): ExecResult
    {
        $name = $this->lastWord($argv);
        if ($name === '') {
            return $this->fail($argv, 'useradd: ไม่ได้ระบุชื่อผู้ใช้', 2);
        }

        $users = $state->read('passwd');

        if (isset($users[$name])) {
            return $this->fail($argv, "useradd: user '{$name}' already exists", 9);
        }

        $uid = self::UID_BASE + count($users) + 1;
        $users[$name] = [
            'uid' => $uid,
            'gid' => $uid,
            'home' => $this->optionValue($argv, '-d') ?: ('/home/' . $name),
            'shell' => $this->optionValue($argv, '-s') ?: '/bin/sh',
            'created_at' => time(),
        ];

        $state->write('passwd', $users);

        return $this->ok($argv);
    }

    private function userdel(array $argv, SandboxState $state): ExecResult
    {
        $name = $this->lastWord($argv);
        $users = $state->read('passwd');

        if (!isset($users[$name])) {
            return $this->fail($argv, "userdel: user '{$name}' does not exist", 6);
        }

        unset($users[$name]);
        $state->write('passwd', $users);

        return $this->ok($argv);
    }

    private function id(array $argv, SandboxState $state): ExecResult
    {
        $name = $this->lastWord($argv);
        $users = $state->read('passwd');

        if (!isset($users[$name])) {
            return $this->fail($argv, "id: '{$name}': no such user", 1);
        }

        $wantsGroup = in_array('-g', $argv, true);
        $value = $wantsGroup ? $users[$name]['gid'] : $users[$name]['uid'];

        return new ExecResult(
            argv: $argv,
            exitCode: 0,
            stdout: $value . "\n",
            stderr: '',
            durationMs: 1,
            simulated: true,
        );
    }

    /** argument ตัวสุดท้ายที่ไม่ใช่ option — คือชื่อผู้ใช้ */
    private function lastWord(array $argv): string
    {
        $words = array_values(array_filter(
            array_slice($argv, 1),
            static fn (string $a): bool => !str_starts_with($a, '-'),
        ));

        return $words === [] ? '' : (string) end($words);
    }

    private function optionValue(array $argv, string $option): string
    {
        foreach ($argv as $index => $arg) {
            if ($arg === $option) {
                return (string) ($argv[$index + 1] ?? '');
            }
        }

        return '';
    }

    private function ok(array $argv): ExecResult
    {
        return new ExecResult($argv, 0, '', '', 2, simulated: true);
    }

    private function fail(array $argv, string $message, int $code = 1): ExecResult
    {
        return new ExecResult($argv, $code, '', $message . "\n", 2, simulated: true);
    }
}
