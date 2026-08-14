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
     * ใบรายชื่อชั่วคราวที่ `tar --index-file` เขียนระหว่างตรวจ archive
     *
     * ถูกลบทิ้งก่อนแตกไฟล์เสมอ จึงไม่มีวันหลุดเข้าไปอยู่ในเว็บที่กู้คืนแล้ว
     */
    private const INDEX = '.tar-index';

    /**
     * เพดานจำนวนรายการใน archive ที่ยอมกู้คืน
     *
     * ตั้งสูงโดยตั้งใจ — เว็บจริงที่มี `node_modules` หรือปลั๊กอินครบชุดมีไฟล์หลักแสน
     * ไฟล์ได้ตามปกติ · ตัวเลขนี้เป็นเพียงขอบเขตกันใบรายชื่อที่โตไม่รู้จบ ไม่ใช่นโยบาย
     * ว่าเว็บควรมีไฟล์กี่ไฟล์
     */
    private const MAX_ENTRIES = 500_000;

    /**
     * ชนิดที่รองรับ — **ไฟล์เว็บกับฐานข้อมูลเท่านั้น**
     *
     * เดิมมี `config` (สำรอง `/etc/apache2`, `/etc/php`) และ `full` ที่รวมทุกอย่าง ·
     * ทั้งคู่เป็นของ**เครื่อง** ไม่ใช่ของลูกค้า จึงไม่มีบ้านให้ไปอยู่ในระบบที่ไฟล์สำรอง
     * ทุกไฟล์เป็นของเจ้าของข้อมูล · ค่าตั้งของเครื่องสำรองด้วย snapshot ของ VPS หรือ git
     * ได้ตรงกว่าและกู้กลับได้จริงกว่า (PLAN-BACKUP-V2 §2 ข้อ B2)
     */
    public const TYPES = ['site', 'database'];

    /**
     * ไม่มี "ไดเรกทอรีสำรองของระบบ" อีกแล้ว — ปลายทางมาจากเจ้าของข้อมูลเสมอ
     *
     * เดิมคลาสนี้ถือเส้นทางเดียวไว้ทั้งตัว (`/var/lib/phpcp/backups`) ซึ่งแปลว่าไฟล์
     * สำรองของลูกค้าทุกคนไปกองรวมกันในพื้นที่ของ panel · ตอนนี้ทุกเมธอดที่เขียนไฟล์
     * รับ `Site` แล้วเขียนลง `$site->backupDir()` ซึ่งอยู่ในบ้านของเจ้าของ — ไม่มีทาง
     * เรียกให้เขียนออกนอกบ้านของเจ้าของข้อมูลได้เลย แม้ผู้เรียกจะพลาด
     */
    public function __construct(
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
            default => $type,
        };
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
     * `$owner` (`ผู้ใช้:กลุ่ม`) คือเจ้าของที่ไฟล์ต้องเป็นหลังสร้างเสร็จ · ค่าว่าง = ข้าม
     * (โหมด shared_owner หรือการทดสอบที่ไม่มี root)
     *
     * @return array{path:string,bytes:int,checksum:string}
     */
    public function backupSite(Executor $executor, Site $site, string $owner = ''): array
    {
        $dir = $this->prepareDir($executor, $site, $owner);
        $path = $this->pathFor($dir, $site->domain . '-files', 'tar.gz');
        $root = $executor->path($site->docroot());

        if (!$executor->exists($root)) {
            throw new ExecutionFailed("ไม่พบไดเรกทอรีของเว็บไซต์ {$site->domain}");
        }

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
                // **ทุกเส้นทางที่ส่งให้คำสั่งจริงต้องผ่าน `path()` เสมอ** — เส้นทางเชิง
                // ตรรกะ (`/home/...`) กับเส้นทางบนดิสก์ต่างกันในโหมด sandbox · ลืมที่นี่
                // ที่เดียวแล้ว tar หาใบแจ้งข้อมูลไม่เจอ แล้วทั้งคำสั่งล้มทั้งที่ทุกอย่างอื่นถูก
                // (เจอบนเครื่องจริงตอนทดสอบรอบแรกของ PLAN-BACKUP-V2)
                '--directory', dirname($executor->path($manifest)), self::MANIFEST,
                '--directory', dirname($root),
                '--exclude', 'tmp',
                basename($root),
            ], timeout: 900);
        } finally {
            // ลบทั้งไดเรกทอรีชั่วคราว ไม่ใช่แค่ไฟล์ — ไม่งั้นเหลือโฟลเดอร์เปล่าสะสม
            $executor->removePath(dirname($executor->path($manifest)));
        }

        if (!$result->ok()) {
            /*
             * **เก็บเศษไฟล์ที่ tar สร้างค้างไว้ก่อนล้ม**
             *
             * tar สร้างไฟล์ปลายทางตั้งแต่วินาทีแรกแล้วค่อยเขียนลงไป · ล้มกลางทางจึงเหลือ
             * `.tar.gz` ขนาด 20 ไบต์ (gzip เปล่า) นอนอยู่ในโฟลเดอร์ของลูกค้า — รายการ
             * อ่านจากโฟลเดอร์จริง มันจึงโผล่ขึ้นมาพร้อมปุ่มกู้คืนเหมือนไฟล์สำรองปกติ
             * แล้วกินโควตาของเขาด้วย · ไฟล์สำรองปลอมที่ไม่มีใครรู้ว่าปลอมคือสิ่งที่
             * อันตรายที่สุดในระบบนี้
             */
            $executor->removePath($executor->path($path));

            throw new ExecutionFailed('สร้างไฟล์สำรองไม่สำเร็จ: ' . trim($result->stderr));
        }

        $this->handOver($executor, $path, $owner);

        return $this->describe($executor, $path);
    }

    /**
     * เตรียมโฟลเดอร์สำรองของบัญชีให้พร้อมและ**เป็นของลูกค้าจริง ๆ**
     *
     * agent รันเป็น root · ไดเรกทอรีที่มันสร้างจึงเป็นของ root และลูกค้าเปิดผ่าน SFTP
     * ไม่ได้เลย ทั้งที่ทั้งระบบนี้รื้อใหม่เพื่อให้เขาหยิบไฟล์ของตัวเองได้ · โฟลเดอร์นี้
     * อาจมีอยู่แล้วจากตอน provision (`SiteLayout::requiredDirectories()`) แต่บัญชีที่
     * สร้างก่อนหน้านั้นยังไม่มี — จึงต้องตั้งเจ้าของทุกครั้ง ไม่ใช่เฉพาะตอนสร้างใหม่
     */
    private function prepareDir(Executor $executor, Site $site, string $owner): string
    {
        $dir = $site->backupDir();

        $executor->makeDirectory($executor->path($dir), 0750);

        if ($owner !== '') {
            // ไม่ใช่ -R เพราะไฟล์ข้างในเป็นของลูกค้าคนเดียวกันอยู่แล้ว และการไล่ทั้ง
            // โฟลเดอร์ทุกครั้งที่สำรองคือการเดินไฟล์เก่าทั้งหมดโดยไม่ได้อะไรเพิ่ม
            $executor->exec(['/usr/bin/chown', $owner, $executor->path($dir)], timeout: 30);
        }

        return $dir;
    }

    /**
     * ยกไฟล์ที่เพิ่งสร้างให้เจ้าของข้อมูล
     *
     * tar/mysqldump ที่รันด้วย root ได้ไฟล์ root:root โหมด 0600 · **ลูกค้าดาวน์โหลด
     * สำเนาของตัวเองไม่ได้** และลบทิ้งเองก็ไม่ได้ ซึ่งขัดกับข้อตกลงทั้งหมดของระบบนี้
     * (B1, B4: ตัวไฟล์คือความจริงเพราะลูกค้าลบมันเองได้)
     *
     * 0640 ไม่ใช่ 0644 — กลุ่มคือกลุ่มของเว็บเซิร์ฟเวอร์ ส่วนคนอื่นบนเครื่องไม่ต้องอ่าน
     * ไฟล์ที่มีทั้งเว็บและฐานข้อมูลของลูกค้ารายนี้อยู่ข้างใน
     */
    private function handOver(Executor $executor, string $path, string $owner): void
    {
        if ($owner === '') {
            return;
        }

        $resolved = $executor->path($path);

        $executor->exec(['/usr/bin/chown', $owner, $resolved], timeout: 60);
        $executor->changeMode($resolved, 0640);
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
        $directoryPath = $site->backupDir() . '/.manifest-' . bin2hex(random_bytes(6));

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
     * สำรองฐานข้อมูลของเว็บหนึ่งแห่ง ลงบ้านของเจ้าของเว็บนั้น
     *
     * ชื่อไฟล์ขึ้นต้นด้วยโดเมนเหมือนไฟล์เว็บ เพราะทั้งสองอย่างอยู่โฟลเดอร์เดียวกันแล้ว
     * · ต่อด้วยชื่อฐานข้อมูลเพราะเว็บหนึ่งแห่งมีได้หลายฐาน — ไฟล์ที่แยกไม่ออกว่าเป็น
     * ฐานไหนคือไฟล์ที่ลูกค้าเดาเอาเองตอนกู้คืน
     *
     * @return array{path:string,bytes:int,checksum:string}
     */
    public function backupDatabase(Executor $executor, Site $site, string $database, string $owner = ''): array
    {
        $dir = $this->prepareDir($executor, $site, $owner);
        // .sql.gz ไม่ใช่ .sql ดิบ — ข้อความ SQL บีบอัดได้ราว 5-10 เท่า และไฟล์นี้
        // นับในโควตาของลูกค้าแล้ว (B9)
        $path = $this->pathFor($dir, $site->domain . '-db-' . $database, 'sql.gz');

        $this->databases->dump($executor, $database, $path);
        $this->handOver($executor, $path, $owner);

        return $this->describe($executor, $path);
    }

    /**
     * กู้คืนไฟล์เว็บไซต์จากไฟล์สำรอง
     *
     * ขั้นตอน: ตรวจ checksum → ตรวจรายชื่อข้างใน → สำรองของเดิม → แตกลงที่ชั่วคราว → สลับเข้าที่
     *
     * ## ไฟล์ที่กู้คืนเป็นไฟล์ที่ "ลูกค้าเขียนเอง" เสมอ
     *
     * ตั้งแต่โฟลเดอร์สำรองย้ายเข้าบ้านลูกค้า (PLAN-BACKUP-V2) archive ที่มาถึงที่นี่
     * **เป็นข้อมูลที่ผู้ไม่หวังดีควบคุมได้ทั้งก้อน** — เขาวางไฟล์เองได้ผ่าน SFTP,
     * `backup.json` ข้างในก็เขียนเองได้ และ checksum ก็ถูกคำนวณสดจากไฟล์นั้นเอง
     * ({@see \Phpcp\Agent\Capability\BackupRestore}) · ด่าน checksum กับใบแจ้งข้อมูล
     * จึงพิสูจน์ได้แค่ "ไฟล์ไม่เปลี่ยนระหว่างทาง" ไม่ได้พิสูจน์ว่าเนื้อในไว้ใจได้
     *
     * ผลที่ตามมาคือขั้นแตกไฟล์ต้องถือว่า archive เป็นศัตรู:
     *
     *   1. **แตกด้วยสิทธิ์เจ้าของเว็บ ไม่ใช่ root** — เดิม tar รันเป็น root ในไดเรกทอรี
     *      ที่อยู่ในบ้านลูกค้า ซึ่งเขาลบและสร้างรายการแทนที่ได้เอง · แค่ชิงลบ `$staging`
     *      แล้ววาง symlink ทับในจังหวะก่อน tar เริ่ม ก็ได้การเขียนไฟล์ด้วย root ไป
     *      ที่ไหนก็ได้บนเครื่อง · ลดสิทธิ์ก่อนแล้วความผิดพลาดที่แย่ที่สุดจบลงในขอบเขต
     *      ของลูกค้าคนนั้นเอง — ซึ่งเป็นที่ที่เขาเขียนได้อยู่แล้ว
     *   2. **`--no-same-owner --no-same-permissions`** — tar ที่รันด้วย root ถือว่า
     *      เปิดทั้งคู่โดยปริยาย · archive ที่ใส่ไฟล์ uid 0 โหมด 4755 มาเองจึงได้
     *      shell ที่เป็น setuid root วางไว้ในเว็บ · `chown -Rh` ที่ตามมาล้าง setuid
     *      ให้โดยบังเอิญ แต่**ไม่ทำงานเลยเมื่อ `$owner` ว่าง** (โหมด shared_owner)
     *   3. **ตรวจรายชื่อก่อนแตะดิสก์** — ดู {@see assertSafeEntries()}
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

        $root = $executor->path($site->docroot());
        $staging = $root . '.restore-' . bin2hex(random_bytes(4));

        /*
         * 0700 ระหว่างตรวจ แล้วค่อยเปิดเป็น 0750 ก่อนแตกไฟล์
         *
         * ใบรายชื่อของ archive ถูกเขียนลงในนี้ · ช่วงที่มันถูกเขียนแล้วยังอ่านตรวจไม่เสร็จ
         * ต้องไม่มีใครนอกจาก root แตะได้ ไม่อย่างนั้นด่านตรวจก็กำลังตรวจไฟล์ที่ผู้ถูกตรวจ
         * แก้ได้ระหว่างตรวจ
         */
        $executor->makeDirectory($staging, 0700);

        try {
            // ขั้นที่ 1 — ตรวจรายชื่อข้างในก่อนทำอย่างอื่นทั้งสิ้น
            //
            // ก่อนสร้างไฟล์นิรภัยด้วย เพราะไฟล์นิรภัยของเว็บใหญ่ ๆ กินทั้งเวลาและโควตา
            // ของลูกค้าจริง ๆ · สร้างมันทิ้งไว้เพื่อ archive ที่จะถูกปฏิเสธอยู่ดี
            // คือการเบียดพื้นที่ของเขาโดยไม่ได้อะไรเลย
            $this->assertSafeEntries($executor, $archive, $staging . '/' . self::INDEX);

            // ขั้นที่ 2 — สำรองของเดิมไว้ก่อน กู้ผิดตัวแล้วยังย้อนกลับได้
            // (ไฟล์นิรภัยเป็นของลูกค้าเหมือนไฟล์อื่น — ส่ง $owner ต่อไปด้วย)
            $safety = $this->backupSite($executor, $site, $owner);

            if ($safety['path'] === $archive) {
                throw new ExecutionFailed('ไฟล์สำรองนิรภัยชนกับไฟล์ต้นฉบับ — ยกเลิกการกู้คืนเพื่อไม่ให้ข้อมูลหาย');
            }

            // ตรวจซ้ำอีกครั้ง "หลัง" สร้างไฟล์สำรองนิรภัย และก่อนแตกไฟล์
            //
            // ระหว่างสองจังหวะนี้มีการเขียนไฟล์ลงไดเรกทอรีสำรอง ถ้ามีอะไรไปแตะต้นฉบับเข้า
            // เราต้องรู้ก่อนเขียนทับของจริง ไม่ใช่รู้ตอนที่ข้อมูลหายไปแล้ว
            //
            // ด่านนี้ยังปิดช่องระหว่าง "ตรวจรายชื่อ" กับ "แตกไฟล์" ไปด้วย — ไฟล์ที่ถูก
            // สับเปลี่ยนหลังผ่านการตรวจรายชื่อจะมี checksum ไม่ตรงที่นี่
            $this->assertIntact($executor, $archive, $checksum);

            // ขั้นที่ 3 — แตกลงที่ชั่วคราว ยังไม่แตะของเดิม
            $executor->changeMode($staging, 0750);
            $this->extractInto($executor, $site, $archive, $staging, $owner);

            $entries = count($executor->listDirectory($staging));

            if ($entries === 0) {
                throw new ExecutionFailed('ไฟล์สำรองไม่มีข้อมูล — ยกเลิกการกู้คืน');
            }

            /*
             * ตั้งเจ้าของ **ก่อน** สลับเข้าที่ ไม่ใช่หลัง — เว็บต้องไม่มีวินาทีไหนที่มีชีวิต
             * อยู่ด้วยเจ้าของที่ผิด
             *
             * ยังจำเป็นแม้แตกไฟล์ด้วยสิทธิ์ของลูกค้าไปแล้ว เพราะ **กลุ่ม**ต้องเป็นกลุ่ม
             * ของเว็บเซิร์ฟเวอร์ ไม่ใช่กลุ่มหลักของผู้ใช้ (เหตุผลเต็มอยู่ที่
             * `SiteProvisioner::ownershipTargets()`) · และ `$staging` เองยังเป็นของ root
             * อยู่ เพราะ agent เป็นคนสร้างมัน
             *
             * `-h` เปลี่ยนตัว symlink เอง ไม่ไล่ตามไปเปลี่ยนปลายทางนอกขอบเขต — ซึ่งเป็น
             * เหตุผลเดียวกับที่ `assertSafeEntries()` ปฏิเสธ hardlink ที่ชี้ออกนอก archive:
             * `-h` กัน symlink ได้ แต่กัน hardlink ไม่ได้เลย เพราะ hardlink ไม่มี "ตัวลิงก์"
             * แยกจากไฟล์จริงให้เปลี่ยน
             *
             * ค่าว่างแปลว่าโหมด shared_owner ซึ่ง filesystem เก็บเจ้าของไม่ได้อยู่แล้ว
             */
            if ($owner !== '') {
                $chown = $executor->exec(['/usr/bin/chown', '-Rh', $owner, $staging], timeout: 300);

                if (!$chown->ok()) {
                    throw new ExecutionFailed(
                        'ตั้งเจ้าของไฟล์ที่กู้คืนไม่สำเร็จ จึงยกเลิกก่อนแตะของเดิม: ' . trim($chown->stderr),
                    );
                }
            }
        } catch (\Throwable $e) {
            // ยังไม่ได้แตะของเดิมเลยจนถึงบรรทัดนี้ — เก็บที่ชั่วคราวทิ้งแล้วจบ
            $executor->removePath($staging);

            throw $e;
        }

        // ขั้นที่ 4 — สลับเข้าที่ ย้ายของเดิมออกก่อนแล้วค่อยย้ายของใหม่เข้า
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

    /**
     * แตก archive ลงที่ชั่วคราว ด้วยสิทธิ์ของเจ้าของเว็บ
     *
     * `$owner` ว่าง = โหมด shared_owner หรือการทดสอบที่ไม่มี root · ที่นั่นบัญชีระบบ
     * ของลูกค้าอาจไม่มีอยู่จริงบนเครื่อง การยืนยันจะลดสิทธิ์ไปหาบัญชีที่ไม่มีตัวตน
     * ทำให้การกู้คืนล้มทั้งที่ไม่มีอะไรผิด — ใช้สิทธิ์ของ agent เองเหมือนเดิม
     * (`asUser(null)` = ทำงานตรง ๆ) และพึ่ง `--no-same-*` เป็นด่านแทน
     *
     * **ห้ามให้ `backup.json` หลุดเข้า docroot** — มันจะถูกเสิร์ฟที่
     * `https://<โดเมน>/backup.json` ทันที พร้อมชื่อผู้ใช้ระบบ เส้นทางไฟล์บนเครื่อง
     * และชื่อโฮสต์ของเครื่องต้นทาง
     *
     * `--strip-components 1` ตัดรายการที่มีชั้นเดียวทิ้งอยู่แล้วในทางปฏิบัติ แต่
     * นั่นเป็นผลข้างเคียงของการนับชั้น ไม่ใช่คำสั่ง · เขียน `--exclude` ให้ชัด
     * เพื่อไม่ให้ความปลอดภัยขึ้นอยู่กับพฤติกรรมที่ไม่ได้ประกาศไว้ของ tar
     */
    private function extractInto(
        Executor $executor,
        Site $site,
        string $archive,
        string $staging,
        string $owner,
    ): void {
        $argv = [
            self::TAR,
            '--extract', '--gzip',
            '--file', $executor->path($archive),
            '--directory', $staging,
            '--exclude', self::MANIFEST,
            '--strip-components', '1',
            '--no-same-owner', '--no-same-permissions',
        ];

        $result = $executor->asUser(
            $owner !== '' ? $site->systemUser() : null,
            static function () use ($executor, $argv): array {
                $run = $executor->exec($argv, timeout: 900);

                return ['ok' => $run->ok(), 'stderr' => trim($run->stderr)];
            },
        );

        if (($result['ok'] ?? false) !== true) {
            throw new ExecutionFailed('แตกไฟล์สำรองไม่สำเร็จ: ' . (string) ($result['stderr'] ?? ''));
        }
    }

    /**
     * ตรวจรายชื่อข้างใน archive ก่อนแตะดิสก์ — ด่านที่ `FileUnzip` มีมาตลอดแต่ที่นี่ไม่เคยมี
     *
     * ## ทำไมต้องผ่านไฟล์ ไม่ใช่ stdout
     *
     * `exec()` ตัด stdout ทิ้งที่ 1 MB (`RealExecutor::MAX_OUTPUT_BYTES`) · ใบรายชื่อ
     * ของเว็บจริงยาวเกินนั้นตามปกติ (ราว 80 ไบต์ต่อรายการ = เกินที่ราวหมื่นรายการ)
     * ด่านที่อ่านจาก stdout จึงตรวจแค่ส่วนหัวแล้วปล่อยส่วนที่เหลือผ่านไปเงียบ ๆ —
     * ซึ่งแปลว่า archive ที่ตั้งใจร้ายเพียงแค่ต้องยาวพอก็เดินผ่านด่านได้
     * · `--index-file` ให้ tar เขียนลงไฟล์เอง แล้วอ่านทีละบรรทัดจึงไม่มีเพดาน
     *
     * ## อะไรถูกปฏิเสธ และอะไรไม่
     *
     *   - **ชื่อที่ขึ้นต้นด้วย `/` หรือมี `..`** — เขียนออกนอกที่ชั่วคราว · ด่านนี้
     *     ทำงานจริงและจับได้จริง: tar ตัดเฉพาะ `../` ที่อยู่**หน้าสุด**ของชื่อ ส่วน
     *     `public_html/../../../etc/cron.d/pwn` เดินผ่าน tar มาถึงเราครบทั้งชื่อ
     *   - **device/fifo/socket** — เว็บไซต์ไม่มีเหตุผลใดที่จะมีของพวกนี้อยู่ข้างใน
     *   - **hardlink ที่ชี้ออกนอก archive** — อันตรายเป็นพิเศษเพราะ `chown -Rh`
     *     ที่ตามมาจะเปลี่ยนเจ้าของของ**ไฟล์จริงปลายทาง** ให้เป็นลูกค้า · hardlink
     *     ที่ชี้ไป `/etc/shadow` จึงเท่ากับยกไฟล์นั้นให้เขาทั้งไฟล์
     *
     *     **แต่ด่านนี้เป็นตาข่ายชั้นสอง ไม่ใช่ชั้นที่กันเรื่องนี้อยู่วันนี้** — GNU tar 1.35
     *     ล้างปลายทางของ hardlink ให้ก่อนเราจะได้เห็น: `../../../etc/shadow`,
     *     `/etc/shadow` และ `public_html/../../../../etc/shadow` ถูกย่อเหลือ
     *     `etc/shadow` ตั้งแต่ตอน `--list` (ทดลองกับ tar บนเครื่องจริงแล้ว) · เก็บด่าน
     *     ไว้เพราะการล้างนั้นเป็นพฤติกรรมของเครื่องมือที่เราไม่ได้ควบคุม ไม่ใช่สัญญา
     *
     * **symlink ไม่ถูกปฏิเสธ** ทั้งที่รายงานตรวจสอบเสนอให้ปฏิเสธ — เว็บจริงมี symlink
     * เป็นเรื่องปกติ (`public/storage` ของ Laravel, `node_modules/.bin/*`) การปฏิเสธ
     * ทั้ง archive เพราะมีอย่างหนึ่งอย่างใดคือการทำให้ปุ่มกู้คืนใช้ไม่ได้กับเว็บส่วนใหญ่
     * · ที่มันไม่อันตรายเพราะสามชั้นซ้อนกัน: tar 1.35 ปฏิเสธการเขียนผ่าน symlink member
     * ที่อยู่ใน archive เดียวกัน (ทดสอบจริงแล้ว), การแตกไฟล์ใช้สิทธิ์ลูกค้าไม่ใช่ root
     * และ `chown -h` ไม่ไล่ตาม symlink · symlink ที่ชี้ออกนอกบ้านหลังกู้คืนเสร็จก็เป็น
     * สิ่งที่เขาสร้างเองผ่าน SFTP ได้อยู่แล้ว จึงไม่ใช่สิทธิ์ที่เพิ่มขึ้นจากการกู้คืน
     *
     * @return int จำนวนรายการทั้งหมดใน archive
     */
    private function assertSafeEntries(Executor $executor, string $archive, string $indexFile): int
    {
        // โหมดจำลองไม่ได้รัน tar จริง จึงไม่มีใบรายชื่อให้อ่าน
        if ($executor->isSimulated()) {
            return 0;
        }

        $list = $executor->exec([
            self::TAR,
            '--list', '--verbose', '--gzip',
            '--file', $executor->path($archive),
            '--index-file', $indexFile,
        ], timeout: 300);

        if (!$list->ok()) {
            throw new ExecutionFailed('อ่านรายชื่อในไฟล์สำรองไม่ได้: ' . trim($list->stderr));
        }

        // อ่านตรงจากดิสก์เหมือนที่ assertIntact() เรียก hash_file() — ไฟล์นี้ agent
        // เพิ่งเขียนเองในไดเรกทอรีของตัวเอง ไม่ใช่เส้นทางที่รับมาจากผู้ใช้
        $handle = @fopen($indexFile, 'rb');

        if ($handle === false) {
            throw new ExecutionFailed('อ่านใบรายชื่อของไฟล์สำรองไม่ได้ จึงไม่กู้คืนให้');
        }

        $count = 0;

        try {
            while (($line = fgets($handle)) !== false) {
                $line = rtrim($line, "\r\n");

                if ($line === '') {
                    continue;
                }

                if (++$count > self::MAX_ENTRIES) {
                    throw new ExecutionFailed(sprintf(
                        'ไฟล์สำรองมีมากกว่า %s รายการ ซึ่งเกินกว่าที่ระบบกู้คืนให้อัตโนมัติได้',
                        number_format(self::MAX_ENTRIES),
                    ));
                }

                self::assertSafeEntry($line);
            }
        } finally {
            fclose($handle);
        }

        // ลบก่อนแตกไฟล์เสมอ ไม่ใช่หลัง — ไม่อย่างนั้นมันกลายเป็นไฟล์หนึ่งในเว็บที่กู้คืนแล้ว
        $executor->removePath($indexFile);

        return $count;
    }

    /**
     * ตรวจรายการเดียวจากใบรายชื่อของ `tar --list --verbose`
     *
     * รูปแบบที่ GNU tar พิมพ์ (ยืนยันกับ tar 1.35 บนเครื่องจริง):
     *
     *     drwxr-xr-x poo/poo    0 2026-08-14 23:05 site/
     *     -rw-r--r-- poo/poo    6 2026-08-14 23:05 site/real.txt
     *     lrwxrwxrwx poo/poo    0 2026-08-14 23:05 site/evil -> /etc/cron.d
     *     hrw-r--r-- poo/poo    0 2026-08-14 23:05 site/hard link to site/real.txt
     *
     * บรรทัดที่แยกไม่ออกถูกปฏิเสธ ไม่ใช่ข้าม — ด่านที่อ่านไม่เข้าใจแล้วปล่อยผ่าน
     * ไม่ใช่ด่าน
     */
    private static function assertSafeEntry(string $line): void
    {
        if (preg_match('/^(.)\S{9}\s+\S+\s+\S+\s+\S+\s+\S+\s+(.+)$/', $line, $matches) !== 1) {
            throw new ExecutionFailed('อ่านรายการในไฟล์สำรองไม่เข้าใจ จึงไม่กู้คืนให้: ' . $line);
        }

        [$type, $name] = [$matches[1], $matches[2]];

        // ตัดปลายทางของลิงก์ออกจากชื่อ · เอาตัวคั่น**ตัวขวาสุด** เพราะชื่อไฟล์จริง
        // มีคำว่า " -> " อยู่ได้ ส่วนที่ tar ต่อท้ายมีเสมอตัวเดียวและอยู่ท้ายสุด
        $separator = match ($type) {
            'l' => ' -> ',
            'h' => ' link to ',
            default => '',
        };

        $target = '';

        if ($separator !== '') {
            $at = strrpos($name, $separator);

            if ($at === false) {
                throw new ExecutionFailed('รายการลิงก์ในไฟล์สำรองไม่มีปลายทาง จึงไม่กู้คืนให้: ' . $line);
            }

            $target = substr($name, $at + strlen($separator));
            $name = substr($name, 0, $at);
        }

        if (!in_array($type, ['-', 'd', 'l', 'h'], true)) {
            throw new ExecutionFailed(
                'ไฟล์สำรองมีรายการที่ไม่ใช่ไฟล์ ไดเรกทอรี หรือลิงก์ (' . $name . ') จึงไม่กู้คืนให้',
            );
        }

        self::assertContained($name, 'ชี้ออกนอกไดเรกทอรีปลายทาง');

        if ($type === 'h') {
            self::assertContained($target, 'เป็น hardlink ที่ชี้ออกนอกไฟล์สำรอง');
        }
    }

    /** เส้นทางที่อยู่ในขอบเขตเสมอ — ไม่ขึ้นต้นด้วย `/` และไม่มีชั้น `..` */
    private static function assertContained(string $path, string $reason): void
    {
        if ($path === '' || str_starts_with($path, '/') || in_array('..', explode('/', $path), true)) {
            throw new ExecutionFailed(
                'ไฟล์สำรองนี้มีรายการที่' . $reason . ' (' . ($path === '' ? '(ว่าง)' : $path) . ') จึงไม่กู้คืนให้',
            );
        }
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

    /**
     * ลบไฟล์สำรองหนึ่งไฟล์ · `$dir` คือขอบเขตที่ยอมให้ลบได้เท่านั้น
     *
     * ผู้เรียกต้องส่งไดเรกทอรีสำรอง**ของเจ้าของไฟล์นั้น** มาเสมอ — ด่านนี้จึงเป็น
     * ตัวกันไม่ให้คำสั่งลบของบัญชีหนึ่งเอื้อมไปถึงไฟล์ของอีกบัญชี ทั้งที่ทั้งคู่อยู่
     * ใต้ `/home` เหมือนกัน
     */
    public function delete(Executor $executor, string $dir, string $archive): void
    {
        // ตัด .. ทิ้งก่อนเทียบ prefix
        //
        // การเทียบสตริงอย่างเดียวหลอกได้ด้วย /home/cust/backup/../.ssh/authorized_keys
        // ซึ่งขึ้นต้นตรงตามที่ต้องการทุกประการ แต่ชี้ออกไปนอกโฟลเดอร์สำรอง
        if (preg_match('#(^|/)\.\.(/|$)#', $archive) === 1) {
            throw new ValidationError('เส้นทางไฟล์สำรองต้องไม่มี ..');
        }

        $resolved = $executor->path($archive);
        $expected = rtrim($executor->path($dir), '/');

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
    private function pathFor(string $dir, string $label, string $extension): string
    {
        $safe = preg_replace('/[^a-zA-Z0-9._-]/', '-', $label) ?? 'backup';

        return sprintf(
            '%s/%s-%s-%s.%s',
            rtrim($dir, '/'),
            $safe,
            date('Ymd-His'),
            bin2hex(random_bytes(3)),
            $extension,
        );
    }
}
