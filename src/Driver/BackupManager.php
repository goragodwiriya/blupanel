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

    /** ใบแจ้งข้อมูลของเว็บต้นทาง ที่รากของ archive — ดู writeManifest() */
    public const MANIFEST = 'backup.json';

    /** รุ่นของรูปแบบใบแจ้งข้อมูล — ปลายทางที่อ่านไม่เข้าใจต้องปฏิเสธ ไม่ใช่เดา */
    public const MANIFEST_SCHEMA = 1;

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
     * สร้างไฟล์สำรองของเว็บไซต์ — **ไฟล์ที่เว็บเสิร์ฟจริง ไม่ใช่กล่องสถานะ**
     *
     * เดิมสำรอง `root()` ซึ่งใช้ได้ตอนที่มีเลย์เอาต์เดียว: `phpcp` เก็บทุกอย่างไว้ใต้
     * `<บ้าน>/domains/<โดเมน>/` โดยมี `public/` อยู่ข้างใน สำรองกล่องก็ได้ไฟล์เว็บติดมาด้วย
     *
     * **เลย์เอาต์ cpanel ไม่เป็นแบบนั้น** — `root()` คือ `<บ้าน>/.phpcp/<โดเมน>` ที่มีแต่
     * `__suspended.html` ส่วนไฟล์เว็บอยู่คนละที่ที่ `<บ้าน>/public_html` · ตั้งแต่ cpanel
     * เป็นเลย์เอาต์มาตรฐาน (migration 0020) ปุ่ม "สำรองข้อมูล" จึงสร้างไฟล์ที่**ไม่มี
     * ไฟล์เว็บอยู่เลยสักไฟล์** และรายงานว่าสำเร็จทุกครั้ง — ความล้มเหลวแบบที่รู้ตัว
     * ตอนกู้คืนแล้วเท่านั้น ซึ่งสายเกินไปตามนิยาม
     *
     * `docroot()` ยังตอบถูกเมื่อเว็บใช้ Domain Pointer (ชี้ออกไปนอกบ้าน) ด้วย
     *
     * @return array{path:string,bytes:int,checksum:string}
     */
    public function backupSite(Executor $executor, Site $site): array
    {
        $path = $this->pathFor('site-' . $site->domain, 'tar.gz');
        $root = $executor->path($site->docroot());

        if (!$executor->exists($root)) {
            throw new ExecutionFailed("ไม่พบไดเรกทอรีของเว็บไซต์ {$site->domain}");
        }

        $executor->makeDirectory($executor->path($this->dir), 0750);

        $manifest = $this->writeManifest($executor, $site, basename($root));

        try {
            // -C แล้วอ้างชื่อโฟลเดอร์ — ไฟล์ในนี้จึงไม่มีเส้นทางเต็มของเครื่องติดไปด้วย
            // และแตกกลับที่ไหนก็ได้โดยไม่เขียนทับตำแหน่งอื่นโดยไม่ตั้งใจ
            //
            // `-C` ใช้ได้หลายครั้งในคำสั่งเดียว โดยมีผลกับรายการที่ตามหลังมันเท่านั้น —
            // ใบแจ้งข้อมูลจึงเข้าไปอยู่ที่รากของ archive คู่กับโฟลเดอร์เว็บ ไม่ใช่ข้างใน
            $result = $executor->exec([
                self::TAR,
                '--create', '--gzip',
                '--file', $executor->path($path),
                '--directory', dirname($manifest), self::MANIFEST,
                '--directory', dirname($root),
                '--exclude', 'tmp',
                basename($root),
            ], timeout: 900);
        } finally {
            // ลบทั้งไดเรกทอรีชั่วคราว ไม่ใช่แค่ไฟล์ — ไม่งั้นเหลือโฟลเดอร์เปล่าสะสม
            $executor->removePath(dirname($executor->path($manifest)));
        }

        if (!$result->ok()) {
            throw new ExecutionFailed('สร้างไฟล์สำรองไม่สำเร็จ: ' . trim($result->stderr));
        }

        return $this->describe($executor, $path);
    }

    /**
     * ใบแจ้งข้อมูลของเว็บต้นทาง — สิ่งที่ทำให้ไฟล์สำรอง "อธิบายตัวเองได้"
     *
     * ไฟล์สำรองที่ถูกส่งไปเก็บอีกเครื่องเป็นแค่ `.tar.gz` ที่ panel ปลายทางไม่รู้จัก:
     * ไม่รู้ว่าเป็นของโดเมนไหน สร้างเมื่อไร มาจากเครื่องอะไร · ทำให้สำเนานอกเครื่อง
     * **เขียนได้อย่างเดียว อ่านกลับไม่ได้** ซึ่งกลับหัวกับเหตุผลที่มันมีอยู่
     *
     * เก็บไว้ **ในตัว archive** ไม่ใช่ไฟล์คู่กัน เพราะไฟล์คู่กันหายระหว่างทางได้ง่าย
     * (คัดลอกด้วยมือ ย้ายที่เก็บ ดาวน์โหลดผ่านหน้าเว็บ) แล้วเหลือ archive ที่ไร้ที่มา
     *
     * @return string เส้นทางไฟล์ชั่วคราวที่ผู้เรียกต้องลบทิ้ง
     */
    private function writeManifest(Executor $executor, Site $site, string $directory): string
    {
        $manifest = [
            'schema' => self::MANIFEST_SCHEMA,
            'type' => 'site',
            'domain' => $site->domain,
            'system_user' => $site->systemUser(),
            'php_version' => $site->phpVersion,
            // ชื่อโฟลเดอร์บนสุดใน archive — ปลายทางใช้ตรวจว่าแตกไฟล์ถูกที่
            'directory' => $directory,
            'docroot' => $site->docroot(),
            'layout' => $site->owner->layout()->value,
            'aliases' => $site->aliases,
            'hostname' => php_uname('n'),
            'created_at' => time(),
        ];

        // ไดเรกทอรีของตัวเอง เพราะ `-C <dir> backup.json` ต้องการให้ชื่อไฟล์ใน archive
        // เป็น `backup.json` เปล่า ๆ ไม่มีเส้นทางนำหน้า
        $directoryPath = $this->dir . '/.manifest-' . bin2hex(random_bytes(6));

        $executor->makeDirectory($executor->path($directoryPath), 0700);
        $executor->writeFile(
            $executor->path($directoryPath . '/' . self::MANIFEST),
            (string) json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            0600,
        );

        return $directoryPath . '/' . self::MANIFEST;
    }

    /**
     * อ่านใบแจ้งข้อมูลออกมาโดยไม่แตกไฟล์ทั้งก้อน
     *
     * ปลายทางต้องรู้ว่าไฟล์นี้เป็นของโดเมนไหน **ก่อน** ตัดสินใจว่าจะเอาไปทับที่ไหน ·
     * แตกทั้งก้อนเพื่อดูข้อมูลบรรทัดเดียวแปลว่าต้องมีที่ว่างเท่าขนาดเว็บทั้งเว็บ
     *
     * @return array<string,mixed>|null null = ไฟล์สำรองรุ่นเก่าที่ยังไม่มีใบแจ้งข้อมูล
     */
    public function readManifest(Executor $executor, string $archive): ?array
    {
        $result = $executor->exec([
            self::TAR,
            '--extract', '--gzip', '--to-stdout',
            '--file', $executor->path($archive),
            self::MANIFEST,
        ], timeout: 60);

        if (!$result->ok()) {
            return null;
        }

        $manifest = json_decode(trim($result->stdout), true);

        return is_array($manifest) ? $manifest : null;
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
    public function restoreSite(
        Executor $executor,
        Site $site,
        string $archive,
        string $checksum,
        string $owner = '',
    ): array {
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

        $root = $executor->path($site->docroot());
        $staging = $root . '.restore-' . bin2hex(random_bytes(4));

        $executor->makeDirectory($staging, 0750);

        // ขั้นที่ 2 — แตกลงที่ชั่วคราว ยังไม่แตะของเดิม
        /*
         * **ห้ามให้ `backup.json` หลุดเข้า docroot** — มันจะถูกเสิร์ฟที่
         * `https://<โดเมน>/backup.json` ทันที พร้อมชื่อผู้ใช้ระบบ เส้นทางไฟล์บนเครื่อง
         * และชื่อโฮสต์ของเครื่องต้นทาง
         *
         * `--strip-components 1` ตัดรายการที่มีชั้นเดียวทิ้งอยู่แล้วในทางปฏิบัติ แต่
         * นั่นเป็นผลข้างเคียงของการนับชั้น ไม่ใช่คำสั่ง · เขียน `--exclude` ให้ชัด
         * เพื่อไม่ให้ความปลอดภัยขึ้นอยู่กับพฤติกรรมที่ไม่ได้ประกาศไว้ของ tar
         */
        $result = $executor->exec([
            self::TAR,
            '--extract', '--gzip',
            '--file', $executor->path($archive),
            '--directory', $staging,
            '--exclude', self::MANIFEST,
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

        /*
         * ตั้งเจ้าของ **ก่อน** สลับเข้าที่ ไม่ใช่หลัง — เว็บต้องไม่มีวินาทีไหนที่มีชีวิต
         * อยู่ด้วยเจ้าของที่ผิด
         *
         * สองเหตุผลที่ขั้นนี้ขาดไม่ได้ ทั้งคู่ทำให้เว็บตายเงียบ ๆ หลังกู้คืน "สำเร็จ":
         *
         *   1. `$staging` ถูกสร้างโดย agent ซึ่งเป็น root · `--strip-components 1`
         *      ตัดรายการไดเรกทอรีบนสุดของ archive ทิ้ง เจ้าของกับสิทธิ์ของมันจึง
         *      **ไม่ถูกนำมาใช้กับ `$staging`** (ยืนยันด้วยการทดลองจริงแล้ว) ·
         *      ผลคือรากเว็บกลายเป็น root:root ที่ FPM pool ของเจ้าของเดินผ่านไม่ได้
         *
         *   2. tar ที่รันด้วย root คืนค่าเจ้าของตามที่ฝังมาใน archive — ซึ่งเป็น
         *      **เลข uid ของเครื่องต้นทาง** · กู้ไฟล์ข้ามเครื่อง (สองเครื่องที่ uid
         *      ไม่ตรงกัน) จึงได้ไฟล์ที่เป็นของผู้ใช้คนอื่นหรือของ uid ที่ไม่มีอยู่จริง
         *
         * `-h` เปลี่ยนตัว symlink เอง ไม่ไล่ตามไปเปลี่ยนปลายทางนอกขอบเขต
         * · ค่าว่างแปลว่าโหมด shared_owner ซึ่ง filesystem เก็บเจ้าของไม่ได้อยู่แล้ว
         */
        if ($owner !== '') {
            $chown = $executor->exec(['/usr/bin/chown', '-Rh', $owner, $staging], timeout: 300);

            if (!$chown->ok()) {
                $executor->removePath($staging);

                throw new ExecutionFailed(
                    'ตั้งเจ้าของไฟล์ที่กู้คืนไม่สำเร็จ จึงยกเลิกก่อนแตะของเดิม: ' . trim($chown->stderr),
                );
            }
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
            'restored' => $site->docroot(),
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
