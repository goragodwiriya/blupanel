<?php

declare (strict_types = 1);

namespace Phpcp\Agent\Capability;

use Phpcp\Agent\Capability;
use Phpcp\Agent\Context;
use Phpcp\Agent\ExecutionFailed;
use Phpcp\Agent\Executor\Executor;
use Phpcp\Agent\SelfProtection;
use Phpcp\Agent\ValidationError;
use Phpcp\Domain\ServiceCatalog;
use Phpcp\Support\Validator;

/**
 * ฐานของคำสั่งที่เปลี่ยนสถานะ service — start / stop / restart / reload
 *
 * เป็น capability กลุ่มแรกของระบบที่ "เปลี่ยนแปลง" อะไรจริง ๆ จึงผ่านเส้นทางเต็ม:
 *   ตรวจ permission → validate → เขียน audit ก่อนลงมือ → รัน → เขียนผล
 *
 * ความปลอดภัย: ชื่อ unit มาจาก allowlist ตายตัวใน ServiceCatalog และผ่าน SelfProtection
 * ก่อนเสมอ ค่าที่ผู้ใช้ส่งมาไม่เคยถูกนำไปประกอบเป็นคำสั่งโดยตรง
 */
abstract class ServiceAction implements Capability
{
    /** คำกริยาที่จะส่งให้ systemctl — มาจากโค้ด ไม่ใช่จาก input */
    abstract protected function verb(): string;

    public function permission(): string
    {
        return 'service.control';
    }

    public function isMutating(): bool
    {
        return true;
    }

    /**
     * @param array $args
     */
    public function validate(array $args): array
    {
        $unit = strtolower(Validator::requireString($args, 'service', 64));

        // ตรวจบริการของ panel ก่อนเรื่องอื่น เพื่อให้ข้อความผิดพลาดตรงกับสาเหตุจริง
        SelfProtection::assertUnit($unit);

        if (!ServiceCatalog::isAllowed($unit)) {
            throw new ValidationError("ไม่รู้จักบริการ: {$unit}");
        }

        return ['service' => $unit];
    }

    /**
     * @param array $args
     * @param Executor $executor
     * @param Context $context
     */
    public function run(array $args, Executor $executor, Context $context): array
    {
        $unit = $args['service'];
        $before = ServiceProbe::read($executor, $unit);

        if (!$before['installed']) {
            throw new ExecutionFailed("ไม่พบบริการ {$unit} บนเครื่องนี้");
        }

        $result = $executor->exec(
            [$executor->path('/usr/bin/systemctl'), $this->verb(), $unit],
            timeout: 30,
        );

        if (!$result->ok()) {
            // Fallback สำหรับ Docker/sysvinit หาก systemctl ล้มเหลว
            $serviceResult = $executor->exec(
                [$executor->path('/usr/sbin/service'), $unit, $this->verb()],
                timeout: 30,
            );

            if (!$serviceResult->ok()) {
                $detail = trim($result->stderr) !== '' ? ' — '.trim($result->stderr) : '';

                throw new ExecutionFailed(
                    sprintf('%s บริการ %s ไม่สำเร็จ%s', $this->actionLabel(), $unit, $detail),
                    $result->exitCode,
                    $result->stderr,
                );
            }
        }

        // อ่านสถานะกลับทันทีเพื่อให้ UI อัปเดตการ์ดได้โดยไม่ต้องเรียกซ้ำ
        $after = ServiceProbe::read($executor, $unit);

        return [
            'service' => $unit,
            'action' => $this->verb(),
            'before' => $before['status'],
            'after' => $after['status'],
            'state' => $after,
            'message' => sprintf('%s บริการ %s เรียบร้อยแล้ว', $this->actionLabel(), ServiceCatalog::label($unit))
        ];
    }

    protected function actionLabel(): string
    {
        return match ($this->verb()) {
            'start' => 'เริ่ม',
            'stop' => 'หยุด',
            'restart' => 'รีสตาร์ต',
            'reload' => 'โหลดค่าตั้งใหม่ของ',
            default => $this->verb(),
        };
    }
}
