<?php

declare (strict_types = 1);

namespace Phpcp\Agent\Capability;

use Phpcp\Agent\Context;
use Phpcp\Agent\Executor\Executor;
use Phpcp\Agent\ValidationError;
use Phpcp\Support\PathGuard;
use Phpcp\Support\Validator;

/**
 * ลบไฟล์และโฟลเดอร์
 *
 * ปฏิเสธการลบโฟลเดอร์โครงสร้างของเว็บไซต์ (public, logs, tmp, backup) —
 * ไม่ใช่เพราะกลัวผู้ใช้ทำลายข้อมูลตัวเอง แต่เพราะ vhost กับ pool ชี้ไปที่โฟลเดอร์เหล่านี้
 * ลบแล้วเว็บจะ 500 ทันทีและอาการจะดูเหมือนปัญหาที่ตัว PHP ซึ่งตามหาสาเหตุยากมาก
 *
 * `backup` เป็นตัวโฟลเดอร์เท่านั้น — **ไฟล์ข้างในลบได้ตามปกติ** และต้องลบได้ ลูกค้า
 * เป็นเจ้าของสำเนาของตัวเองและตัดสินใจเองได้ว่าจะเก็บอันไหน (PLAN-BACKUP-V2 ข้อ B4)
 */
final class FileDelete extends FileCapability
{
    private const MAX_ITEMS = 100;

    /** โฟลเดอร์ชั้นบนสุดที่ระบบสร้างให้และห้ามลบ */
    private const PROTECTED_ROOTS = ['public', 'logs', 'tmp', 'backup'];

    public static function name(): string
    {
        return 'file.delete';
    }

    public function isMutating(): bool
    {
        return true;
    }

    public function summary(): string
    {
        return 'ลบไฟล์หรือโฟลเดอร์';
    }

    /**
     * @param array $args
     */
    public function validate(array $args): array
    {
        $items = [];
        foreach (Validator::requireStringList($args, 'items', self::MAX_ITEMS, 4096) as $item) {
            $relative = PathGuard::clean($item, 'เส้นทางที่จะลบ');

            if ($relative === '') {
                throw new ValidationError('ลบรากของขอบเขตไม่ได้');
            }

            $items[] = $relative;
        }

        if ($items === []) {
            throw new ValidationError('ต้องเลือกอย่างน้อยหนึ่งรายการก่อนจึงจะลบได้');
        }

        return [
            'root' => Validator::pattern(
                Validator::requireString($args, 'root', 64),
                '/^[a-z][a-z0-9-]{0,63}$/',
                'คีย์ขอบเขตไฟล์ไม่ถูกต้อง',
            ),
            'items' => $items
        ];
    }

    /**
     * @param array $args
     * @param Executor $executor
     * @param Context $context
     */
    public function run(array $args, Executor $executor, Context $context): array
    {
        $scope = $this->scope($context, $args);

        // โฟลเดอร์โครงสร้างของเว็บไซต์ลบไม่ได้ — กฎนี้ใช้เฉพาะขอบเขตของเว็บไซต์
        // ขอบเขตระดับเซิร์ฟเวอร์ไม่มีโครงสร้างแบบนี้ จึงไม่ควรถูกจำกัดด้วยชื่อเดียวกัน
        if ($scope->isSite()) {
            foreach ($args['items'] as $relative) {
                if (in_array($relative, self::PROTECTED_ROOTS, true)) {
                    throw new ValidationError("โฟลเดอร์ {$relative} เป็นโครงสร้างของเว็บไซต์ ลบไม่ได้");
                }
            }
        }
        $items = $args['items'];

        $result = $this->withSite($executor, $scope, static function (callable $resolve) use ($executor, $items): array {
            $deleted = [];

            foreach ($items as $relative) {
                $executor->removePath($resolve($relative));
                $deleted[] = basename($relative);
            }

            return ['deleted' => $deleted];
        });

        return [
            'root' => $scope->key,
            'site_id' => $scope->siteId,
            'deleted' => $result['deleted'],
            'count' => count($result['deleted']),
            'message' => sprintf('ลบ %d รายการแล้ว', count($result['deleted']))
        ];
    }
}
