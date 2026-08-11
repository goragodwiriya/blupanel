<?php

declare (strict_types = 1);

namespace Phpcp\Agent\Capability;

use Phpcp\Agent\Context;
use Phpcp\Agent\Executor\Executor;
use Phpcp\Agent\ValidationError;
use Phpcp\Support\PathGuard;
use Phpcp\Support\Validator;

/**
 * สร้างโฟลเดอร์ใหม่ในโฟลเดอร์ที่กำลังเปิดอยู่
 *
 * รับ "โฟลเดอร์แม่" กับ "ชื่อ" แยกกัน ไม่ใช่เส้นทางเต็มก้อนเดียว —
 * ชื่อจึงถูกตรวจว่าเป็นชิ้นเดียวห้ามมี / อยู่ข้างใน ผู้ใช้จึงสร้างโฟลเดอร์
 * ข้ามชั้นหรือย้อนขึ้นด้วยการพิมพ์ชื่อไม่ได้
 */
final class FileMkdir extends FileCapability
{
    public static function name(): string
    {
        return 'file.mkdir';
    }

    public function isMutating(): bool
    {
        return true;
    }

    public function summary(): string
    {
        return 'สร้างโฟลเดอร์ใหม่';
    }

    /**
     * @param array $args
     */
    public function validate(array $args): array
    {
        return self::baseArgs($args) + [
            'name' => PathGuard::name(Validator::requireString($args, 'name'), 'ชื่อโฟลเดอร์')
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
        $relative = PathGuard::join($args['path'], $args['name']);

        $this->withPath(
            $executor,
            $scope,
            $relative,
            static function (string $root, string $target) use ($executor): array {
                if ($executor->stat($target) !== null) {
                    throw new ValidationError('มีไฟล์หรือโฟลเดอร์ชื่อนี้อยู่แล้ว');
                }

                // 0750 ไม่ใช่ 0755: กลุ่มของเว็บเซิร์ฟเวอร์เข้าถึงได้ แต่ผู้ใช้อื่นบนเครื่องไม่ได้
                $executor->makeDirectory($target, 0o750);

                return [];
            },
            mustExist: false,
        );

        return [
            'root' => $scope->key,
            'site_id' => $scope->siteId,
            'path' => $relative,
            'name' => $args['name'],
            'message' => 'สร้างโฟลเดอร์ '.$args['name'].' แล้ว'
        ];
    }
}
