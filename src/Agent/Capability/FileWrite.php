<?php

declare (strict_types = 1);

namespace Phpcp\Agent\Capability;

use Phpcp\Agent\Context;
use Phpcp\Agent\Executor\Executor;
use Phpcp\Agent\ValidationError;
use Phpcp\Domain\FileCatalog;

/**
 * บันทึกเนื้อไฟล์ข้อความจากตัวแก้ไข
 *
 * เขียนผ่านไฟล์ชั่วคราวแล้ว rename ทับ (atomic) — ถ้าเครื่องดับกลางทาง
 * ไฟล์เดิมยังอยู่ครบ ไม่เหลือไฟล์ที่เขียนค้างครึ่งเดียวซึ่งจะทำให้เว็บล่มทันที
 */
final class FileWrite extends FileCapability
{
    public static function name(): string
    {
        return 'file.write';
    }

    public function isMutating(): bool
    {
        return true;
    }

    public function summary(): string
    {
        return 'บันทึกเนื้อหาไฟล์';
    }

    /**
     * @param array $args
     * @return mixed
     */
    public function validate(array $args): array
    {
        $base = self::baseArgs($args);

        if ($base['path'] === '') {
            throw new ValidationError('ต้องระบุไฟล์ที่จะบันทึก');
        }

        $content = $args['content'] ?? '';
        if (!is_string($content)) {
            throw new ValidationError('เนื้อหาไฟล์ต้องเป็นข้อความ');
        }
        if (strlen($content) > FileCatalog::MAX_EDIT_BYTES) {
            throw new ValidationError('เนื้อหายาวเกิน 5 MB');
        }
        if (!mb_check_encoding($content, 'UTF-8')) {
            throw new ValidationError('เนื้อหาต้องเป็นข้อความ UTF-8');
        }

        return $base + [
            'content' => $content,
            // สร้างไฟล์ใหม่ได้เมื่อผู้ใช้กด "ไฟล์ใหม่" — ค่าปริยายคือเขียนทับของเดิมเท่านั้น
            'create' => self::flag($args, 'create')
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
        $relative = $args['path'];
        $name = basename($relative);
        $content = $args['content'];
        $create = $args['create'];

        if (!FileCatalog::isEditable($name, strlen($content))) {
            throw new ValidationError('ไฟล์ชนิดนี้แก้ไขผ่านตัวจัดการไฟล์ไม่ได้');
        }

        $result = $this->withPath(
            $executor,
            $scope,
            $relative,
            static function (string $root, string $target) use ($executor, $content, $create): array {
                $info = $executor->stat($target);

                if ($info !== null) {
                    if ($create) {
                        throw new ValidationError('มีไฟล์ชื่อนี้อยู่แล้ว');
                    }
                    if ($info['type'] !== 'file') {
                        throw new ValidationError('เขียนทับได้เฉพาะไฟล์ธรรมดา');
                    }
                } elseif (!$create) {
                    throw new ValidationError('ไม่พบไฟล์ที่จะบันทึก');
                }

                // เขียนไฟล์ชั่วคราวข้าง ๆ ของเดิม (โฟลเดอร์เดียวกัน) เพื่อให้ rename
                // อยู่ใน filesystem เดียวกันและเป็น atomic จริง
                $mode = $info['mode'] ?? 0o640;
                $temporary = $target.'.phpcp-'.bin2hex(random_bytes(6));

                $executor->writeFile($temporary, $content, $mode);

                try {
                    $executor->rename($temporary, $target);
                } catch (\Throwable $e) {
                    $executor->removePath($temporary);

                    throw $e;
                }

                return ['size' => strlen($content), 'mode' => sprintf('%04o', $mode)];
            },
            mustExist: false,
        );

        return [
            'root' => $scope->key,
            'site_id' => $scope->siteId,
            'path' => $relative,
            'name' => $name,
            'created' => $create,
            ...$result
        ];
    }
}
