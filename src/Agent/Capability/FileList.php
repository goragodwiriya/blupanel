<?php

declare (strict_types = 1);

namespace Phpcp\Agent\Capability;

use Phpcp\Agent\Context;
use Phpcp\Agent\Executor\Executor;
use Phpcp\Domain\FileCatalog;
use Phpcp\Support\PathGuard;
use Phpcp\Support\Validator;

/**
 * อ่านรายการไฟล์ในโฟลเดอร์เดียว — ไม่ลงลึกในโฟลเดอร์ย่อย
 *
 * ไม่ลงลึกโดยตั้งใจ: การไล่ทั้งต้นไม้ทำให้เว็บไซต์ที่มี node_modules
 * ค้างไปเป็นนาทีและส่งข้อมูลเกินขนาด frame ที่โปรโตคอลรับได้
 *
 * ตัดหน้าที่นี่ (agent) ด้วยเหตุผลเดียวกัน — โฟลเดอร์แบนที่มีไฟล์หลักหมื่น
 * (เช่นแคชหรือ log ที่ไม่เคยเคลียร์) ส่งทีเดียวหมดจะชน MAX_FRAME ของโปรโตคอล
 * เหมือนกับกรณีลงลึกทั้งต้นไม้ จึงต้องตัดก่อนที่จะประกอบเป็นข้อความส่งออกจาก agent
 * ไม่ใช่ตัดที่ชั้นเว็บซึ่งได้รับข้อมูลที่เกินมาแล้ว
 */
final class FileList extends FileCapability
{
    private const DEFAULT_PER_PAGE = 100;
    private const MAX_PER_PAGE = 500;

    public static function name(): string
    {
        return 'file.list';
    }

    public function isMutating(): bool
    {
        return false;
    }

    public function summary(): string
    {
        return 'อ่านรายการไฟล์ในโฟลเดอร์';
    }

    /**
     * @param array $args
     */
    public function validate(array $args): array
    {
        return self::baseArgs($args) + [
            'page' => Validator::optionalInt($args, 'page', 1, min: 1, max: 1_000_000),
            'per_page' => Validator::optionalInt(
                $args,
                'per_page',
                self::DEFAULT_PER_PAGE,
                min: 1,
                max: self::MAX_PER_PAGE,
            ),
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

        $result = $this->withPath($executor, $scope, $relative, static function (string $root, string $target) use ($executor, $relative, $scope): array {
            $info = $executor->stat($target);
            if ($info === null || $info['type'] !== 'dir') {
                throw new \Phpcp\Agent\ValidationError('เส้นทางนี้ไม่ใช่โฟลเดอร์');
            }

            $entries = [];
            foreach ($executor->listDirectory($target) as $entry) {
                $childPath = $relative === '' ? $entry['name'] : $relative.'/'.$entry['name'];
                $described = self::describe($entry['name'], $childPath, $entry);

                $described['editable'] = $entry['type'] === 'file'
                && FileCatalog::isEditable($entry['name'], $entry['size']);
                $described['kind'] = $entry['type'] === 'dir' ? 'folder' : FileCatalog::kind($entry['name']);
                // ขนาดของ inode โฟลเดอร์ (มักเป็น 4096) ไม่ได้บอกอะไรผู้ใช้ — null ให้ตัว
                // จัดรูปแบบมาตรฐานของตาราง (data-format="bytes" + data-empty-text) แสดง "—"
                // ให้เอง แทนที่จะต้องมีตัวจัดรูปแบบเฉพาะกิจฝั่งหน้าจอ
                if ($entry['type'] === 'dir') {
                    $described['size'] = null;
                }
                // แต่ละแถวพกชื่อขอบเขตของตัวเองมาด้วย เพื่อให้ผลลัพธ์หนึ่งแถวประกอบ URL
                // ดาวน์โหลด/แก้ไขได้จากข้อมูลในแถวล้วน ๆ ไม่ต้องพึ่งค่าที่เลือกไว้นอกแถว
                $described['root'] = $scope->key;

                $entries[] = $described;
            }

            // โฟลเดอร์ขึ้นก่อนเสมอ แล้วเรียงตามชื่อแบบไม่สนตัวพิมพ์ —
            // เรียงที่ agent ไม่ใช่ที่เบราว์เซอร์ เพื่อให้ผลลัพธ์เหมือนกันทุกเครื่อง
            usort($entries, static function (array $a, array $b): int {
                $rank = static fn(array $e): int => $e['type'] === 'dir' ? 0 : 1;

                return [$rank($a), mb_strtolower($a['name'])] <=> [$rank($b), mb_strtolower($b['name'])];
            });

            return [
                'entries' => $entries,
                'disk' => $executor->diskSpace($root)
            ];
        });

        $total = count($result['entries']);
        $perPage = $args['per_page'];
        $pages = max(1, (int) ceil($total / $perPage));
        // เกินหน้าสุดท้ายได้ง่าย ๆ ถ้าไฟล์ถูกลบไประหว่างที่ผู้ใช้เปิดหน้าค้างไว้ — เลื่อนกลับมาหน้าสุดท้ายแทนที่จะคืนรายการว่าง
        $page = min($args['page'], $pages);

        return [
            'root' => $scope->key,
            'site_id' => $scope->siteId,
            'scope' => $scope->label,
            'path' => $relative,
            'parent' => $relative === '' ? null : PathGuard::parent($relative),
            'breadcrumb' => self::breadcrumb($relative),
            'entries' => array_slice($result['entries'], ($page - 1) * $perPage, $perPage),
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'pages' => $pages,
            'disk' => $result['disk']
        ];
    }

    /**
     * แตกเส้นทางเป็นชิ้นสำหรับแถบนำทาง
     *
     * @return list<array{label:string,path:string}>
     */
    private static function breadcrumb(string $relative): array
    {
        $crumbs = [['label' => 'หน้าแรก', 'path' => '']];

        if ($relative === '') {
            return $crumbs;
        }

        $walked = '';
        foreach (explode('/', $relative) as $segment) {
            $walked = $walked === '' ? $segment : $walked.'/'.$segment;
            $crumbs[] = ['label' => $segment, 'path' => $walked];
        }

        return $crumbs;
    }
}
