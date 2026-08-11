<?php

declare (strict_types = 1);

namespace Phpcp\Agent\Capability;

use Phpcp\Agent\Context;
use Phpcp\Agent\Executor\Executor;
use Phpcp\Agent\ValidationError;
use Phpcp\Support\Validator;

/**
 * โครงต้นไม้ของ**โฟลเดอร์เท่านั้น** สำหรับแถบนำทางด้านซ้ายของตัวจัดการไฟล์
 *
 * แยกจาก `file.list` เพราะเป็นคนละคำถาม: `file.list` ตอบ "ในโฟลเดอร์นี้มีอะไรบ้าง"
 * (ไฟล์ด้วย ทีละชั้น) ส่วนอันนี้ตอบ "โครงสร้างโฟลเดอร์รอบ ๆ ตรงนี้เป็นอย่างไร"
 * ถ้าใช้ `file.list` วาดต้นไม้ ต้องยิงคำขอหนึ่งครั้งต่อหนึ่งโฟลเดอร์และได้ข้อมูลไฟล์
 * ที่ไม่ได้ใช้ติดมาทุกครั้ง
 *
 * **สองเพดานที่ขาดไม่ได้** — เครื่องจริงมีโฟลเดอร์อย่าง `node_modules` และ `/proc`
 * ที่ลึกและกว้างจนไล่ทั้งหมดแล้วค้างเป็นนาที:
 *
 *   `depth`     ไม่เกิน 3 ชั้น · ค่าปริยาย 1 ชั้นเพราะแถบนำทางขยายทีละชั้นตามที่ผู้ใช้กด
 *   MAX_NODES   เพดานจำนวนโฟลเดอร์รวมทั้งคำตอบ · ตัดที่ agent เหมือน `file.list`
 *               เพราะข้อความที่โตเกิน MAX_FRAME ของโปรโตคอลจะส่งไม่ออกตั้งแต่ต้นทาง
 *
 * ไม่ลงไปใน symlink เด็ดขาด — `listDirectory` ใช้ lstat ลิงก์จึงมาเป็นชนิด `link`
 * ไม่ใช่ `dir` และถูกข้ามไปพร้อมกับไฟล์ · นี่คือสิ่งที่ทำให้การไล่ต้นไม้ออกนอกขอบเขต
 * ไม่ได้แม้จะเรียก `resolve()` แค่ที่จุดตั้งต้นจุดเดียว
 */
final class FileTree extends FileCapability
{
    /** เพดานจำนวนโฟลเดอร์ต่อหนึ่งคำตอบ */
    private const MAX_NODES = 1500;

    /** ชั้นลึกสุดที่ยอมไล่ในคำขอเดียว */
    private const MAX_DEPTH = 3;

    public static function name(): string
    {
        return 'file.tree';
    }

    public function isMutating(): bool
    {
        return false;
    }

    public function summary(): string
    {
        return 'อ่านโครงโฟลเดอร์';
    }

    /**
     * @param array $args
     */
    public function validate(array $args): array
    {
        return self::baseArgs($args) + [
            'depth' => Validator::optionalInt($args, 'depth', 1, min: 1, max: self::MAX_DEPTH)
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
        $depth = $args['depth'];

        $result = $this->withPath($executor, $scope, $relative, static function (string $root, string $target) use ($executor, $relative, $depth): array {
            $info = $executor->stat($target);
            if ($info === null || $info['type'] !== 'dir') {
                throw new ValidationError('เส้นทางนี้ไม่ใช่โฟลเดอร์');
            }

            $budget = self::MAX_NODES;
            $nodes = self::walk($executor, $target, $relative, $depth, $budget);

            return ['nodes' => $nodes, 'truncated' => $budget <= 0];
        });

        return [
            'root' => $scope->key,
            'site_id' => $scope->siteId,
            'path' => $relative,
            'depth' => $depth,
            ...$result
        ];
    }

    /**
     * ไล่โฟลเดอร์ลงไปทีละชั้นจนหมด `$depth` หรือหมดโควตา
     *
     * `$budget` ส่งเป็น reference เพื่อให้ทุกกิ่งใช้โควตาก้อนเดียวกัน — ถ้าแยกโควตา
     * ต่อกิ่ง โฟลเดอร์ที่มีลูกหลักพันกิ่งเดียวก็ยังทำให้คำตอบใหญ่เกินได้
     *
     * @return list<array<string,mixed>>
     */
    private static function walk(
        Executor $executor,
        string $absolute,
        string $relative,
        int $depth,
        int &$budget,
    ): array {
        $nodes = [];

        foreach (self::directories($executor, $absolute) as $entry) {
            if ($budget <= 0) {
                break;
            }

            $budget--;

            $childRelative = $relative === '' ? $entry['name'] : $relative.'/'.$entry['name'];
            $childAbsolute = $absolute.'/'.$entry['name'];

            // ชั้นสุดท้ายไม่ไล่ต่อ แต่ยัง**ต้องรู้ว่ามีลูกไหม** ไม่งั้นแถบนำทางจะขึ้นลูกศร
            // ให้ทุกโฟลเดอร์ แล้วผู้ใช้กดแล้วเจอที่ว่างเปล่า · การถามหนึ่งครั้งต่อโฟลเดอร์
            // ถูกจำกัดด้วย $budget ก้อนเดียวกันอยู่แล้ว
            $children = $depth > 1
                ? self::walk($executor, $childAbsolute, $childRelative, $depth - 1, $budget)
                : [];

            $nodes[] = [
                'name' => $entry['name'],
                'path' => $childRelative,
                'mtime' => $entry['mtime'],
                'has_children' => $depth > 1 ? $children !== [] : self::hasSubdirectory($executor, $childAbsolute),
                'children' => $children
            ];
        }

        usort($nodes, static fn(array $a, array $b): int => mb_strtolower($a['name']) <=> mb_strtolower($b['name']));

        return $nodes;
    }

    /**
     * รายการย่อยที่เป็นโฟลเดอร์จริง — โฟลเดอร์ที่เปิดไม่ได้ถือว่าว่าง ไม่ใช่ข้อผิดพลาด
     *
     * ผู้ใช้ที่เห็น `/etc` ย่อมมีโฟลเดอร์ที่ตัวเองเข้าไม่ถึงปนอยู่เสมอ การโยนข้อผิดพลาด
     * จะทำให้ทั้งแถบนำทางว่างเพราะโฟลเดอร์เดียวที่แตะไม่ได้
     *
     * @return list<array{name:string,type:string,mtime:int}>
     */
    private static function directories(Executor $executor, string $absolute): array
    {
        try {
            $entries = $executor->listDirectory($absolute);
        } catch (\Throwable) {
            return [];
        }

        return array_values(array_filter(
            $entries,
            static fn(array $entry): bool => $entry['type'] === 'dir',
        ));
    }

    private static function hasSubdirectory(Executor $executor, string $absolute): bool
    {
        return self::directories($executor, $absolute) !== [];
    }
}
