<?php

declare(strict_types=1);

namespace Phpcp\Driver\Php;

use Phpcp\Agent\ExecutionFailed;
use Phpcp\Agent\Executor\Executor;
use Phpcp\Domain\ServiceCatalog;
use Phpcp\Domain\Site;
use Phpcp\Driver\Template;
use Phpcp\Support\Validator;

/**
 * จัดการ FPM pool — ARCHITECTURE §11
 *
 * **หนึ่ง pool ต่อ (ผู้ใช้ × เวอร์ชัน PHP)** ตั้งแต่ migration 0006 — เดิมเป็นหนึ่ง pool
 * ต่อหนึ่งเว็บ ทำให้ลูกค้าที่มี 5 เว็บบน PHP 8.4 กิน 5 pool โดยไม่ได้แยกอะไรที่ควรแยก
 * เพราะทั้ง 5 เว็บเป็นทรัพย์สินของคนเดียวกันอยู่แล้ว
 *
 * pool รันด้วย uid ของเจ้าของ มี open_basedir จำกัดอยู่ในบ้านของเจ้าของ และปิด shell
 * function ทั้งหมด — นี่คือกลไกที่ทำให้เว็บที่ถูกแฮ็กไปอ่านไฟล์ของ**ลูกค้ารายอื่น**
 * หรือยกระดับเป็น root ไม่ได้ · เว็บของลูกค้าคนเดียวกันอ่านกันได้โดยตั้งใจ
 */
final class FpmManager
{
    public function __construct(private readonly Template $templates)
    {
    }

    /**
     * ไฟล์ pool ของเจ้าของเว็บนี้สำหรับเวอร์ชัน PHP ที่เว็บนี้ใช้
     *
     * ไฟล์เดียวรับทุกเว็บของเจ้าของที่ใช้เวอร์ชันเดียวกัน — เนื้อหาจึงอ้างถึง**บ้านของ
     * ผู้ใช้** ไม่ใช่โฟลเดอร์ของเว็บใดเว็บหนึ่ง · ถ้าเขียนแบบอ้างเว็บใดเว็บหนึ่ง
     * เว็บที่สร้างทีหลังจะไปเขียนทับ open_basedir ของเว็บก่อนหน้าจนพังทั้งคู่
     */
    /**
     * @param list<string> $extraPaths โฟลเดอร์นอกบ้านที่ต้องอยู่ใน open_basedir ด้วย
     *                                 (Domain Pointer ของเว็บใด ๆ ที่ใช้ pool นี้)
     */
    public function renderPool(
        Site $site,
        string $webserverUser,
        Executor $executor,
        array $extraPaths = [],
    ): string {
        $owner = $site->owner;
        $version = $site->phpVersion;

        // pool เดียวรับหลายเว็บ open_basedir จึงต้องเป็น**สหภาพ**ของบ้านกับ Domain Pointer
        // ของทุกเว็บที่ใช้ pool นี้ · ถ้าเขียนแค่บ้าน เว็บที่ชี้ docroot ออกไปข้างนอก
        // จะเปิดไฟล์ของตัวเองไม่ได้เลยและขึ้น 500 ทันทีที่ deploy
        $allowed = [$executor->path($owner->home())];

        foreach ($extraPaths as $path) {
            $mapped = $executor->path($path);

            if (!in_array($mapped, $allowed, true)) {
                $allowed[] = $mapped;
            }
        }

        $allowed[] = '/usr/share/php';
        $allowed[] = '/tmp';

        // เส้นทางในไฟล์ pool ต้องถูกแมปตามโหมดเช่นเดียวกับใน vhost
        return $this->templates->render('fpm/pool.conf.tpl', [
            'POOL_NAME' => $owner->poolName($version),
            'ACCOUNT_USER' => $owner->username,
            'PHP_VERSION' => $version,
            'FPM_SOCKET' => $executor->path($owner->fpmSocket($version)),
            'WEBSERVER_USER' => $webserverUser,
            'HOME' => $executor->path($owner->home()),
            'OPEN_BASEDIR' => implode(':', $allowed),
            'TMP_DIR' => $executor->path($owner->tmpDir()),
            'SLOW_LOG' => $executor->path($owner->phpSlowLog($version)),
            'PHP_ERROR_LOG' => $executor->path($owner->phpErrorLog($version)),
            'MAX_CHILDREN' => $site->maxChildren,
            'MEMORY_LIMIT' => $site->memoryLimitMb . 'M',
            'UPLOAD_LIMIT' => $site->uploadLimitMb . 'M',
        ]);
    }

