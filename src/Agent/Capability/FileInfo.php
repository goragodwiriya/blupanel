<?php

declare (strict_types = 1);

namespace Phpcp\Agent\Capability;

use Phpcp\Agent\Context;
use Phpcp\Agent\Executor\Executor;
use Phpcp\Agent\ValidationError;
use Phpcp\Domain\FileCatalog;
use Phpcp\Support\PathGuard;

/**
 * ข้อมูลของไฟล์หรือโฟลเดอร์เดียว — กล่อง "คุณสมบัติ" ของตัวจัดการไฟล์
 *
 * ข้อมูลส่วนใหญ่ซ้ำกับที่ `file.list` ส่งมาในแถวอยู่แล้ว แต่ยังต้องมี endpoint นี้
 * เพราะสองกรณีที่แถวตอบไม่ได้: เปิดคุณสมบัติจาก**ผลการค้นหา**ซึ่งข้ามโฟลเดอร์มา
 * และดูของสิ่งที่เพิ่งถูกแก้โดยคนอื่นหลังจากตารางโหลดไปแล้ว
 *
 * **ไม่คำนวณขนาดรวมของโฟลเดอร์** — ต้องไล่ทั้งต้นไม้ซึ่งบนขอบเขต `server` แปลว่า
 * ไล่ทั้งดิสก์ต่อการกดหนึ่งครั้ง · บอกจำนวนรายการชั้นบนสุดแทน ซึ่งได้จากการอ่าน
 * ไดเรกทอรีครั้งเดียวและเป็นสิ่งที่ผู้ใช้อยากรู้จริง ๆ ตอนจะลบหรือย้าย
 */
final class FileInfo extends FileCapability
{
    public static function name(): string
    {
        return 'file.info';
    }

    public function isMutating(): bool
    {
        return false;
    }

    public function summary(): string
    {
        return 'อ่านข้อมูลไฟล์';
    }

    /**
     * @param array $args
     */
    public function validate(array $args): array
    {
        $base = self::baseArgs($args);

        if ($base['path'] === '') {
            throw new ValidationError('ต้องระบุไฟล์หรือโฟลเดอร์');
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

        $result = $this->withPath($executor, $scope, $relative, static function (string $root, string $target) use ($executor, $relative, $name): array {
            $info = $executor->stat($target);
            if ($info === null) {
                throw new ValidationError('ไม่พบไฟล์หรือโฟลเดอร์ที่ระบุ');
            }

            $described = self::describe($name, $relative, $info);
            $described['kind'] = $info['type'] === 'dir' ? 'folder' : FileCatalog::kind($name);
            $described['editable'] = $info['type'] === 'file' && FileCatalog::isEditable($name, $info['size']);
            $described['syntax'] = FileCatalog::syntax($name);
            $described['parent'] = PathGuard::parent($relative);

            if ($info['type'] === 'dir') {
                // อ่านครั้งเดียวพอ · `listDirectory` มีเพดานของมันเองอยู่แล้ว
                // จำนวนที่ได้จึงเป็น "อย่างน้อยเท่านี้" เมื่อโฟลเดอร์ใหญ่กว่าเพดาน
                try {
                    $described['entries'] = count($executor->listDirectory($target));
                } catch (\Throwable) {
                    $described['entries'] = null;
                }

                $described['size'] = null;
            }

            return $described;
        });

        return [
            'root' => $scope->key,
            'site_id' => $scope->siteId,
            ...$result
        ];
    }
}
