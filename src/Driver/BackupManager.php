<?php

declare(strict_types=1);

namespace Phpcp\Driver;

use Phpcp\Agent\ExecutionFailed;
use Phpcp\Agent\Executor\Executor;
use Phpcp\Agent\ValidationError;
use Phpcp\Domain\Site;
use Phpcp\Driver\Db\MariaDbManager;

/**
 * สร้างและกู้คืนข้อมูลสำรอง — ARCHITECTURE §12
 *
 * หลักการที่บังคับไว้:
 *   1. ทุกไฟล์สำรองมี checksum และต้องตรวจผ่านก่อน restore เสมอ
 *      ไฟล์ที่เสียหายบางส่วนอันตรายกว่าไม่มีไฟล์เลย เพราะกู้แล้วได้ข้อมูลครึ่ง ๆ
 *   2. ก่อน restore ทับของเดิม ต้องสำรองของเดิมไว้ก่อน — กู้ผิดตัวแล้วยังย้อนได้
 *   3. แตกไฟล์ลงที่ชั่วคราวก่อน แล้วค่อยสลับเข้าที่ ไม่แตะของเดิมจนกว่าจะแน่ใจว่าครบ
 *
 * ข้อ 3 สำคัญที่สุด: การแตกไฟล์ทับโดยตรงแล้วล้มกลางทาง จะเหลือเว็บไซต์
 * ที่มีไฟล์ผสมกันระหว่างของเก่ากับของใหม่ ซึ่งแก้ยากกว่าไฟล์หายทั้งหมด
 */
final class BackupManager
{
    private const TAR = '/usr/bin/tar';

    /**
     * ที่เก็บไฟล์สำรองเมื่อไม่ได้ระบุ — ตรงกับ `Paths::backups()` ของการติดตั้งแบบ system
     *
     * เดิมเป็นค่าคงที่ที่ใช้ตรง ๆ ทั้งคลาส ซึ่งแปลว่าไดเรกทอรีสำรองถูกตรึงไว้กับ
     * เส้นทางเดียวของทั้งระบบ · ทำให้ติดตั้งแบบ portable ใช้ไม่ได้ และการทดสอบ
     * ต้องมีสิทธิ์ root · ตอนนี้รับผ่าน constructor โดยมีค่านี้เป็นค่าปริยาย
     */
    public const DEFAULT_DIR = '/var/lib/phpcp/backups';

    /** ชนิดที่รองรับ ตรงกับที่ PROMPT.md กำหนด */
    public const TYPES = ['site', 'database', 'config', 'full'];

    public function __construct(
        private readonly string $dir = self::DEFAULT_DIR,
        private readonly MariaDbManager $databases = new MariaDbManager(),
    ) {
    }

    public static function assertType(string $type): string
    {
        if (!in_array($type, self::TYPES, true)) {
            throw new ValidationError('ชนิดข้อมูลสำรองไม่ถูกต้อง');
        }

        return $type;
    }

    public static function typeLabel(string $type): string
    {
        return match ($type) {
            'site' => 'ไฟล์เว็บไซต์',
            'database' => 'ฐานข้อมูล',
            'config' => 'ค่าตั้งเซิร์ฟเวอร์',
            'full' => 'ทั้งระบบ',
            default => $type,
        };
    }

    public function directory(): string
    {
        return $this->dir;
    }

    /**
     * สร้างไฟล์สำรองของเว็บไซต์
     *
     * @return array{path:string,bytes:int,checksum:string}
     */
    public function backupSite(Executor $executor, Site $site): array
    {
        $path = $this->pathFor('site-' . $site->domain, 'tar.gz');
        $root = $executor->path($site->root());

        if (!$executor->exists($root)) {
            throw new ExecutionFailed("ไม่พบไดเรกทอรีของเว็บไซต์ {$site->domain}");
        }

        $executor->makeDirectory($executor->path($this->dir), 0750);

        // -C แล้วอ้างชื่อโฟลเดอร์ — ไฟล์ในนี้จึงไม่มีเส้นทางเต็มของเครื่องติดไปด้วย
        // และแตกกลับที่ไหนก็ได้โดยไม่เขียนทับตำแหน่งอื่นโดยไม่ตั้งใจ
        $result = $executor->exec([
            self::TAR,
            '--create', '--gzip',
            '--file', $executor->path($path),
            '--directory', dirname($root),
            '--exclude', 'tmp',
            basename($root),
        ], timeout: 900);

        if (!$result->ok()) {
            throw new ExecutionFailed('สร้างไฟล์สำรองไม่สำเร็จ: ' . trim($result->stderr));
        }

        return $this->describe($executor, $path);
    }

