<?php

declare (strict_types = 1);

namespace Phpcp\Agent\Capability;

use Phpcp\Agent\Context;
use Phpcp\Agent\Executor\Executor;
use Phpcp\Agent\ValidationError;
use Phpcp\Domain\FileCatalog;

/**
 * อ่านเนื้อไฟล์ข้อความเพื่อเปิดในตัวแก้ไข
 *
 * เปิดได้เฉพาะนามสกุลใน allowlist ของ FileCatalog และไม่เกิน 5 MB —
 * ไม่ใช่เพื่อกันการอ่านไฟล์ (ผู้ใช้เป็นเจ้าของไฟล์อยู่แล้ว) แต่เพื่อกันไม่ให้
 * ไฟล์ไบนารีขนาดใหญ่ถูกดึงเข้าหน่วยความจำแล้วส่งข้ามโปรโตคอลจนล้น frame
 */
final class FileRead extends FileCapability
{
    public static function name(): string
    {
        return 'file.read';
    }

    public function isMutating(): bool
    {
        return false;
    }

    public function summary(): string
    {
        return 'อ่านเนื้อหาไฟล์ข้อความ';
    }

    /**
     * @param array $args
     * @return mixed
     */
    public function validate(array $args): array
    {
        $base = self::baseArgs($args);

        if ($base['path'] === '') {
            throw new ValidationError('ต้องระบุไฟล์ที่จะอ่าน');
        }

        return $base;
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

        $result = $this->withPath($executor, $scope, $relative, static function (string $root, string $target) use ($executor, $name): array {
            $info = $executor->stat($target);
            if ($info === null || $info['type'] !== 'file') {
                throw new ValidationError('อ่านได้เฉพาะไฟล์ธรรมดาเท่านั้น');
            }

            if (!FileCatalog::isEditable($name, $info['size'])) {
                throw new ValidationError(
                    $info['size'] > FileCatalog::MAX_EDIT_BYTES
                        ? 'ไฟล์ใหญ่เกินกว่าจะเปิดในตัวแก้ไข (เกิน 5 MB)'
                        : 'ไฟล์ชนิดนี้เปิดในตัวแก้ไขข้อความไม่ได้',
                );
            }

            $content = $executor->readFile($target);

            // ไฟล์ที่นามสกุลบอกว่าเป็นข้อความแต่เนื้อในเป็นไบนารี (เช่นถูกเขียนทับ)
            // ต้องถูกปฏิเสธ ไม่อย่างนั้นเบราว์เซอร์จะได้ JSON ที่ encode ไม่ผ่าน
            if (!mb_check_encoding($content, 'UTF-8')) {
                throw new ValidationError('ไฟล์นี้ไม่ใช่ข้อความ UTF-8 จึงแก้ไขไม่ได้');
            }

            return [
                'content' => $content,
                'size' => $info['size'],
                'mode' => sprintf('%04o', $info['mode']),
                'mtime' => $info['mtime']
            ];
        });

        return [
            'root' => $scope->key,
            'site_id' => $scope->siteId,
            'path' => $relative,
            'name' => $name,
            'syntax' => FileCatalog::syntax($name),
            ...$result
        ];
    }
}
