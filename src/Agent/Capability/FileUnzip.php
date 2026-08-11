<?php

declare (strict_types = 1);

namespace Phpcp\Agent\Capability;

use Phpcp\Agent\Context;
use Phpcp\Agent\Executor\Executor;
use Phpcp\Agent\ValidationError;
use Phpcp\Support\PathGuard;
use Phpcp\Support\Validator;

/**
 * แตกไฟล์ zip ลงในโฟลเดอร์
 *
 * การกัน Zip Slip อยู่ที่ชั้น executor ไม่ใช่ที่นี่ — ชื่อรายการในไฟล์ zip
 * ไม่เคยผ่านมือ capability เลย จึงไม่มีทางที่โค้ดตรงนี้จะพลาดตรวจ
 * รายการที่อันตรายถูกข้ามและนับไว้ใน skipped เพื่อรายงานให้ผู้ใช้รู้
 */
final class FileUnzip extends FileCapability
{
    public static function name(): string
    {
        return 'file.unzip';
    }

    public function isMutating(): bool
    {
        return true;
    }

    public function summary(): string
    {
        return 'แตกไฟล์ zip';
    }

    /**
     * @param array $args
     * @return mixed
     */
    public function validate(array $args): array
    {
        $base = self::baseArgs($args);

        if ($base['path'] === '') {
            throw new ValidationError('ต้องระบุไฟล์ zip ที่จะแตก');
        }
        if (!str_ends_with(mb_strtolower($base['path']), '.zip')) {
            throw new ValidationError('รองรับเฉพาะไฟล์นามสกุล .zip');
        }

        return $base + [
            // ค่าว่างหมายถึงแตกลงโฟลเดอร์เดียวกับที่ไฟล์ zip อยู่
            'destination' => Validator::optionalString($args, 'destination') !== ''
                ? PathGuard::name((string) $args['destination'], 'ชื่อโฟลเดอร์ปลายทาง')
                : ''
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
        $archive = $args['path'];
        $folder = PathGuard::parent($archive);
        $destination = $args['destination'] === ''
            ? $folder
            : PathGuard::join($folder, $args['destination']);

        $result = $this->withSite($executor, $scope, static function (callable $resolve) use ($executor, $archive, $destination): array {
            $source = $resolve($archive);
            if (($executor->stat($source)['type'] ?? '') !== 'file') {
                throw new ValidationError('ไม่พบไฟล์ zip ที่ระบุ');
            }

            // สร้างโฟลเดอร์ปลายทางก่อนแล้วค่อย resolve ซ้ำ — resolve ต้องได้เส้นทาง
            // ที่คลาย symlink แล้วจริง ๆ ซึ่งทำได้ต่อเมื่อโฟลเดอร์มีอยู่บนดิสก์แล้ว
            $prepared = $resolve($destination, false);
            if ($executor->stat($prepared) === null) {
                $executor->makeDirectory($prepared, 0o750);
            }

            return $executor->unzip($source, $resolve($destination));
        });

        $message = sprintf('แตกไฟล์ %d รายการแล้ว', $result['entries']);
        if ($result['skipped'] > 0) {
            $message .= sprintf(' (ข้าม %d รายการที่ไม่ปลอดภัย)', $result['skipped']);
        }

        return [
            'root' => $scope->key,
            'site_id' => $scope->siteId,
            'archive' => $archive,
            'destination' => $destination,
            'entries' => $result['entries'],
            'skipped' => $result['skipped'],
            'bytes' => $result['bytes'],
            'message' => $message
        ];
    }
}
