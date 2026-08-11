<?php

declare (strict_types = 1);

namespace Phpcp\Agent\Capability;

use Phpcp\Agent\Context;
use Phpcp\Agent\Executor\Executor;
use Phpcp\Agent\ValidationError;
use Phpcp\Domain\FileCatalog;

/**
 * ส่งเนื้อไฟล์กลับไปให้ผู้ใช้ดาวน์โหลด
 *
 * เข้ารหัส base64 ก่อนส่งเพราะโปรโตคอลเป็น JSON — ไฟล์จึงใหญ่ได้ไม่เกิน
 * FileCatalog::MAX_TRANSFER_BYTES ไฟล์ที่ใหญ่กว่านั้นให้บีบอัดเป็น zip
 * หรือดึงผ่าน SFTP แทน การทำ chunked transfer ผ่านโปรโตคอลบรรทัดเดียว
 * จะซับซ้อนเกินกว่าที่ประโยชน์คุ้ม
 */
final class FileDownload extends FileCapability
{
    public static function name(): string
    {
        return 'file.download';
    }

    public function isMutating(): bool
    {
        return false;
    }

    public function summary(): string
    {
        return 'ดาวน์โหลดไฟล์';
    }

    /**
     * @param array $args
     * @return mixed
     */
    public function validate(array $args): array
    {
        $base = self::baseArgs($args);

        if ($base['path'] === '') {
            throw new ValidationError('ต้องระบุไฟล์ที่จะดาวน์โหลด');
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

        $result = $this->withPath($executor, $scope, $relative, static function (string $root, string $target) use ($executor): array {
            $info = $executor->stat($target);

            if ($info === null || $info['type'] !== 'file') {
                throw new ValidationError('ดาวน์โหลดได้เฉพาะไฟล์ธรรมดา (โฟลเดอร์ให้บีบอัดเป็น zip ก่อน)');
            }
            if ($info['size'] > FileCatalog::MAX_TRANSFER_BYTES) {
                throw new ValidationError(sprintf(
                    'ไฟล์ใหญ่เกิน %d MB ให้บีบอัดเป็น zip ก่อนดาวน์โหลด',
                    (int) (FileCatalog::MAX_TRANSFER_BYTES / 1048576),
                ));
            }

            return [
                'content' => base64_encode($executor->readFile($target)),
                'size' => $info['size']
            ];
        });

        return [
            'root' => $scope->key,
            'site_id' => $scope->siteId,
            'path' => $relative,
            'name' => basename($relative),
            'size' => $result['size'],
            'content' => $result['content']
        ];
    }
}
