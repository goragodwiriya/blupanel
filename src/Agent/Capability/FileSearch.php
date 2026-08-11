<?php

declare (strict_types = 1);

namespace Phpcp\Agent\Capability;

use Phpcp\Agent\Context;
use Phpcp\Agent\Executor\Executor;
use Phpcp\Agent\ValidationError;
use Phpcp\Domain\FileCatalog;
use Phpcp\Support\Validator;

/**
 * ค้นหาไฟล์และโฟลเดอร์ตามชื่อ ใต้โฟลเดอร์ที่กำลังเปิดอยู่
 *
 * **เทียบจากชื่อเท่านั้น ไม่เปิดอ่านเนื้อไฟล์** — การค้นในเนื้อไฟล์ต้องอ่านทุกไบต์
 * ของทุกไฟล์ในทุกคำค้น ซึ่งบนขอบเขต `server` (ราก `/`) แปลว่าอ่านทั้งดิสก์
 *
 * **สี่เพดานที่ทำงานพร้อมกัน** เพราะแต่ละอันกันคนละกรณีที่เจอจริงบนเซิร์ฟเวอร์:
 *
 *   MAX_RESULTS   คำตอบไม่โตเกิน frame ของโปรโตคอล
 *   MAX_VISITED   โฟลเดอร์ที่มีไฟล์หลักแสน (แคช, log ที่ไม่เคยเคลียร์) ไม่ทำให้ค้างยาว
 *   MAX_DEPTH     กันต้นไม้ที่ลึกผิดปกติ
 *   TIME_LIMIT    ดิสก์ที่ตอบช้า (NFS, ดิสก์ใกล้พัง) ไม่ทำให้คำขอค้างจนหมดเวลาฝั่งเว็บ
 *
 * ไม่เดินตาม symlink ด้วยเหตุผลเดียวกับ `file.tree` — ทั้งเพื่อไม่ให้ออกนอกขอบเขต
 * และเพื่อไม่ให้ลิงก์วนกลับมาหาตัวเองแล้วไล่ไม่รู้จบ
 */
final class FileSearch extends FileCapability
{
    private const MIN_QUERY = 2;
    private const MAX_RESULTS = 200;
    private const MAX_VISITED = 20000;
    private const MAX_DEPTH = 12;
    private const TIME_LIMIT_SECONDS = 5.0;

    public static function name(): string
    {
        return 'file.search';
    }

    public function isMutating(): bool
    {
        return false;
    }

    public function summary(): string
    {
        return 'ค้นหาไฟล์ตามชื่อ';
    }

    /**
     * @param array $args
     */
    public function validate(array $args): array
    {
        $query = trim(Validator::requireString($args, 'q', 100));

        if (mb_strlen($query) < self::MIN_QUERY) {
            throw new ValidationError('คำค้นต้องยาวอย่างน้อย '.self::MIN_QUERY.' ตัวอักษร');
        }

        // อักขระควบคุมในคำค้นไม่มีทางตรงกับชื่อไฟล์ที่ระบบยอมให้สร้าง (PathGuard ห้ามไว้)
        // มีแต่จะไปโผล่ใน log แล้วปลอมตัวเป็นบรรทัดอื่น
        if (preg_match('/[\x00-\x1f\x7f]/', $query) === 1) {
            throw new ValidationError('คำค้นมีอักขระควบคุมที่ไม่อนุญาต');
        }

        return self::baseArgs($args) + ['q' => $query];
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
        $query = $args['q'];

        $result = $this->withPath($executor, $scope, $relative, static function (string $root, string $target) use ($executor, $relative, $query): array {
            $info = $executor->stat($target);
            if ($info === null || $info['type'] !== 'dir') {
                throw new ValidationError('เส้นทางนี้ไม่ใช่โฟลเดอร์');
            }

            $state = ['visited' => 0, 'deadline' => microtime(true) + self::TIME_LIMIT_SECONDS];
            $matches = [];

            self::scan($executor, $target, $relative, $query, self::MAX_DEPTH, $matches, $state);

            // โฟลเดอร์ก่อนไฟล์แล้วเรียงตามชื่อ — เหมือน `file.list` เพื่อให้ผลลัพธ์
            // ที่แสดงในตารางเดียวกันมีลำดับแบบเดียวกันเสมอ
            usort($matches, static function (array $a, array $b): int {
                $rank = static fn(array $e): int => $e['type'] === 'dir' ? 0 : 1;

                return [$rank($a), mb_strtolower($a['name'])] <=> [$rank($b), mb_strtolower($b['name'])];
            });

            return [
                'entries' => $matches,
                'visited' => $state['visited'],
                // บอกตรง ๆ ว่าผลลัพธ์ถูกตัด — ผู้ใช้ที่หาไม่เจอต้องแยกออกระหว่าง
                // "ไม่มีไฟล์นั้น" กับ "หยุดค้นก่อนถึงมัน" ไม่งั้นจะเชื่อคำตอบที่ไม่จริง
                'truncated' => count($matches) >= self::MAX_RESULTS
                || $state['visited'] >= self::MAX_VISITED
                || microtime(true) > $state['deadline']
            ];
        });

        return [
            'root' => $scope->key,
            'site_id' => $scope->siteId,
            'path' => $relative,
            'q' => $query,
            ...$result
        ];
    }