    /**
     * สำรองฐานข้อมูลเป็นไฟล์ SQL
     *
     * @return array{path:string,bytes:int,checksum:string}
     */
    public function backupDatabase(Executor $executor, string $database): array
    {
        $path = $this->pathFor('db-' . $database, 'sql');

        $executor->makeDirectory($executor->path($this->dir), 0750);
        $this->databases->dump($executor, $database, $path);

        return $this->describe($executor, $path);
    }

    /**
     * สำรองค่าตั้งของเซิร์ฟเวอร์ที่ panel เป็นคนดูแล
     *
     * ไม่รวมไฟล์ของ panel เอง (/etc/phpcp) เพราะมี secret key อยู่ —
     * ไฟล์สำรองที่มีคีย์ปนอยู่จะทำให้ความเสียหายตอนไฟล์สำรองรั่วรุนแรงกว่าเดิมมาก
     *
     * @return array{path:string,bytes:int,checksum:string}
     */
    public function backupConfig(Executor $executor): array
    {
        $path = $this->pathFor('config', 'tar.gz');
        $executor->makeDirectory($executor->path($this->dir), 0750);

        $sources = [];
        foreach (['/etc/apache2', '/etc/php'] as $candidate) {
            if ($executor->exists($executor->path($candidate))) {
                $sources[] = ltrim($candidate, '/');
            }
        }

        if ($sources === []) {
            throw new ExecutionFailed('ไม่พบไดเรกทอรีค่าตั้งที่จะสำรอง');
        }

        $result = $executor->exec([
            self::TAR,
            '--create', '--gzip',
            '--file', $executor->path($path),
            '--directory', $executor->path('/'),
            ...$sources,
        ], timeout: 300);

        if (!$result->ok()) {
            throw new ExecutionFailed('สำรองค่าตั้งไม่สำเร็จ: ' . trim($result->stderr));
        }

        return $this->describe($executor, $path);
    }

    /**
     * กู้คืนไฟล์เว็บไซต์จากไฟล์สำรอง
     *
     * ขั้นตอน: ตรวจ checksum → สำรองของเดิม → แตกลงที่ชั่วคราว → สลับเข้าที่
     *
     * @return array{restored:string,safety:string,entries:int}
     */
    public function restoreSite(Executor $executor, Site $site, string $archive, string $checksum): array
    {
        $this->assertIntact($executor, $archive, $checksum);

        // ขั้นที่ 1 — สำรองของเดิมไว้ก่อน กู้ผิดตัวแล้วยังย้อนกลับได้
        $safety = $this->backupSite($executor, $site);

        if ($safety['path'] === $archive) {
            throw new ExecutionFailed('ไฟล์สำรองนิรภัยชนกับไฟล์ต้นฉบับ — ยกเลิกการกู้คืนเพื่อไม่ให้ข้อมูลหาย');
        }

        // ตรวจซ้ำอีกครั้ง "หลัง" สร้างไฟล์สำรองนิรภัย และก่อนแตกไฟล์
        //
        // ระหว่างสองจังหวะนี้มีการเขียนไฟล์ลงไดเรกทอรีสำรอง ถ้ามีอะไรไปแตะต้นฉบับเข้า
        // เราต้องรู้ก่อนเขียนทับของจริง ไม่ใช่รู้ตอนที่ข้อมูลหายไปแล้ว
        $this->assertIntact($executor, $archive, $checksum);

        $root = $executor->path($site->root());
        $staging = $root . '.restore-' . bin2hex(random_bytes(4));

        $executor->makeDirectory($staging, 0750);

        // ขั้นที่ 2 — แตกลงที่ชั่วคราว ยังไม่แตะของเดิม
        $result = $executor->exec([
            self::TAR,
            '--extract', '--gzip',
            '--file', $executor->path($archive),
            '--directory', $staging,
            '--strip-components', '1',
        ], timeout: 900);

        if (!$result->ok()) {
            $executor->removePath($staging);

            throw new ExecutionFailed('แตกไฟล์สำรองไม่สำเร็จ: ' . trim($result->stderr));
        }

        $entries = count($executor->listDirectory($staging));

        if ($entries === 0) {
            $executor->removePath($staging);

            throw new ExecutionFailed('ไฟล์สำรองไม่มีข้อมูล — ยกเลิกการกู้คืน');
        }

        // ขั้นที่ 3 — สลับเข้าที่ ย้ายของเดิมออกก่อนแล้วค่อยย้ายของใหม่เข้า
        $retired = $root . '.old-' . bin2hex(random_bytes(4));

        $executor->rename($root, $retired);

        try {
            $executor->rename($staging, $root);
        } catch (\Throwable $e) {
            // สลับไม่สำเร็จ — เอาของเดิมกลับเข้าที่ทันที
            $executor->rename($retired, $root);
            $executor->removePath($staging);

            throw new ExecutionFailed('สลับไฟล์ที่กู้คืนไม่สำเร็จ จึงคืนของเดิมกลับแล้ว: ' . $e->getMessage());
        }

        $executor->removePath($retired);

        return [
            'restored' => $site->root(),
            'safety' => $safety['path'],
            'entries' => $entries,
        ];
    }

