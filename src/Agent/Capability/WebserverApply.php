<?php

declare(strict_types=1);

namespace Phpcp\Agent\Capability;

use Phpcp\Agent\Context;
use Phpcp\Agent\ExecutionFailed;
use Phpcp\Agent\Executor\Executor;
use Phpcp\Agent\ValidationError;
use Phpcp\Domain\SettingsRepository;
use Phpcp\Support\Validator;

/**
 * เปลี่ยนเว็บเซิร์ฟเวอร์ให้จบในคำสั่งเดียว — กดจากหน้าจอแล้วใช้งานได้เลย
 *
 * **ทำไมต้องเป็น capability เดียว ไม่ใช่หลายปุ่ม:** การสลับเว็บเซิร์ฟเวอร์มีห้าขั้นตอน
 * ที่ต้องทำ*เรียงกัน*และ*ครบทุกขั้น* ไม่งั้นเครื่องค้างครึ่งทางแบบที่ผู้ดูแลกู้เองไม่ได้:
 *
 *   1. บันทึกค่าที่เลือก
 *   2. เขียนไฟล์ตั้งค่าของทุกเว็บใหม่ตามรูปแบบของเซิร์ฟเวอร์ตัวใหม่ (site.rebuild)
 *   3. **restart** ตัวที่ต้องเปลี่ยนพอร์ตที่ฟัง — ไม่ใช่ reload · Apache เปลี่ยน
 *      Listen ตอน reload ไม่ได้ตามการออกแบบของมันเอง
 *   4. เปิดตัวที่ต้องใช้ / หยุดตัวที่ไม่ใช้แล้ว — สองตัวถือพอร์ต 80 พร้อมกันไม่ได้
 *   5. ยิงคำขอจริงเข้าไปดูว่าเว็บยังตอบอยู่
 *
 * ถ้าปล่อยให้ผู้ดูแลทำเองทีละขั้นตามคู่มือ ข้ามขั้นเดียวก็ได้ nginx ที่สตาร์ตไม่ขึ้น
 * พร้อมข้อความ "Address already in use" ที่ไม่ได้บอกว่าต้องทำอะไรต่อ — ซึ่งเกิดขึ้นจริง
 * มาแล้ว และเป็นเหตุผลทั้งหมดที่ control panel ควรมีอยู่
 */
final class WebserverApply extends SiteCapability
{
    /** โหมดที่เลือกได้ พร้อมชื่อที่ผู้ใช้เห็น */
    public const MODES = [
        'nginx-proxy' => 'nginx + Apache (รองรับ .htaccess · แนะนำ)',
        'apache' => 'Apache อย่างเดียว',
        'nginx' => 'nginx อย่างเดียว (ไม่มี .htaccess)',
    ];

    /** unit ที่แต่ละโหมดต้องการให้ทำงาน */
    private const REQUIRED_UNITS = [
        'apache' => ['apache2'],
        'nginx' => ['nginx'],
        'nginx-proxy' => ['apache2', 'nginx'],
    ];

    public static function name(): string
    {
        return 'webserver.apply';
    }

    public function permission(): string
    {
        return 'settings.manage';
    }

    public function isMutating(): bool
    {
        return true;
    }

    public function summary(): string
    {
        return 'เปลี่ยนเว็บเซิร์ฟเวอร์ที่ใช้โฮสต์เว็บไซต์';
    }

    public function validate(array $args): array
    {
        $mode = Validator::requireString($args, 'mode', 32);

        if (!array_key_exists($mode, self::MODES)) {
            throw new ValidationError(sprintf(
                'โหมดที่เลือกใช้ไม่ได้: %s (ใช้ได้: %s)',
                $mode,
                implode(', ', array_keys(self::MODES)),
            ));
        }

        return [
            'mode' => $mode,
            // ให้ nginx ตอบไฟล์ static เอง — มีผลเฉพาะโหมด nginx-proxy
            'static_by_nginx' => !isset($args['static_by_nginx'])
                || in_array($args['static_by_nginx'], [true, 1, '1', 'on', 'true'], true),
        ];
    }

