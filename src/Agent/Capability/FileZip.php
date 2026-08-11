<?php

declare (strict_types = 1);

namespace Phpcp\Agent\Capability;

use Phpcp\Agent\Context;
use Phpcp\Agent\Executor\Executor;
use Phpcp\Agent\ValidationError;
use Phpcp\Support\PathGuard;
use Phpcp\Support\Validator;

/**
 * บีบอัดไฟล์และโฟลเดอร์ที่เลือกเป็นไฟล์ zip
 *
 * ไฟล์ผลลัพธ์ถูกสร้างในโฟลเดอร์ที่ผู้ใช้เปิดอยู่ ไม่ใช่ในที่ชั่วคราวของระบบ —
 * ผู้ใช้เห็นผลลัพธ์ทันทีและพื้นที่ที่ใช้ถูกนับรวมในโควตาของเว็บไซต์นั้นตามปกติ
 */
final class FileZip extends FileCapability
{
    private const MAX_ITEMS = 100;

    public static function name(): string
    {
        return 'file.zip';
    }

    public function isMutating(): bool
    {
        return true;
    }

    public function summary(): string
    {
        return 'บีบอัดไฟล์เป็น zip';
    }

    /**
     * @param array $args
     */
    public function validate(array $args): array
    {
        $items = [];
        foreach (Validator::requireStringList($args, 'items', self::MAX_ITEMS, 4096) as $item) {
            $relative = PathGuard::clean($item, 'เส้นทางที่จะบีบอัด');
            if ($relative === '') {
                throw new ValidationError('เลือกไฟล์หรือโฟลเดอร์ที่จะบีบอัดก่อน');
            }

            $items[] = $relative;
        }

        if ($items === []) {
            throw new ValidationError('เลือกไฟล์หรือโฟลเดอร์ที่จะบีบอัดก่อน');
        }

        $name = PathGuard::name(Validator::requireString($args, 'archive', 200), 'ชื่อไฟล์บีบอัด');
        if (!str_ends_with(mb_strtolower($name), '.zip')) {
            $name .= '.zip';
        }

        return [
            'root' => Validator::pattern(
                Validator::requireString($args, 'root', 64),
                '/^[a-z][a-z0-9-]{0,63}$/',
                'คีย์ขอบเขตไฟล์ไม่ถูกต้อง',
            ),
            'path' => PathGuard::clean(Validator::optionalString($args, 'path', max: 4096)),
            'items' => $items,
            'archive' => $name
        ];
    }

    /**
     * @param array $args
     * @param Executor $executor
     * @param Context $context
     * @return mixed
     */
    public function run(array $args, Executor $executor, Context $context): array
    {
        $scope = $this->scope($context, $args);
        $items = $args['items'];
        $folder = $args['path'];
        $archive = PathGuard::join($folder, $args['archive']);

        $result = $this->withSite($executor, $scope, static function (callable $resolve) use ($executor, $items, $folder, $archive): array {
            $target = $resolve($archive, false);
            if ($executor->stat($target) !== null) {
                throw new ValidationError('มีไฟล์ชื่อนี้อยู่แล้ว');
            }

            // ชื่อในไฟล์บีบอัดอ้างอิงจากโฟลเดอร์ที่ผู้ใช้เปิดอยู่ ไม่ใช่จากรากของเว็บไซต์ —
            // แตกไฟล์ออกมาจึงได้โครงสร้างเหมือนที่เห็นบนหน้าจอ ไม่มีชั้นโฟลเดอร์แปลกปลอม
            $summary = $executor->zip(
                array_map(static fn(string $relative): string => $resolve($relative), $items),
                $resolve($folder),
                $target,
            );

            $executor->changeMode($target, 0o640);

            return $summary;
        });

        return [
            'root' => $scope->key,
            'site_id' => $scope->siteId,
            'archive' => $archive,
            'entries' => $result['entries'],
            'bytes' => $result['bytes'],
            'message' => sprintf('บีบอัด %d รายการเป็น %s แล้ว', $result['entries'], $args['archive'])
        ];
    }
}
