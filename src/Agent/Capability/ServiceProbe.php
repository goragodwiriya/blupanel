<?php

declare (strict_types = 1);

namespace Phpcp\Agent\Capability;

use Phpcp\Agent\Executor\Executor;
use Phpcp\Domain\ServiceCatalog;

/**
 * อ่านสถานะ service หนึ่งตัวจาก systemd — ใช้ร่วมกันระหว่าง capability หลายตัว
 *
 * ไม่ใช่ Capability เอง แต่เป็นตัวช่วย เพื่อให้ทั้ง service.status และคำสั่งที่เปลี่ยนสถานะ
 * (start/stop/restart/reload) คืนข้อมูลรูปแบบเดียวกัน — UI จึงอัปเดตการ์ดได้ทันที
 * หลังกดปุ่มโดยไม่ต้องเรียกซ้ำอีกรอบ
 */
final class ServiceProbe
{
    public const PROPERTIES = [
        'Description',
        'LoadState',
        'ActiveState',
        'SubState',
        'UnitFileState',
        'MainPID',
        'MemoryCurrent',
        'ActiveEnterTimestamp'
    ];

    /** @return array<string,mixed> */
    public static function read(Executor $executor, string $unit): array
    {
        $argv = [$executor->path('/usr/bin/systemctl'), 'show', $unit, '--no-pager'];
        foreach (self::PROPERTIES as $property) {
            $argv[] = '--property='.$property;
        }

        $result = $executor->exec($argv, timeout: 10);
        $keyValues = $result->keyValues();

        // เมื่อ systemd ไม่ได้รันเป็น PID 1 (เช่น ใน Docker container หรือสภาพแวดล้อมที่ไม่มี systemd)
        // systemctl show จะล้มเหลว ให้ใช้ fallback ด้วย service / sysvinit / process check
        if (empty($keyValues['LoadState']) || $keyValues['LoadState'] === 'not-found') {
            $fallback = self::probeFallback($executor, $unit);
            if ($fallback !== null) {
                return $fallback;
            }
        }

        return self::parse($unit, $keyValues);
    }

    /**
     * Fallback สำหรับสภาพแวดล้อมที่ไม่มี systemd (เช่น Docker container)
     * @return array<string,mixed>|null
     */
    private static function probeFallback(Executor $executor, string $unit): ?array
    {
        $initScript = '/etc/init.d/'.$unit;
        $unitFiles = [
            '/lib/systemd/system/'.$unit.'.service',
            '/usr/lib/systemd/system/'.$unit.'.service',
            '/etc/systemd/system/'.$unit.'.service',
        ];

        $exists = file_exists($initScript);
        foreach ($unitFiles as $unitFile) {
            $exists = $exists || file_exists($unitFile);
        }

        // ทดลองรัน service <unit> status
        $serviceBin = file_exists('/usr/sbin/service') ? '/usr/sbin/service' : '/usr/bin/service';
        $statusRes = $executor->exec([$executor->path($serviceBin), $unit, 'status'], timeout: 5);

        $output = strtolower($statusRes->stdout.' '.$statusRes->stderr);

        // ข้อความที่แต่ละระบบใช้บอกว่า "ไม่มี unit นี้" — ต่างกันตามตัว init ที่ติดตั้งอยู่
        //
        // `could not be found` คือคำที่ systemd รุ่นใหม่ใช้จริง และเคย**ตกสำรวจ**มาแล้ว:
        // รายการเดิมมีแค่ `unrecognized service` กับ `not-found` ซึ่งไม่ตรงสักคำ ทำให้
        // php-fpm เวอร์ชันที่ไม่ได้ลงไว้ถูกรายงานว่า "ติดตั้งแล้วแต่หยุดทำงาน" แล้วยิง
        // แจ้งเตือนเรื่องบริการที่ไม่มีอยู่จริง
        $notFoundPhrases = [
            'unrecognized service',
            'not-found',
            'could not be found',
            'no such file or directory',
            'not be found',
        ];

        $isUnrecognized = false;
        foreach ($notFoundPhrases as $phrase) {
            $isUnrecognized = $isUnrecognized || str_contains($output, $phrase);
        }

        if (!$exists && $isUnrecognized) {
            return null; // ถือว่าไม่ได้ติดตั้งจริงๆ
        }

        // ไม่มีไฟล์ unit และคำสั่งก็ล้มเหลว — เดาสถานะไม่ออกจึงต้องไม่เดา
        //
        // การคืน `installed => true` ในกรณีนี้อันตรายกว่าการยอมรับว่าไม่รู้ เพราะผู้เรียก
        // (เช่น `alert.check`) จะเห็นเป็น "ติดตั้งแล้วแต่ไม่ทำงาน" แล้วปลุกคนกลางดึก
        // เรื่องบริการที่ไม่มีอยู่บนเครื่องนี้เลย
        if (!$exists && $statusRes->exitCode !== 0) {
            return null;
        }

        $isRunning = $statusRes->exitCode === 0 || str_contains($output, 'is running') || str_contains($output, 'running');
        $activeState = $isRunning ? 'active' : 'inactive';

        return [
            'unit' => $unit,
            'label' => ServiceCatalog::label($unit),
            'kind' => ServiceCatalog::kind($unit),
            'critical' => ServiceCatalog::isCritical($unit),
            'installed' => true,
            'description' => ServiceCatalog::label($unit),
            'active' => $activeState,
            'sub' => $isRunning ? 'running' : 'dead',
            'enabled' => 'enabled',
            'running' => $isRunning,
            'pid' => 0,
            'memory_bytes' => 0,
            'since' => null,
            'status' => self::statusOf(true, $activeState)
        ];
    }

    /**
     * @param array<string,string> $values
     * @return array<string,mixed>
     */
    public static function parse(string $unit, array $values): array
    {
        $activeState = $values['ActiveState'] ?? 'inactive';
        $loadState = $values['LoadState'] ?? 'not-found';
        $installed = $loadState !== 'not-found';

        $since = null;
        $timestamp = trim($values['ActiveEnterTimestamp'] ?? '');
        if ($timestamp !== '') {
            $parsed = strtotime($timestamp);
            $since = $parsed === false ? null : $parsed;
        }

        // MemoryCurrent เป็น "[not set]" เมื่อบริการไม่ได้ทำงาน
        $memoryRaw = $values['MemoryCurrent'] ?? '0';

        return [
            'unit' => $unit,
            'label' => ServiceCatalog::label($unit),
            'kind' => ServiceCatalog::kind($unit),
            'critical' => ServiceCatalog::isCritical($unit),
            'installed' => $installed,
            'description' => $values['Description'] ?? ServiceCatalog::label($unit),
            'active' => $activeState,
            'sub' => $values['SubState'] ?? 'dead',
            'enabled' => $values['UnitFileState'] ?? 'disabled',
            'running' => $activeState === 'active',
            'pid' => (int) ($values['MainPID'] ?? 0),
            'memory_bytes' => ctype_digit($memoryRaw) ? (int) $memoryRaw : 0,
            'since' => $since,
            'status' => self::statusOf($installed, $activeState)
        ];
    }

    /**
     * แปลงสถานะ systemd เป็นสถานะที่ UI แสดงตาม PROMPT.md
     * (ทำงานปกติ / หยุดทำงาน / มีปัญหา / ต้องดำเนินการ)
     */
    public static function statusOf(bool $installed, string $activeState): string
    {
        if (!$installed) {
            return 'not_installed';
        }

        return match ($activeState) {
            'active', 'reloading' => 'running',
            'failed' => 'failed',
            'activating', 'deactivating' => 'transitioning',
            default => 'stopped',
        };
    }
}