    /** ตรวจว่าไฟล์สำรองยังครบถ้วนก่อนนำไปใช้ */
    public function assertIntact(Executor $executor, string $archive, string $checksum): void
    {
        $resolved = $executor->path($archive);

        if (!$executor->exists($resolved)) {
            throw new ValidationError('ไม่พบไฟล์สำรองที่ระบุ');
        }

        if ($checksum === '') {
            throw new ValidationError('ไฟล์สำรองนี้ไม่มี checksum บันทึกไว้ จึงยืนยันความครบถ้วนไม่ได้');
        }

        $actual = @hash_file('sha256', $resolved);

        if ($actual === false) {
            throw new ExecutionFailed('อ่านไฟล์สำรองเพื่อตรวจสอบไม่ได้');
        }

        if (!hash_equals($checksum, $actual)) {
            throw new ValidationError(
                'ไฟล์สำรองไม่ตรงกับ checksum ที่บันทึกไว้ — ไฟล์อาจเสียหายหรือถูกแก้ไข จึงไม่กู้คืนให้',
            );
        }
    }

    public function delete(Executor $executor, string $archive): void
    {
        // ตัด .. ทิ้งก่อนเทียบ prefix
        //
        // การเทียบสตริงอย่างเดียวหลอกได้ด้วย /var/lib/phpcp/backups/../panel.db
        // ซึ่งขึ้นต้นตรงตามที่ต้องการทุกประการ แต่ชี้ไปยังไฟล์ฐานข้อมูลของ panel เอง
        if (preg_match('#(^|/)\.\.(/|$)#', $archive) === 1) {
            throw new ValidationError('เส้นทางไฟล์สำรองต้องไม่มี ..');
        }

        $resolved = $executor->path($archive);
        $expected = rtrim($executor->path($this->dir), '/');

        if (!str_starts_with($resolved, $expected . '/')) {
            throw new ValidationError('ลบได้เฉพาะไฟล์ในไดเรกทอรีสำรองเท่านั้น');
        }

        // ตรวจซ้ำหลังคลาย symlink สำหรับไฟล์ที่มีอยู่จริง —
        // ลิงก์ที่ชี้ออกนอกไดเรกทอรีสำรองผ่านการเทียบสตริงข้างบนได้
        if ($executor->exists($resolved)) {
            $real = $executor->realPath($resolved);

            if ($real === null || !str_starts_with($real, $expected . '/')) {
                throw new ValidationError('ไฟล์นี้ชี้ออกนอกไดเรกทอรีสำรอง จึงลบผ่านระบบนี้ไม่ได้');
            }

            $executor->removePath($real);
        }
    }

    /** @return array{path:string,bytes:int,checksum:string} */
    private function describe(Executor $executor, string $path): array
    {
        $resolved = $executor->path($path);
        $stat = $executor->stat($resolved);

        if ($stat === null) {
            throw new ExecutionFailed('สร้างไฟล์สำรองแล้วแต่หาไฟล์ไม่พบ');
        }

        $checksum = @hash_file('sha256', $resolved);

        return [
            'path' => $path,
            'bytes' => (int) $stat['size'],
            'checksum' => $checksum === false ? '' : $checksum,
        ];
    }

    /**
     * ชื่อไฟล์สำรองต้องไม่ซ้ำกันเด็ดขาด
     *
     * เดิมใช้เวลาระดับวินาทีอย่างเดียว ซึ่งชนกันได้จริงและเคยทำให้ข้อมูลหาย:
     * ตอนกู้คืน ระบบสำรองสถานะปัจจุบันไว้ก่อนเป็นมาตรการนิรภัย ถ้าไฟล์นั้นได้ชื่อ
     * เดียวกับไฟล์ที่กำลังจะกู้คืน มันจะเขียนทับต้นฉบับ แล้วระบบก็ไปแตก
     * "สถานะที่พังแล้ว" กลับมาแทนของเดิม โดยรายงานว่าสำเร็จ
     *
     * ต่อท้ายด้วยค่าสุ่มจึงไม่ใช่เรื่องความสวยงาม แต่เป็นการกันข้อมูลหาย
     */
    private function pathFor(string $label, string $extension): string
    {
        $safe = preg_replace('/[^a-zA-Z0-9._-]/', '-', $label) ?? 'backup';

        return sprintf(
            '%s/%s-%s-%s.%s',
            $this->dir,
            $safe,
            date('Ymd-His'),
            bin2hex(random_bytes(3)),
            $extension,
        );
    }
}
