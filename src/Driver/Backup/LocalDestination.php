<?php

declare(strict_types=1);

namespace Phpcp\Driver\Backup;

use Phpcp\Agent\ExecutionFailed;
use Phpcp\Agent\Executor\Executor;
use Phpcp\Agent\ValidationError;

/**
 * ปลายทางที่เป็นเส้นทางอื่นบนเครื่องนี้ — จุดเมานต์ของ NAS, ดิสก์ก้อนที่สอง, USB
 *
 * **ยังนับว่า "นอกเครื่อง" ไหม:** ขึ้นกับว่าเมานต์อะไรไว้ · ถ้าเป็นดิสก์คนละก้อนหรือ NFS
 * ของเครื่องอื่น ก็แก้ปัญหาที่ E1 ตั้งใจแก้ได้จริง · ถ้าเป็นโฟลเดอร์บนดิสก์ก้อนเดิม
 * ก็ไม่ได้แก้อะไรเลย — `test()` จึงบอกกลับมาว่าปลายทางอยู่บนอุปกรณ์เดียวกับไดเรกทอรี
 * สำรองต้นทางหรือเปล่า เพื่อให้ผู้ดูแลเห็นความจริงข้อนี้ตั้งแต่ตอนตั้งค่า ไม่ใช่ตอนดิสก์พัง
 *
 * driver นี้ยังเป็นตัวที่ใช้ทดสอบทั้งกลไกได้โดยไม่ต้องมีเครื่องที่สอง
 */
final class LocalDestination implements Destination
{
    /**
     * @param string $sourceDir ไดเรกทอรีสำรองต้นทาง — ใช้ตอบว่าปลายทางอยู่บนอุปกรณ์เดียวกันไหม
     */
    public function __construct(
        private readonly string $root,
        private readonly string $sourceDir = '',
    ) {
        if ($this->root === '' || !str_starts_with($this->root, '/')) {
            throw new ValidationError('เส้นทางปลายทางต้องเป็นเส้นทางเต็มที่ขึ้นต้นด้วย /');
        }

        if (preg_match('#(^|/)\.\.(/|$)#', $this->root) === 1) {
            throw new ValidationError('เส้นทางปลายทางต้องไม่มี ..');
        }
    }

    public static function driver(): string
    {
        return 'local';
    }

    public function push(Executor $executor, string $localPath, string $remoteName): string
    {
        $target = $this->remotePathFor($remoteName);

        $executor->makeDirectory($executor->path($this->root), 0750);
        $executor->copyPath($executor->path($localPath), $executor->path($target));

        // ยืนยันว่าไฟล์ถึงปลายทางครบจริง ไม่ใช่แค่คำสั่งคัดลอกไม่ error
        //
        // เทียบขนาดอย่างเดียวไม่พอสำหรับสิ่งที่มีไว้กู้ข้อมูล — ดิสก์ที่กำลังจะพัง
        // คืนไฟล์ขนาดถูกต้องแต่เนื้อในเสียได้ · จึงเทียบ checksum เต็มไฟล์
        $this->assertSameContent($executor, $localPath, $target);

        return $target;
    }

    public function pull(Executor $executor, string $remotePath, string $localPath): void
    {
        $this->assertInsideRoot($remotePath);

        if (!$executor->exists($executor->path($remotePath))) {
            throw new ExecutionFailed('ไม่พบไฟล์สำรองที่ปลายทาง: ' . $remotePath);
        }

        $executor->copyPath($executor->path($remotePath), $executor->path($localPath));
        $this->assertSameContent($executor, $remotePath, $localPath);
    }

    public function delete(Executor $executor, string $remotePath): void
    {
        $this->assertInsideRoot($remotePath);

        $resolved = $executor->path($remotePath);

        if (!$executor->exists($resolved)) {
            return;   // ลบไปแล้ว — ตัวเก็บกวาดต้องเรียกซ้ำได้โดยไม่ล้ม
        }

        // คลาย symlink ก่อนลบ · ลิงก์ที่ชี้ออกนอกปลายทางผ่านการเทียบสตริงข้างบนได้
        $real = $executor->realPath($resolved);
        $root = rtrim($executor->path($this->root), '/');

        if ($real === null || !str_starts_with($real, $root . '/')) {
            throw new ValidationError('ไฟล์นี้ชี้ออกนอกปลายทางสำรอง จึงลบผ่านระบบนี้ไม่ได้');
        }

        $executor->removePath($real);
    }