    /**
     * ไล่ทุกชั้นใต้โฟลเดอร์หนึ่ง เก็บรายการที่ชื่อมีคำค้นอยู่ข้างใน
     *
     * @param list<array<string,mixed>> $matches
     * @param array{visited:int,deadline:float} $state
     */
    private static function scan(
        Executor $executor,
        string $absolute,
        string $relative,
        string $query,
        int $depth,
        array &$matches,
        array &$state,
    ): void {
        if ($depth <= 0 || count($matches) >= self::MAX_RESULTS || $state['visited'] >= self::MAX_VISITED) {
            return;
        }

        try {
            $entries = $executor->listDirectory($absolute);
        } catch (\Throwable) {
            return; // โฟลเดอร์ที่ผู้ใช้เข้าไม่ถึงถือว่าไม่มีผลลัพธ์ ไม่ใช่ข้อผิดพลาดทั้งคำขอ
        }

        // ตรวจเวลาหลังอ่านไดเรกทอรีเสร็จ ไม่ใช่ก่อน — ค่าที่แพงคือ syscall ที่เพิ่งจ่ายไป
        if (microtime(true) > $state['deadline']) {
            return;
        }

        foreach ($entries as $entry) {
            $state['visited']++;

            if (count($matches) >= self::MAX_RESULTS || $state['visited'] >= self::MAX_VISITED) {
                return;
            }

            $childRelative = $relative === '' ? $entry['name'] : $relative.'/'.$entry['name'];

            if (self::nameMatches($entry['name'], $query)) {
                $described = self::describe($entry['name'], $childRelative, $entry);
                $described['kind'] = $entry['type'] === 'dir' ? 'folder' : FileCatalog::kind($entry['name']);
                $described['editable'] = $entry['type'] === 'file'
                && FileCatalog::isEditable($entry['name'], $entry['size']);
                // โฟลเดอร์แม่ของผลลัพธ์ — หน้าจอใช้พาผู้ใช้ไปยังที่ที่ไฟล์นั้นอยู่จริง
                $described['parent'] = $relative;

                if ($entry['type'] === 'dir') {
                    $described['size'] = null;
                }

                $matches[] = $described;
            }

            if ($entry['type'] === 'dir') {
                self::scan($executor, $absolute.'/'.$entry['name'], $childRelative, $query, $depth - 1, $matches, $state);
            }
        }
    }

    /**
     * ชื่อนี้มีคำค้นอยู่ข้างในหรือไม่ — ไม่สนตัวพิมพ์ใหญ่เล็ก
     *
     * ชื่อไฟล์บนดิสก์เป็นลำดับไบต์ ไม่ใช่ข้อความ — ชื่อที่ไม่ใช่ UTF-8 (ไฟล์เก่าที่
     * ตั้งชื่อด้วย TIS-620 หรือชื่อที่ถูกเขียนด้วยโปรแกรมอื่น) ต้องถูกข้าม ไม่ใช่ส่งกลับ
     * เพราะ `json_encode` ทั้งคำตอบจะล้มเพราะชื่อเดียว แล้วผู้ใช้จะได้หน้าจอว่างเปล่า
     * โดยไม่มีอะไรบอกว่าทำไม
     */
    private static function nameMatches(string $name, string $query): bool
    {
        if (!mb_check_encoding($name, 'UTF-8')) {
            return false;
        }

        return mb_stripos($name, $query) !== false;
    }
}
