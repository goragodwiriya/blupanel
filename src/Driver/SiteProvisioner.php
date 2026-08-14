<?php

declare (strict_types = 1);

namespace Phpcp\Driver;

use Phpcp\Agent\ExecutionFailed;
use Phpcp\Agent\Executor\Executor;
use Phpcp\Domain\Site;
use Phpcp\Driver\Php\FpmManager;
use Phpcp\Driver\WebServer\WebServerDriver;

/**
 * งานฝั่งระบบปฏิบัติการทั้งหมดของเว็บไซต์หนึ่งเว็บ — ARCHITECTURE §11
 *
 * รวมไว้ที่นี่เพราะทุก capability ที่แตะเว็บไซต์ (สร้าง ลบ ระงับ เปลี่ยนเวอร์ชัน PHP)
 * ต้องทำขั้นตอนชุดเดียวกันและต้องย้อนกลับได้เหมือนกัน ถ้ากระจายไปอยู่ในแต่ละ capability
 * จะเกิดกรณีที่บางเส้นทางลืม rollback แล้วทิ้ง config ค้างไว้จนเว็บทั้งเครื่องพัง
 */
final class SiteProvisioner
{
    /** ผลการหยั่ง filesystem ต่อหนึ่งเส้นทาง — การหยั่งต้องสร้างไฟล์จริง จึงทำซ้ำทุกเว็บไม่ไหว */
    private array $ownershipProbe = [];

    public function __construct(
        private readonly WebServerDriver $webserver,
        private readonly FpmManager $fpm,
        /** ดู Config::sharedOwner() — true เมื่อ filesystem เก็บเจ้าของไฟล์ไม่ได้ */
        private readonly bool $sharedOwner = false,
    ) {
    }

    public function webserver(): WebServerDriver
    {
        return $this->webserver;
    }

    /**
     * @return mixed
     */
    public function fpm(): FpmManager
    {
        return $this->fpm;
    }

    /**
     * บัญชีระบบของผู้ใช้ — งานนี้ย้ายไป AccountProvisioner ตั้งแต่ migration 0006
     *
     * @return array{uid:int,gid:int}
     */
    public function account(): AccountProvisioner
    {
        return new AccountProvisioner($this->webserver, $this->sharedOwner);
    }

    /** @return array{uid:int,gid:int} */
    public function lookupUser(Executor $executor, string $user): array
    {
        return $this->account()->lookup($executor, $user);
    }

    /**
     * สร้างโครงสร้างไดเรกทอรีของเว็บไซต์พร้อมสิทธิ์ที่ถูกต้อง
     *
     * บ้านของผู้ใช้ต้องถูกสร้างโดย AccountProvisioner มาก่อนแล้ว — ที่นี่ดูแลเฉพาะ
     * โฟลเดอร์ของเว็บหนึ่งแห่งใต้ `<บ้าน>/domains/`
     *
     * สิทธิ์ 750 คือสิ่งที่กันไม่ให้**ลูกค้าต่างราย**อ่านไฟล์กันได้ (SECURITY §2.6)
     * เว็บเซิร์ฟเวอร์เข้าถึงได้เพราะอยู่ในกลุ่มเดียวกัน · เว็บของลูกค้าคนเดียวกัน
     * อ่านกันได้โดยตั้งใจ เพราะเป็นทรัพย์สินของคนเดียวกันและใช้ uid เดียวกัน
     */
    public function createDirectories(Executor $executor, Site $site): void
    {
        $executor->makeDirectory($executor->path($site->root()), 0750);

        foreach ([$site->docroot(), $site->logDir(), $site->tmpDir(), $site->backupDir()] as $dir) {
            $executor->makeDirectory($executor->path($dir), 0750);
        }

        // Domain Pointer — docroot ชี้ไปที่โฟลเดอร์ที่มีโค้ดอยู่ก่อนแล้ว
        // ห้ามโยนไฟล์ต้อนรับลงไป — โค้ดของผู้ใช้สำคัญกว่าหน้าต้อนรับของเรา
        if ($site->docrootOverride === '') {
            // ไฟล์ตั้งต้นให้เปิดเว็บแล้วเห็นว่าใช้งานได้จริง ไม่ใช่หน้า 403 ของ Apache
            $index = $executor->path($site->docroot().'/index.php');
            if (!$executor->exists($index)) {
                $executor->writeFile($index, $this->welcomePage($site), 0640);
            }
        }

        $suspended = $executor->path($site->suspendedPage());
        if (!$executor->exists($suspended)) {
            $executor->writeFile($suspended, $this->suspendedPage($site), 0644);
        }

        $this->setOwnership($executor, $site);
    }

