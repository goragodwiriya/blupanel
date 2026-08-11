<?php

declare(strict_types=1);

namespace Phpcp\Agent\Executor;

/**
 * จำลอง systemctl ในโหมด sandbox
 *
 * ตอบด้วยรูปแบบเดียวกับ systemctl จริงทุกประการ — capability จึง parse ด้วยโค้ดชุดเดียว
 * ไม่ต้องรู้เลยว่ากำลังอยู่โหมดไหน (ARCHITECTURE §6.2)
 */
final class SystemctlSimulator implements Simulator
{
    public function handles(string $binary): bool
    {
        return basename($binary) === 'systemctl';
    }

    public function simulate(array $argv, SandboxState $state, ?string $stdin = null): ExecResult
    {
        $args = array_slice($argv, 1);
        $flags = array_values(array_filter($args, static fn (string $a): bool => str_starts_with($a, '-')));
        $words = array_values(array_filter($args, static fn (string $a): bool => !str_starts_with($a, '-')));

        $verb = $words[0] ?? '';
        $unit = self::normalizeUnit($words[1] ?? '');
        $services = $state->services();

        return match ($verb) {
            'show' => $this->show($argv, $unit, $services, $flags),
            'is-active' => $this->simpleValue($argv, $services[$unit]['active'] ?? 'inactive', ($services[$unit]['active'] ?? '') === 'active'),
            'is-enabled' => $this->simpleValue($argv, $services[$unit]['enabled'] ?? 'disabled', ($services[$unit]['enabled'] ?? '') === 'enabled'),
            'start', 'stop', 'restart', 'reload' => $this->transition($argv, $verb, $unit, $services, $state),
            default => new ExecResult(
                argv: $argv,
                exitCode: 1,
                stdout: '',
                stderr: "sandbox: ยังไม่รองรับคำสั่ง systemctl {$verb}",
                durationMs: 1,
                simulated: true,
            ),
        };
    }

    /**
     * @param array<string,array<string,mixed>> $services
     * @param list<string> $flags
     */
    private function show(array $argv, string $unit, array $services, array $flags): ExecResult
    {
        $service = $services[$unit] ?? null;

        // systemctl show ของหน่วยที่ไม่มีอยู่จริงยังคืน exit 0 พร้อมค่าว่าง เลียนแบบให้เหมือนกัน
        $active = $service['active'] ?? 'inactive';
        $since = $service['since'] ?? null;

        $values = [
            'Description' => (string) ($service['description'] ?? $unit),
            'LoadState' => $service === null ? 'not-found' : 'loaded',
            'ActiveState' => $service === null ? 'inactive' : (string) $active,
            'SubState' => $service === null ? 'dead' : (string) ($service['sub'] ?? 'dead'),
            'UnitFileState' => (string) ($service['enabled'] ?? 'disabled'),
            'MainPID' => (string) ($service['pid'] ?? 0),
            'MemoryCurrent' => (string) ($service['memory'] ?? 0),
            'ActiveEnterTimestamp' => $since === null ? '' : date('D Y-m-d H:i:s T', (int) $since),
            'Version' => (string) ($service['version'] ?? ''),
        ];

        // เคารพ --property=... ที่ผู้เรียกระบุ ถ้าไม่ระบุคืนทั้งหมด
        $requested = [];
        foreach ($flags as $flag) {
            if (str_starts_with($flag, '--property=')) {
                foreach (explode(',', substr($flag, 11)) as $name) {
                    $requested[] = trim($name);
                }
            }
        }

        $lines = [];
        foreach ($values as $key => $value) {
            if ($requested === [] || in_array($key, $requested, true)) {
                $lines[] = "{$key}={$value}";
            }
        }

        return new ExecResult(
            argv: $argv,
            exitCode: 0,
            stdout: implode("\n", $lines) . "\n",
            stderr: '',
            durationMs: 2,
            simulated: true,
        );
    }

    private function simpleValue(array $argv, string $value, bool $ok): ExecResult
    {
        return new ExecResult(
            argv: $argv,
            exitCode: $ok ? 0 : 3,
            stdout: $value . "\n",
            stderr: '',
            durationMs: 1,
            simulated: true,
        );
    }

    /** @param array<string,array<string,mixed>> $services */
    private function transition(
        array $argv,
        string $verb,
        string $unit,
        array $services,
        SandboxState $state,
    ): ExecResult {
        if (!isset($services[$unit])) {
            return new ExecResult(
                argv: $argv,
                exitCode: 5,
                stdout: '',
                stderr: "Failed to {$verb} {$unit}: Unit {$unit} not found.\n",
                durationMs: 3,
                simulated: true,
            );
        }

        $service = $services[$unit];

        if ($verb === 'stop') {
            $service['active'] = 'inactive';
            $service['sub'] = 'dead';
            $service['since'] = null;
            $service['pid'] = 0;
            $service['memory'] = 0;
        } elseif ($verb === 'reload') {
            // reload ไม่เปลี่ยนสถานะ แต่ล้มถ้าบริการไม่ได้ทำงานอยู่ — เหมือน systemd จริง
            if ($service['active'] !== 'active') {
                return new ExecResult(
                    argv: $argv,
                    exitCode: 1,
                    stdout: '',
                    stderr: "Failed to reload {$unit}: Unit is not active.\n",
                    durationMs: 3,
                    simulated: true,
                );
            }
        } else {
            $service['active'] = 'active';
            $service['sub'] = 'running';
            $service['since'] = time();
            $service['pid'] = random_int(400, 9999);
            $service['memory'] = max(4_194_304, (int) ($service['memory'] ?: 33_554_432));
        }

        $services[$unit] = $service;
        $state->saveServices($services);

        // หน่วงเวลาเล็กน้อยให้เหมือนของจริง UI จะได้เห็น spinner ทำงานจริง
        usleep(150_000);

        return new ExecResult(
            argv: $argv,
            exitCode: 0,
            stdout: '',
            stderr: '',
            durationMs: 150,
            simulated: true,
        );
    }

    private static function normalizeUnit(string $unit): string
    {
        return str_ends_with($unit, '.service') ? substr($unit, 0, -8) : $unit;
    }
}