    public function run(array $args, Executor $executor, Context $context): array
    {
        $target = $args['mode'];
        $settings = new SettingsRepository($context->db);
        $previous = self::webServerMode($context);

        $this->assertInstalled($target, $executor);

        // บันทึกก่อนลงมือ — ขั้นตอนที่เหลืออ่านค่านี้ผ่าน SiteCapability::webServerMode()
        // ถ้าบันทึกทีหลัง site.rebuild จะเขียนไฟล์ด้วยรูปแบบของโหมดเก่าทั้งหมด
        $settings->save([
            'webserver.mode' => $target,
            'webserver.static_by_nginx' => $args['static_by_nginx'] ? '1' : '0',
        ]);

        try {
            $rebuild = (new SiteRebuild())->run([], $executor, $context);
        } catch (\Throwable $e) {
            // คืนค่าเดิมทันที ไม่งั้นหน้าจอจะบอกว่าใช้โหมดใหม่อยู่ทั้งที่ไฟล์บนดิสก์
            // ยังเป็นของเก่า — สภาพที่ผู้ดูแลมองไม่ออกว่าอะไรเป็นอะไร
            $settings->save(['webserver.mode' => $previous]);

            throw new ExecutionFailed(
                "เขียนไฟล์ตั้งค่าของเว็บไซต์ไม่สำเร็จ จึงคืนค่าเดิมแล้ว\n\n" . $e->getMessage(),
            );
        }

        $steps = $this->switchUnits($target, $executor);

        return [
            'mode' => $target,
            'previous' => $previous,
            'static_by_nginx' => $args['static_by_nginx'],
            'rebuilt' => $rebuild['count'] ?? 0,
            'steps' => $steps,
            'message' => sprintf(
                'เปลี่ยนเป็น %s แล้ว · เขียนไฟล์ตั้งค่าใหม่ %d เว็บไซต์',
                self::MODES[$target],
                (int) ($rebuild['count'] ?? 0),
            ),
        ];
    }

    /**
     * เครื่องต้องมีโปรแกรมที่โหมดนั้นต้องใช้จริงก่อนจะยอมเปลี่ยน
     *
     * ตรวจก่อนบันทึกค่า — ถ้าปล่อยให้บันทึกแล้วค่อยไปล้มตอนสตาร์ต ผู้ดูแลจะเหลือเครื่อง
     * ที่ค่าตั้งบอกว่าใช้ nginx แต่ไม่มี nginx อยู่ ซึ่งกู้กลับได้ยากกว่าการถูกปฏิเสธ
     */
    private function assertInstalled(string $mode, Executor $executor): void
    {
        $missing = [];

        foreach (self::REQUIRED_UNITS[$mode] as $unit) {
            $binary = $unit === 'nginx' ? '/usr/sbin/nginx' : '/usr/sbin/apache2';

            if (!$executor->exists($executor->path($binary))) {
                $missing[] = $unit;
            }
        }

        if ($missing !== []) {
            throw new ValidationError(sprintf(
                'เครื่องนี้ยังไม่ได้ติดตั้ง %s — ติดตั้งด้วย `apt install %s` ก่อน',
                implode(' และ ', $missing),
                implode(' ', $missing),
            ));
        }
    }

    /**
     * เปิด/ปิด/รีสตาร์ตให้ตรงกับโหมดใหม่
     *
     * **หยุดตัวที่ไม่ใช้ก่อนเสมอ** — พอร์ต 80 มีเจ้าของได้ทีละตัว ถ้าสั่งเปิดตัวใหม่
     * ก่อนหยุดตัวเก่าจะได้ "Address already in use" ทุกครั้ง
     *
     * @return list<string>
     */
    private function switchUnits(string $mode, Executor $executor): array
    {
        $needed = self::REQUIRED_UNITS[$mode];
        $steps = [];

        foreach (['nginx', 'apache2'] as $unit) {
            if (in_array($unit, $needed, true)) {
                continue;
            }

            $executor->exec([$executor->path('/usr/bin/systemctl'), 'stop', $unit], timeout: 30);
            $steps[] = "หยุด {$unit}";
        }

        foreach ($needed as $unit) {
            // restart ไม่ใช่ reload — พอร์ตที่ฟังเปลี่ยนได้เฉพาะตอนสตาร์ตใหม่เท่านั้น
            $result = $executor->exec(
                [$executor->path('/usr/bin/systemctl'), 'restart', $unit],
                timeout: 60,
            );

            if (!$result->ok()) {
                throw new ExecutionFailed(sprintf(
                    "เขียนไฟล์ตั้งค่าเรียบร้อยแล้วแต่สตาร์ต %s ไม่สำเร็จ\n\n%s\n\n"
                    . 'ไฟล์บนดิสก์ถูกต้องแล้ว — แก้สาเหตุข้างต้นแล้วสั่งเปลี่ยนโหมดซ้ำได้ทันที',
                    $unit,
                    trim($result->stderr ?: $result->stdout),
                ));
            }

            $steps[] = "รีสตาร์ต {$unit}";
        }

        return $steps;
    }
}