    /**
     * เจ้าของไฟล์คือผู้ใช้ของเว็บไซต์ แต่กลุ่มคือกลุ่มของเว็บเซิร์ฟเวอร์
     *
     * เคยตั้งเป็น <ผู้ใช้>:<ผู้ใช้> ซึ่งดูปลอดภัยกว่า แต่ทำให้ Apache เดินผ่าน
     * ไดเรกทอรีของเว็บไซต์ไม่ได้เลย (mode 0750) ผลคือไฟล์สแตติกทุกไฟล์ตอบ 403
     * รวมถึงไฟล์ตรวจสอบของ Let's Encrypt ซึ่งทำให้ต่ออายุใบรับรองไม่ได้ด้วย
     *
     * รูปแบบนี้ยังกันเว็บไซต์อ่านไฟล์ข้ามกันได้เหมือนเดิม เพราะ PHP-FPM ของแต่ละเว็บ
     * รันด้วย uid ของตัวเอง ไม่ใช่ www-data และมี open_basedir กำกับอีกชั้น —
     * สิ่งที่ www-data อ่านได้คือไฟล์สแตติก ซึ่งเป็นงานที่มันต้องทำอยู่แล้ว
     *
     * ข้อยกเว้นเดียว: filesystem ที่เก็บ uid/gid ไม่ได้ (NTFS/exFAT/FAT) — ดู
     * assertOwnershipUnsupported() และ SECURITY §2.6
     */

    /**
     * ทุกไดเรกทอรีที่เว็บนี้เป็นเจ้าของ — ใช้ร่วมกันระหว่างตอน provision และตอนซ่อมเจ้าของ
     *
     * **ต้องเป็นชุดเดียวกันทั้งสองที่** · ตอนสร้างเว็บเราตั้งเจ้าของครบทุกไดเรกทอรี
     * แต่ `site.reset_owner` เคย chown แค่ `root()` ตัวเดียว ซึ่งในเลย์เอาต์ cpanel คือ
     * `.phpcp/<โดเมน>` — ไม่แตะ `public_html` ที่เป็นที่ที่ปัญหาเจ้าของไฟล์เกิดขึ้นจริง
     * ปุ่มซ่อมจึงรายงานว่าสำเร็จโดยที่เว็บยังพังเหมือนเดิม
     *
     * @return list<string>
     */
    public static function ownershipTargets(Site $site): array
    {
        $root = rtrim($site->root(), '/');
        $targets = [$root];

        /*
         * ไดเรกทอรีของเว็บที่อยู่**นอก** root() ต้อง chown แยกอีกที
         *
         * เลย์เอาต์ phpcp เก็บทุกอย่างไว้ใต้กล่องเดียวกัน (`<บ้าน>/domains/<โดเมน>/`)
         * `chown -R` ที่นั่นครอบทั้ง docroot, log และ backup ในคำสั่งเดียว — วนลูปนี้
         * จึงไม่เพิ่มอะไรเลย พฤติกรรมเดิมไม่ขยับแม้แต่คำสั่งเดียว
         *
         * **เลย์เอาต์ cpanel ไม่เป็นแบบนั้น**: docroot อยู่ที่ `<บ้าน>/public_html`
         * และ log อยู่ที่ `<บ้าน>/logs/<โดเมน>` ซึ่งไม่ได้อยู่ใต้ root() เลย · ถ้าไม่เก็บ
         * เพิ่ม ไฟล์เว็บจะยังเป็นของ root แล้วลูกค้าอัปโหลดอะไรไม่ได้ผ่าน SFTP
         * ทั้งที่หน้าจอบอกว่าสร้างเว็บสำเร็จทุกขั้นตอน
         */
        $layoutDirs = $site->owner->layout()->requiredDirectories(
            $site->owner->home(),
            $site->domain,
            $site->owner->isMainDomain($site->domain),
        );

        foreach (array_keys($layoutDirs) as $dir) {
            $dir = rtrim($dir, '/');

            if ($dir !== $root && !str_starts_with($dir.'/', $root.'/')) {
                $targets[] = $dir;
            }
        }

        // docroot ที่ชี้ออกนอกบ้านต้อง chown แยกต่างหาก — chown -R ที่บ้านไปไม่ถึง
        if ($site->docrootOverride !== '') {
            $targets[] = $site->docrootOverride;
        }

        $targets = array_values(array_unique($targets));

        return $targets;
    }

