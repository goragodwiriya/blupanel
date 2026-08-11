<?php

declare (strict_types = 1);

namespace Phpcp\Agent\Capability;

use Phpcp\Agent\Context;
use Phpcp\Agent\Executor\Executor;
use Phpcp\Agent\ValidationError;
use Phpcp\Domain\FileCatalog;
use Phpcp\Support\PathGuard;
use Phpcp\Support\Validator;

/**
 * รับไฟล์ที่ผู้ใช้อัปโหลด
 *
 * เนื้อไฟล์เดินทางมาเป็น base64 เพราะโปรโตคอลของ agent เป็น JSON บรรทัดเดียว
 * ซึ่งรับไบต์ดิบไม่ได้ base64 ขยายข้อมูล 4/3 เท่า ขนาดที่รับจึงถูกจำกัดไว้
 * ต่ำกว่า Protocol::MAX_FRAME พอสมควร (FileCatalog::MAX_TRANSFER_BYTES)
 *
 * ชื่อไฟล์ที่ผู้ใช้ส่งมาไม่เคยถูกเชื่อ — ตัดเหลือแต่ basename แล้วตรวจด้วย PathGuard
 * ก่อนนำไปต่อกับเส้นทางใด ๆ
 */
final class FileUpload extends FileCapability
{
    public static function name(): string
    {
        return 'file.upload';
    }

    public function isMutating(): bool
    {
        return true;
    }

    public function summary(): string
    {
        return 'อัปโหลดไฟล์เข้าเว็บไซต์';
    }

    /**
     * @param array $args
     * @return mixed
     */
    public function validate(array $args): array
    {
        $base = self::baseArgs($args);

        // basename ก่อนตรวจ: เบราว์เซอร์บางตัวส่งเส้นทางเต็มของเครื่องผู้ใช้มาให้
        // และผู้โจมตีอาจส่ง '../../etc/cron.d/x' มาตรง ๆ
        $name = PathGuard::name(basename(Validator::requireString($args, 'name', 255)), 'ชื่อไฟล์');

        $encoded = $args['content'] ?? '';
        if (!is_string($encoded)) {
            throw new ValidationError('เนื้อไฟล์ไม่ถูกต้อง');
        }

        $content = base64_decode($encoded, true);
        if ($content === false) {
            throw new ValidationError('ถอดรหัสเนื้อไฟล์ไม่สำเร็จ');
        }
        if (strlen($content) > FileCatalog::MAX_TRANSFER_BYTES) {
            throw new ValidationError('ไฟล์ใหญ่เกิน '.(int) (FileCatalog::MAX_TRANSFER_BYTES / 1048576).' MB');
        }

        return $base + [
            'name' => $name,
            'content' => $content,
            'overwrite' => self::flag($args, 'overwrite')
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
        $content = $args['content'];
        $overwrite = $args['overwrite'];

        $this->withPath(
            $executor,
            $scope,
            $relative,
            static function (string $root, string $target) use ($executor, $content, $overwrite): array {
                $existing = $executor->stat($target);

                if ($existing !== null) {
                    if (!$overwrite) {
                        throw new ValidationError('มีไฟล์ชื่อนี้อยู่แล้ว');
                    }
                    if ($existing['type'] !== 'file') {
                        throw new ValidationError('ปลายทางไม่ใช่ไฟล์ธรรมดา');
                    }
                }

                // 0640 ไม่ใช่ 0644: ไฟล์ที่อัปโหลดอาจมีข้อมูลลับ ผู้ใช้อื่นบนเครื่องไม่ควรอ่านได้
                // และไม่ตั้ง execute bit เด็ดขาด แม้ไฟล์จะเป็นสคริปต์ก็ตาม
                $executor->writeFile($target, $content, 0o640);

                return [];
            },
            mustExist: false,
        );

        return [
            'root' => $scope->key,
            'site_id' => $scope->siteId,
            'path' => $relative,
            'name' => $args['name'],
            'size' => strlen($content),
            'message' => 'อัปโหลด '.$args['name'].' แล้ว'
        ];
    }
}
