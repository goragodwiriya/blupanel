<?php

declare(strict_types=1);

namespace Phpcp\Agent\Capability;

use Phpcp\Agent\Capability;
use Phpcp\Agent\Context;
use Phpcp\Agent\Executor\Executor;
use Phpcp\Agent\ValidationError;
use Phpcp\Domain\ConfigFileCatalog;
use Phpcp\Driver\Dns\BindZoneManager;
use Phpcp\Driver\RollbackGuard;
use Phpcp\Driver\WebServer\CustomConfig;

/**
 * เขียนไฟล์ตั้งค่าเพิ่มเติมของ BIND9
 *
 * **สิทธิ์ระดับเครื่อง (`settings.manage`) ไม่ใช่ `domain.manage`** — เนื้อไฟล์นี้ถูกอ่าน
 * ตอน named สตาร์ต และมีผลกับ zone ของลูกค้าทุกรายพร้อมกัน · ผู้ดูแลเว็บไซต์ที่มีสิทธิ์
 * แก้เรกคอร์ดของโดเมนตัวเองต้องแตะไฟล์นี้ไม่ได้ ด้วยเหตุผลเดียวกับ `dns.reload`
 *
 * ## การคืนค่าต้องคืนสองไฟล์ ไม่ใช่ไฟล์เดียว
 *
 * RollbackGuard **ลบ**ไฟล์ทิ้งเมื่อสภาพเดิมคือ "ยังไม่มีไฟล์นี้" แล้วสั่ง
 * `systemctl reload-or-restart` ต่อทันที · การบันทึกครั้งแรกของเครื่องหนึ่งจึงเป็นกรณีที่
 * อันตรายที่สุด: ถ้าจับคู่คืนแค่ไฟล์ส่วนเสริม บรรทัด `include` ใน `named.conf.local`
 * จะยังอยู่และชี้ไปไฟล์ที่เพิ่งถูกลบ — **กลไกคืนค่าที่มีไว้กันเครื่องพังจะกลายเป็นตัวที่
 * ทำ DNS ทั้งเครื่องล่มเสียเอง** และล่มแบบที่ผู้ดูแลไม่ได้ทำอะไรผิดเลยด้วย
 *
 * {@see BindZoneManager::writeCustomConfig()} จึงคืนสภาพเดิมของทั้งสองไฟล์กลับมาให้
 * ผูกไว้ด้วยกันในการคืนค่าครั้งเดียว
 */
final class DnsCustomConfig implements Capability
{
    public static function name(): string
    {
        return 'dns.custom_config';
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
        return 'เขียนไฟล์ตั้งค่าเพิ่มเติมของ BIND9';
    }

    public function validate(array $args): array
    {
        return [
            'content' => CustomConfig::assertContent((string) ($args['content'] ?? '')),
            'key' => isset($args['key']) && $args['key'] !== ''
                ? ConfigFileCatalog::assertKey((string) $args['key'])
                : '',
            'window' => isset($args['window']) ? (int) $args['window'] : RollbackGuard::DEFAULT_WINDOW,
        ];
    }

    public function run(array $args, Executor $executor, Context $context): array
    {
        /*
         * ตัดสินจากทะเบียนอีกรอบว่าไฟล์นี้แก้ได้จริง — ปุ่มที่ไม่ขึ้นบนหน้าจอไม่ใช่ด่าน
         * ความปลอดภัย คำขอที่ประกอบเองยังส่งคีย์ของไฟล์ที่ระบบสร้างมาได้เสมอ
         */
        if ($args['key'] !== '') {
            $file = ConfigFileCatalog::find(
                ConfigFileCatalog::forDns(BindZoneManager::customConfigPath($context->config)),
                $args['key'],
            );

            if ($file === null || $file['kind'] !== ConfigFileCatalog::KIND_WRITABLE) {
                throw new ValidationError(
                    'ไฟล์นี้แก้จากหน้าเว็บไม่ได้ — panel เขียนทับทั้งไฟล์ทุกครั้งที่มีการเพิ่มหรือลบ zone '
                    . 'สิ่งที่แก้ลงไปจะหายไปเงียบ ๆ · เขียนค่าที่ต้องการลงไฟล์ส่วนเสริมแทน',
                );
            }
        }

        $manager = new BindZoneManager($executor, $context->config, $context->db);
        $written = $manager->writeCustomConfig($args['content']);

        $rollbackId = (new RollbackGuard($context->db))->arm(
            action: self::name(),
            description: 'แก้ค่าตั้งเพิ่มเติมของ BIND9',
            // ทั้งสองไฟล์ในการคืนค่าครั้งเดียว — ดูเหตุผลที่หัวคลาส
            files: $written['files'],
            reloadUnits: ['named'],
            window: $args['window'],
            actorId: $context->actor->userId,
        );

        return [
            'service' => 'bind',
            'path' => $written['path'],
            'rollback_id' => $rollbackId,
            'window' => $args['window'],
            'message' => 'บันทึกค่าตั้งเพิ่มเติมของ BIND9 แล้ว — ทดสอบว่าโดเมนยังตอบคำถามได้จริง '
                . 'แล้วกดยืนยันภายในเวลาที่กำหนด ไม่งั้นระบบจะคืนค่าเดิมให้เอง',
        ];
    }
}
