<?php

declare(strict_types=1);

namespace Phpcp\Driver;

use Phpcp\Agent\Executor\Executor;
use Phpcp\Agent\ValidationError;

/**
 * อ่านและแก้ค่าตั้ง SSH — PROMPT.md
 *
 * แก้ได้เฉพาะ 5 คีย์ที่ระบุไว้ และค่าที่ใส่ได้เป็น enum หรือหมายเลขพอร์ตเท่านั้น
 * ไม่เปิดให้แก้ไฟล์ทั้งไฟล์ เพราะ sshd_config ที่ผิดทำให้เข้าเครื่องไม่ได้อีกเลย
 *
 * ทุกการแก้ไขต้องผ่าน RollbackGuard — นี่คือค่าที่เปลี่ยนแล้วตัดการเชื่อมต่อ
 * ของคนที่กำลังแก้ได้ทันที (ARCHITECTURE §5.4)
 */
final class SshManager
{
    public const CONFIG = '/etc/ssh/sshd_config';

    /** คีย์ที่แก้ได้ พร้อมค่าที่ยอมรับ */
    private const EDITABLE = [
        'Port' => 'port',
        'PermitRootLogin' => ['yes', 'no', 'prohibit-password', 'forced-commands-only'],
        'PasswordAuthentication' => ['yes', 'no'],
        'PubkeyAuthentication' => ['yes', 'no'],
        'PermitEmptyPasswords' => ['yes', 'no'],
    ];

    /** ค่าเริ่มต้นของ OpenSSH เมื่อไม่ได้ระบุในไฟล์ */
    private const DEFAULTS = [
        'Port' => '22',
        'PermitRootLogin' => 'prohibit-password',
        'PasswordAuthentication' => 'yes',
        'PubkeyAuthentication' => 'yes',
        'PermitEmptyPasswords' => 'no',
    ];

    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys(self::EDITABLE);
    }

    public static function label(string $key): string
    {
        return match ($key) {
            'Port' => 'พอร์ต SSH',
            'PermitRootLogin' => 'อนุญาตให้ root เข้าสู่ระบบ',
            'PasswordAuthentication' => 'เข้าสู่ระบบด้วยรหัสผ่าน',
            'PubkeyAuthentication' => 'เข้าสู่ระบบด้วยกุญแจ',
            'PermitEmptyPasswords' => 'อนุญาตรหัสผ่านว่าง',
            default => $key,
        };
    }

    /** @return list<string>|null ค่าที่เลือกได้ — null = เป็นหมายเลขพอร์ต */
    public static function choices(string $key): ?array
    {
        $spec = self::EDITABLE[$key] ?? null;

        return is_array($spec) ? $spec : null;
    }

    public static function assertValue(string $key, string $value): string
    {
        if (!isset(self::EDITABLE[$key])) {
            throw new ValidationError("แก้ค่า {$key} ผ่านระบบนี้ไม่ได้");
        }

        $spec = self::EDITABLE[$key];

        if ($spec === 'port') {
            if (preg_match('/^\d{1,5}$/', $value) !== 1 || (int) $value < 1 || (int) $value > 65535) {
                throw new ValidationError('หมายเลขพอร์ตต้องอยู่ระหว่าง 1 ถึง 65535');
            }

            return $value;
        }

        if (!in_array($value, $spec, true)) {
            throw new ValidationError("ค่าของ {$key} ต้องเป็นหนึ่งใน: " . implode(', ', $spec));
        }

        return $value;
    }

    public function isInstalled(Executor $executor): bool
    {
        return $executor->exists($executor->path(self::CONFIG));
    }

    /**
     * อ่านค่าปัจจุบัน — คีย์ที่ไม่มีในไฟล์ใช้ค่าเริ่มต้นของ OpenSSH
     *
     * @return array<string,array{value:string,explicit:bool}>
     */
    public function read(Executor $executor): array
    {
        $values = [];

        foreach (self::DEFAULTS as $key => $default) {
            $values[$key] = ['value' => $default, 'explicit' => false];
        }

        if (!$this->isInstalled($executor)) {
            return $values;
        }

        foreach (preg_split('/\R/', $executor->readFile($executor->path(self::CONFIG))) ?: [] as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            foreach (self::DEFAULTS as $key => $ignored) {
                // เทียบแบบไม่สนตัวพิมพ์เพราะ sshd ก็ไม่สน
                if (preg_match('/^' . preg_quote($key, '/') . '\s+(\S+)/i', $line, $m) === 1) {
                    // บรรทัดแรกที่พบชนะ — sshd ใช้ค่าแรกเช่นกัน
                    if (!$values[$key]['explicit']) {
                        $values[$key] = ['value' => $m[1], 'explicit' => true];
                    }
                }
            }
        }

        return $values;
    }

    /**
     * เขียนค่าใหม่ลงไฟล์ คืนเนื้อหาเดิมไว้ให้ผู้เรียกเก็บสำหรับการคืนค่า
     *
     * @param array<string,string> $changes
     * @return array{original:string,updated:string}
     */
    public function apply(Executor $executor, array $changes): array
    {
        $path = $executor->path(self::CONFIG);

        if (!$executor->exists($path)) {
            throw new ValidationError('ไม่พบไฟล์ตั้งค่า SSH บนเครื่องนี้');
        }

        $original = $executor->readFile($path);
        $lines = preg_split('/\R/', $original) ?: [];

        foreach ($changes as $key => $value) {
            self::assertValue($key, $value);
            $lines = self::replaceDirective($lines, $key, $value);
        }

        $updated = implode("\n", $lines);
        $executor->writeFile($path, $updated, 0600);

        return ['original' => $original, 'updated' => $updated];
    }

    /**
     * แทนที่ directive — คอมเมนต์บรรทัดเดิมไว้แทนการลบ เพื่อให้ตามรอยได้ว่าเคยตั้งอะไรไว้
     *
     * @param list<string> $lines
     * @return list<string>
     */
    private static function replaceDirective(array $lines, string $key, string $value): array
    {
        $out = [];
        $written = false;

        foreach ($lines as $line) {
            if (preg_match('/^\s*' . preg_quote($key, '/') . '\s+\S+/i', $line) === 1) {
                if (!$written) {
                    $out[] = '# ' . trim($line) . '   # แก้โดย PHP Server Control Panel ' . date('Y-m-d H:i');
                    $out[] = $key . ' ' . $value;
                    $written = true;
                } else {
                    // บรรทัดซ้ำที่เหลือถูกคอมเมนต์ทิ้ง ไม่อย่างนั้นค่าจะขัดกันเอง
                    $out[] = '# ' . trim($line) . '   # ซ้ำ ปิดโดย Control Panel';
                }

                continue;
            }

            $out[] = $line;
        }

        if (!$written) {
            $out[] = '';
            $out[] = '# เพิ่มโดย PHP Server Control Panel ' . date('Y-m-d H:i');
            $out[] = $key . ' ' . $value;
        }

        return $out;
    }

    /**
     * ตรวจไฟล์ด้วย sshd เอง ก่อนสั่ง reload
     *
     * @return array{0:bool,1:string}
     */
    public function testConfig(Executor $executor): array
    {
        $binary = '/usr/sbin/sshd';

        if (!$executor->exists($binary)) {
            return [true, 'ข้ามการตรวจ: ไม่พบ sshd บนเครื่องนี้'];
        }

        $result = $executor->exec([$binary, '-t', '-f', $executor->path(self::CONFIG)], timeout: 20);
        $output = trim($result->stderr) !== '' ? $result->stderr : $result->stdout;

        return [$result->ok(), $output];
    }
}
