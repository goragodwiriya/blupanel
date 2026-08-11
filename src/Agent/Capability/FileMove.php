<?php

declare (strict_types = 1);

namespace Phpcp\Agent\Capability;

use Phpcp\Agent\Context;
use Phpcp\Agent\Executor\Executor;
use Phpcp\Agent\ValidationError;
use Phpcp\Support\PathGuard;
use Phpcp\Support\Validator;

/**
 * ย้าย เปลี่ยนชื่อ หรือคัดลอกไฟล์และโฟลเดอร์
 *
 * สามงานนี้รวมเป็น capability เดียวเพราะมีกติกาความปลอดภัยชุดเดียวกันทุกข้อ:
 * ทั้งต้นทางและปลายทางต้องอยู่ในบ้านของเว็บไซต์เดียวกัน แยกเป็นสามตัวจะทำให้
 * กติกาถูกคัดลอกไปสามที่แล้วแก้ไม่ครบในภายหลัง
 */
final class FileMove extends FileCapability
{
    /** จำนวนรายการสูงสุดต่อหนึ่งคำสั่ง — กันคำขอที่ทำให้ agent ทำงานยาวเกินไป */
    private const MAX_ITEMS = 100;

    public static function name(): string
    {
        return 'file.move';
    }

    public function isMutating(): bool
    {
        return true;
    }

    public function summary(): string
    {
        return 'ย้าย เปลี่ยนชื่อ หรือคัดลอกไฟล์';
    }

    /**
     * @param array $args
     */
    public function validate(array $args): array
    {
        $sources = Validator::requireStringList($args, 'items', self::MAX_ITEMS, 4096);

        $clean = [];
        foreach ($sources as $source) {
            $relative = PathGuard::clean($source, 'เส้นทางต้นทาง');
            if ($relative === '') {
                throw new ValidationError('ย้ายหรือคัดลอกรากของขอบเขตไม่ได้');
            }

            $clean[] = $relative;
        }

        if ($clean === []) {
            throw new ValidationError('ต้องเลือกอย่างน้อยหนึ่งรายการก่อน');
        }

        return [
            'root' => Validator::pattern(
                Validator::requireString($args, 'root', 64),
                '/^[a-z][a-z0-9-]{0,63}$/',
                'คีย์ขอบเขตไฟล์ไม่ถูกต้อง',
            ),
            'items' => $clean,
            'destination' => PathGuard::clean(Validator::optionalString($args, 'destination', max: 4096), 'โฟลเดอร์ปลายทาง'),
            // เปลี่ยนชื่อ = ย้ายหนึ่งรายการพร้อมตั้งชื่อใหม่ ใช้ได้กับรายการเดียวเท่านั้น
            'rename' => isset($args['rename']) && $args['rename'] !== ''
                ? PathGuard::name((string) $args['rename'], 'ชื่อใหม่')
                : '',
            'copy' => self::flag($args, 'copy'),
            'overwrite' => self::flag($args, 'overwrite')
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
        $destination = $args['destination'];
        $rename = $args['rename'];
        $copy = $args['copy'];
        $overwrite = $args['overwrite'];

        if ($rename !== '' && count($items) !== 1) {
            throw new ValidationError('เปลี่ยนชื่อได้ครั้งละหนึ่งรายการเท่านั้น');
        }

        return $this->withSite($executor, $scope, static function (callable $resolve) use (
            $executor,
            $items,
            $destination,
            $rename,
            $copy,
            $overwrite,
            $scope,
        ): array {
            $realDestination = $resolve($destination);
            if (($executor->stat($realDestination)['type'] ?? '') !== 'dir') {
                throw new ValidationError('ปลายทางต้องเป็นโฟลเดอร์');
            }

            $done = [];

            foreach ($items as $relative) {
                $from = $resolve($relative);
                $name = $rename !== '' ? $rename : basename($relative);
                $to = $realDestination.'/'.$name;

                if ($from === $to) {
                    continue; // ปลายทางเดียวกับต้นทาง = ไม่ต้องทำอะไร ไม่ใช่ข้อผิดพลาด
                }

                // ย้ายโฟลเดอร์เข้าไปในตัวเองทำให้ต้นไม้ขาดจากระบบไฟล์และกู้คืนยาก
                // rename() ของเคอร์เนลกันเคสนี้ให้ แต่ copy จะวนไม่รู้จบจนดิสก์เต็ม
                if (str_starts_with($realDestination.'/', $from.'/')) {
                    throw new ValidationError('ย้ายโฟลเดอร์เข้าไปในตัวเองไม่ได้: '.basename($relative));
                }

                if ($executor->stat($to) !== null) {
                    if (!$overwrite) {
                        throw new ValidationError('มี '.$name.' อยู่ที่ปลายทางแล้ว');
                    }

                    $executor->removePath($to);
                }

                if ($copy) {
                    $executor->copyPath($from, $to);
                } else {
                    $executor->rename($from, $to);
                }

                $done[] = $name;
            }

            return [
                'root' => $scope->key,
            'site_id' => $scope->siteId,
                'moved' => $done,
                'count' => count($done),
                'destination' => $destination,
                'message' => sprintf('%s %d รายการแล้ว', $copy ? 'คัดลอก' : 'ย้าย', count($done))
            ];
        });
    }
}