    public function test(Executor $executor): array
    {
        $executor->makeDirectory($executor->path($this->root), 0750);

        $probe = $this->remotePathFor('.phpcp-probe-' . bin2hex(random_bytes(4)));
        $content = 'phpcp destination probe ' . time();

        $executor->writeFile($executor->path($probe), $content, 0600);

        $readBack = $executor->readFile($executor->path($probe));
        $executor->removePath($executor->path($probe));

        if ($readBack !== $content) {
            throw new ExecutionFailed('เขียนไฟล์ทดสอบได้ แต่อ่านกลับมาแล้วเนื้อหาไม่ตรง');
        }

        $space = $executor->diskSpace($executor->path($this->root));

        return [
            'root' => $this->root,
            'free_bytes' => (int) ($space['free'] ?? 0),
            'total_bytes' => (int) ($space['total'] ?? 0),
            // ผู้ดูแลต้องเห็นข้อนี้ตั้งแต่ตอนตั้งค่า ไม่ใช่ตอนดิสก์พัง
            'same_device' => $this->sameDeviceAsSource($executor),
        ];
    }

    /**
     * ปลายทางอยู่บนอุปกรณ์เดียวกับไดเรกทอรีสำรองต้นทางหรือไม่
     *
     * `stat()` ของ Executor ไม่ได้คืนหมายเลขอุปกรณ์ จึงเทียบด้วยพื้นที่ว่างทั้งก้อน
     * ซึ่งเป็นตัวบ่งชี้ที่ใช้ได้จริงและไม่ต้องขยาย interface — สองเส้นทางบน filesystem
     * เดียวกันรายงานพื้นที่ว่างเท่ากันเสมอ
     */
    private function sameDeviceAsSource(Executor $executor): bool
    {
        if ($this->sourceDir === '') {
            return false;   // ไม่รู้ต้นทาง = ตอบไม่ได้ · อย่าเดาเป็น "ปลอดภัย"
        }

        $source = $executor->diskSpace($executor->path($this->sourceDir));
        $target = $executor->diskSpace($executor->path($this->root));

        return ($source['total'] ?? -1) === ($target['total'] ?? -2)
            && ($source['free'] ?? -1) === ($target['free'] ?? -2);
    }

    private function remotePathFor(string $name): string
    {
        if ($name === '' || str_contains($name, '/')) {
            throw new ValidationError('ชื่อไฟล์ปลายทางต้องเป็นชื่อล้วน ไม่มีไดเรกทอรี');
        }

        return rtrim($this->root, '/') . '/' . $name;
    }

    private function assertInsideRoot(string $path): void
    {
        if (preg_match('#(^|/)\.\.(/|$)#', $path) === 1) {
            throw new ValidationError('เส้นทางไฟล์ปลายทางต้องไม่มี ..');
        }

        if (!str_starts_with($path, rtrim($this->root, '/') . '/')) {
            throw new ValidationError('เส้นทางนี้อยู่นอกปลายทางสำรองที่กำหนดไว้');
        }
    }

    private function assertSameContent(Executor $executor, string $a, string $b): void
    {
        $left = @hash_file('sha256', $executor->path($a));
        $right = @hash_file('sha256', $executor->path($b));

        if ($left === false || $right === false) {
            throw new ExecutionFailed('อ่านไฟล์เพื่อยืนยันความครบถ้วนไม่ได้');
        }

        if (!hash_equals($left, $right)) {
            throw new ExecutionFailed('ไฟล์ที่ปลายทางไม่ตรงกับต้นฉบับ — ถือว่าส่งไม่สำเร็จ');
        }
    }
}
