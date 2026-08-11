<?php

declare(strict_types=1);

namespace Phpcp\Agent\Executor;

use Phpcp\Agent\Capability;
use Phpcp\Kernel\Config;
use Phpcp\Kernel\Mode;

/**
 * เลือก Executor ให้ capability ตามโหมดที่ระบบตั้งอยู่ — จุดเดียวในระบบที่ตัดสินใจเรื่องโหมด
 *
 * รายละเอียดที่ทำให้ dryrun มีประโยชน์จริง: capability ที่อ่านอย่างเดียวจะได้ RealExecutor
 * ผู้ใช้จึงเห็นสถานะจริงของเครื่องบนหน้าจอ พร้อมกับรายการคำสั่งที่ระบบ "จะ" ทำถ้ากดจริง
 */
final class ExecutorFactory
{
    private ?SandboxExecutor $sandbox = null;

    public function __construct(private readonly Config $config)
    {
    }

    public function for(Capability $capability): Executor
    {
        return match ($this->config->mode) {
            Mode::Production => new RealExecutor(),

            // ใช้ตัวเดิมซ้ำภายใน process เพื่อให้ simulatedCommands() สะสมครบทั้ง request
            Mode::Sandbox => $this->sandbox ??= new SandboxExecutor($this->config->sandboxPrefix()),

            Mode::DryRun => $capability->isMutating()
                ? new DryRunExecutor()
                : new RealExecutor(),
        };
    }
}