    public function setOwnership(Executor $executor, Site $site): void
    {
        if ($this->sharedOwner) {
            // fail-closed — ยอมข้าม chown ได้ก็ต่อเมื่อพิสูจน์ได้ว่า filesystem ทำไม่ได้จริง
            $this->assertOwnershipUnsupported($executor, $site);

            return;
        }

        $owner = $site->systemUser().':'.$this->webserver->runAsGroup();

        $targets = self::ownershipTargets($site);

        foreach ($targets as $target) {
            $executor->exec([
                '/usr/bin/chown',
                '-R',
                $owner,
                $executor->path($target)
            ], timeout: 60);
        }
    }

    /**
     * พิสูจน์ว่า filesystem ที่เก็บเว็บไซต์ "เก็บเจ้าของไฟล์ไม่ได้จริง" ก่อนข้าม chown
     *
     * นี่คือหัวใจของโหมด shared_owner ทั้งหมด — จงอย่าเปลี่ยนเป็นการ
     * "ลอง chown ถ้าล้มก็ข้าม" เด็ดขาด เพราะบนเซิร์ฟเวอร์จริง chown ล้มชั่วคราว
     * (ดิสก์เต็ม, quota, SELinux) จะกลายเป็นการปิดการแยกสิทธิ์ระหว่างเว็บโดยเงียบ
     * ซึ่งอันตรายกว่าไม่มีโหมดนี้เลย
     *
     * วิธีตรวจเป็นการทดสอบจริง ไม่ใช่การเดาจากชนิด filesystem — เขียนไฟล์
     * ทดสอบ chown แล้วอ่านเจ้าของกลับมา ถ้าเจ้าของเปลี่ยนตามแปลว่า filesystem ทำได้
     * จึงห้ามเปิด shared_owner — ไม่ต้องดูแลรายชื่อ filesystem ที่จะงอกเรื่อย ๆ
     */
    private function assertOwnershipUnsupported(Executor $executor, Site $site): void
    {
        $root = $executor->path($site->root());

        if (isset($this->ownershipProbe[$root])) {
            return;
        }

        $probe = $root.'/.phpcp-ownership-probe';

        try {
            $executor->writeFile($probe, "phpcp ownership probe\n", 0600);
            $executor->exec(['/usr/bin/chown', $site->systemUser(), $probe], timeout: 15);

            $stat = $executor->stat($probe);
            $expected = $this->lookupUser($executor, $site->systemUser());

            if ($stat !== null && $stat['uid'] === $expected['uid']) {
                throw new ExecutionFailed(
                    "เปิด sites.shared_owner ไว้ แต่ {$site->root()} อยู่บน filesystem ที่เก็บเจ้าของไฟล์ได้ตามปกติ\n\n"
                    ."โหมดนี้มีไว้สำหรับ filesystem ที่เก็บ uid/gid ไม่ได้ (NTFS, exFAT, FAT) เท่านั้น\n"
                    .'เครื่องนี้แยกสิทธิ์ระหว่างเว็บได้ตามปกติ จึงต้องตั้ง sites.shared_owner เป็น false',
                );
            }
        } finally {
            $executor->removePath($probe);
        }

        $this->ownershipProbe[$root] = true;
    }