    /**
     * เวอร์ชัน PHP ที่ติดตั้งอยู่จริงบนเครื่อง
     *
     * @return list<string>
     */
    public function installedVersions(Executor $executor): array
    {
        $found = [];

        foreach (ServiceCatalog::PHP_VERSIONS as $version) {
            if ($executor->exists($executor->path('/etc/php/' . $version . '/fpm'))) {
                $found[] = $version;
            }
        }

        return $found;
    }

    public function isVersionInstalled(Executor $executor, string $version): bool
    {
        return in_array(Validator::phpVersion($version), $this->installedVersions($executor), true);
    }

    /**
     * ตรวจไฟล์ pool ด้วย php-fpm เอง ก่อนสั่ง reload
     *
     * php-fpm -t อ่าน config หลักของเวอร์ชันนั้นทั้งชุดรวม pool.d ทั้งหมด
     * จึงจับได้ทั้ง syntax ผิดและชื่อ pool ซ้ำ
     *
     * @return array{0:bool,1:string}
     */
    public function testConfig(Executor $executor, string $version): array
    {
        $version = Validator::phpVersion($version);
        $binary = '/usr/sbin/php-fpm' . $version;

        if (!$executor->exists($binary)) {
            // ไม่มี binary ให้ตรวจ = ตรวจไม่ได้ ไม่ใช่ตรวจแล้วผ่าน
            // คืน true เพราะ configtest ของเว็บเซิร์ฟเวอร์เป็นด่านหลักอยู่แล้ว
            // แต่บอกไว้ในข้อความให้เห็นชัดว่าข้ามการตรวจชั้นนี้ไป
            return [true, "ข้ามการตรวจ pool: ไม่พบ {$binary} บนเครื่องนี้"];
        }

        // ต้องระบุ -y เสมอ ไม่ใช่ปล่อยให้ php-fpm ใช้ config ที่ compile มา
        // ไม่อย่างนั้นในโหมด sandbox จะกลายเป็นการตรวจ config จริงของเครื่อง
        // ทั้งที่ไฟล์ที่เพิ่งเขียนอยู่ใน prefix — ตรวจผิดไฟล์แล้วบอกว่าผ่าน
        $config = $executor->path('/etc/php/' . $version . '/fpm/php-fpm.conf');

        if (!$executor->exists($config)) {
            return [true, "ข้ามการตรวจ pool: ไม่พบ {$config}"];
        }

        $result = $executor->exec([$binary, '-t', '-y', $config], timeout: 20);
        $output = trim($result->stderr) !== '' ? $result->stderr : $result->stdout;

        return [$result->ok(), $output];
    }

    /**
     * reload ที่ล้มเหลวต้องดังเสมอ
     *
     * เคยปล่อยผ่านโดยไม่ดูรหัสออก ผลคือ panel รายงานว่า "สร้างเว็บไซต์เรียบร้อยแล้ว"
     * ทั้งที่ FPM ยังไม่ได้สร้าง socket ของ pool ใหม่ เว็บจึงตอบ 503 ทุกคำขอ
     * ผู้ใช้ไม่มีทางรู้เลยว่าต้องไปสั่ง reload เอง
     *
     * ไฟล์ค่าตั้งถูกเขียนและตรวจผ่านไปแล้วตอนถึงจุดนี้ ข้อความจึงบอกให้ชัด
     * ว่าค่าตั้งไม่ได้หาย แค่บริการยังไม่รับไปใช้
     */
    public function reload(Executor $executor, string $version): void
    {
        $unit = ServiceCatalog::fpmUnit(Validator::phpVersion($version));
        $result = $executor->exec([$executor->path('/usr/bin/systemctl'), 'reload', $unit], timeout: 30);

        if (!$result->ok()) {
            throw new ExecutionFailed(sprintf(
                "เขียนค่าตั้งเรียบร้อยแล้วแต่สั่ง reload %s ไม่สำเร็จ — เว็บไซต์จะยังไม่ทำงานจนกว่าจะ reload สำเร็จ\n\n%s",
                $unit,
                trim($result->stderr ?: $result->stdout),
            ));
        }
    }

    /**
     * extension ที่เปิดใช้งานอยู่ของเวอร์ชันนั้น อ่านจากไดเรกทอรี conf.d
     *
     * @return list<string>
     */
    public function extensions(Executor $executor, string $version): array
    {
        $version = Validator::phpVersion($version);
        $dir = $executor->path('/etc/php/' . $version . '/fpm/conf.d');

        if (!$executor->exists($dir)) {
            return [];
        }

        $found = [];
        foreach (glob($dir . '/*.ini') ?: [] as $file) {
            // ชื่อไฟล์รูปแบบ 20-mbstring.ini
            if (preg_match('/^\d+-([a-z0-9_]+)\.ini$/i', basename($file), $m) === 1) {
                $found[] = strtolower($m[1]);
            }
        }

        sort($found);

        return array_values(array_unique($found));
    }
}
