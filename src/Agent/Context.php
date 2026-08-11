<?php

declare(strict_types=1);

namespace Phpcp\Agent;

use Phpcp\Kernel\Config;
use Phpcp\Kernel\Db;

/**
 * สิ่งแวดล้อมที่ capability เข้าถึงได้ นอกเหนือจาก Executor
 *
 * จงใจให้เล็ก — ยิ่ง capability พึ่งพาน้อย ยิ่งตรวจสอบและทดสอบง่าย
 */
final readonly class Context
{
    public function __construct(
        public Actor $actor,
        public Config $config,
        public Db $db,
    ) {
    }
}