    /**
     * เขียนไฟล์ config ทั้งชุดของเว็บไซต์ลงทรานแซกชัน (ยังไม่ commit)
     */
    /**
     * @param list<string> $poolExtraPaths Domain Pointer ของทุกเว็บที่ใช้ pool เดียวกันนี้
     */
    public function stageConfigs(
        ConfigTransaction $tx,
        Site $site,
        Executor $executor,
        array $poolExtraPaths = [],
    ): void {
        // ต้องมาก่อนการเขียนไฟล์ ไม่ใช่หลัง — configtest ที่ตามมาจะล้มทันที
        // ถ้าโมดูลที่ directive ใน vhost ต้องใช้ยังไม่ถูกเปิด
        $this->webserver->ensureModules($executor, $site->usesSsl());

        $tx->write(
            $site->fpmPoolFile(),
            $this->fpm->renderPool($site, $this->webserver->runAsUser(), $executor, $poolExtraPaths),
            0644,
        );

        $this->stageVhost($tx, $site, $executor);
    }

    /**
     * เขียนไฟล์ตั้งค่าของเว็บไซต์ลงทรานแซกชัน — ทุกไฟล์ที่เว็บนี้ต้องมี
     *
     * แยกเป็นเมธอดเพราะมีผู้เรียกหกที่ (สร้างเว็บ · เพิ่ม/ลบ/ตั้งโดเมน · พัก/เปิดใช้งาน)
     * และตั้งแต่มีโหมด nginx-proxy จำนวนไฟล์ต่อเว็บไม่ใช่หนึ่งเสมอไปอีกแล้ว
     * ถ้าปล่อยให้แต่ละที่วนเอง วันหนึ่งจะมีที่ที่ลืมเขียนไฟล์ชั้นหลัง
     */
    public function stageVhost(ConfigTransaction $tx, Site $site, Executor $executor): void
    {
        foreach ($this->webserver->vhostFiles($site, $executor) as $path => $contents) {
            $tx->write($path, $contents, 0644);
        }
    }

    /**
     * ตรวจ config ทั้งเว็บเซิร์ฟเวอร์และ FPM แล้วค่อย reload
     *
     * ตรวจก่อน reload เสมอ — นี่คือขั้นตอนที่กันไม่ให้ vhost ที่ผิด
     * ทำให้เว็บทุกเว็บบนเครื่องดับพร้อมกัน (ARCHITECTURE §10)
     *
     * @return array{0:bool,1:string}
     */
    public function validate(Executor $executor, Site $site): array
    {
        [$fpmOk, $fpmOut] = $this->fpm->testConfig($executor, $site->phpVersion);
        if (!$fpmOk) {
            return [false, "การตั้งค่า PHP-FPM {$site->phpVersion} ไม่ผ่าน:\n".$fpmOut];
        }

        [$webOk, $webOut] = $this->webserver->testConfig($executor);
        if (!$webOk) {
            return [false, "การตั้งค่าเว็บเซิร์ฟเวอร์ไม่ผ่าน:\n".$webOut];
        }

        return [true, trim($webOut)];
    }

    /** โหลดค่าตั้งใหม่ทั้งสองบริการหลังจากตรวจผ่านแล้ว */
    public function reload(Executor $executor, Site $site, ?string $alsoPhpVersion = null): void
    {
        $this->fpm->reload($executor, $site->phpVersion);

        // ตอนเปลี่ยนเวอร์ชัน PHP ต้อง reload เวอร์ชันเดิมด้วยเพื่อให้ pool เก่าหายไป
        if ($alsoPhpVersion !== null && $alsoPhpVersion !== $site->phpVersion) {
            $this->fpm->reload($executor, $alsoPhpVersion);
        }

        $this->webserver->reload($executor);
    }

    /**
     * ลบไฟล์ config ของเว็บไซต์ (ใช้ตอนลบเว็บไซต์)
     *
     * **pool ใช้ร่วมกับเว็บอื่นของเจ้าของคนเดียวกัน** ตั้งแต่ migration 0006 จึงลบได้
     * เฉพาะเวอร์ชันที่เจ้าของไม่ได้ใช้แล้วจริง ๆ · ถ้าลบทุกเวอร์ชันแบบเดิม การลบเว็บ
     * หนึ่งเว็บจะทำให้เว็บพี่น้องของลูกค้าคนเดียวกันดับทันทีทั้งหมด
     *
     * @param list<string> $allPhpVersions เวอร์ชันทั้งหมดที่ระบบรู้จัก
     * @param list<string> $versionsStillUsed เวอร์ชันที่เจ้าของยังใช้อยู่หลังลบเว็บนี้แล้ว
     */
    public function stageRemoval(
        ConfigTransaction $tx,
        Site $site,
        array $allPhpVersions,
        array $versionsStillUsed = [],
    ): void {
        foreach ($this->webserver->vhostPaths($site) as $path) {
            $tx->delete($path);
        }

        // ลบ pool ของทุกเวอร์ชันที่ไม่ได้ใช้แล้ว เผื่อเคยสลับเวอร์ชันจนมีไฟล์ค้าง
        foreach ($allPhpVersions as $version) {
            if (in_array($version, $versionsStillUsed, true)) {
                continue;
            }

            $tx->delete($site->fpmPoolFileFor($version));
        }
    }

    /**
     * @param Site $site
     */
    private function welcomePage(Site $site): string
    {
        $domain = htmlspecialchars($site->domain, ENT_QUOTES, 'UTF-8');

        return <<<HTML
            <!doctype html>
            <html lang="th">
            <meta charset="utf-8">
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <title>{$domain}</title>
            <body style="font-family:system-ui,-apple-system,'Segoe UI',sans-serif;max-width:640px;margin:4rem auto;padding:0 1.5rem;line-height:1.7;color:#0f172a">
              <h1 style="font-size:1.4rem;margin-bottom:.25rem">{$domain}</h1>
              <hr style="border:none;border-top:1px solid #e2e8f0;margin:1.5rem 0">
              <p>เว็บไซต์นี้ถูกสร้างเรียบร้อยแล้ว และกำลังทำงานด้วย PHP <?= PHP_VERSION ?></p>
              <p style="color:#64748b;font-size:.9rem">
                อัปโหลดไฟล์ของคุณไปที่ <code>public/</code> เพื่อแทนที่หน้านี้
              </p>
            </body>
            </html>

            HTML;
    }

    /**
     * @param Site $site
     */
    private function suspendedPage(Site $site): string
    {
        $domain = htmlspecialchars($site->domain, ENT_QUOTES, 'UTF-8');

        return <<<HTML
            <!doctype html>
            <html lang="th">
            <meta charset="utf-8">
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <title>เว็บไซต์ถูกระงับชั่วคราว</title>
            <body style="font-family:system-ui,-apple-system,'Segoe UI',sans-serif;max-width:520px;margin:5rem auto;padding:0 1.5rem;text-align:center;line-height:1.7;color:#0f172a">
              <h1 style="font-size:1.3rem">เว็บไซต์ถูกระงับชั่วคราว</h1>
              <p style="color:#64748b">{$domain} ไม่สามารถให้บริการได้ในขณะนี้</p>
              <p style="color:#94a3b8;font-size:.9rem">กรุณาติดต่อผู้ดูแลระบบ</p>
            </body>
            </html>

            HTML;
    }
}
