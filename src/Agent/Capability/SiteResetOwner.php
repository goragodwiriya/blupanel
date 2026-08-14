<?php

declare(strict_types=1);

namespace Phpcp\Agent\Capability;

use Phpcp\Agent\Capability;
use Phpcp\Agent\Context;
use Phpcp\Agent\ExecutionFailed;
use Phpcp\Driver\SiteProvisioner;
use Phpcp\Agent\Executor\Executor;
use Phpcp\Agent\SelfProtection;
use Phpcp\Agent\ValidationError;
use Phpcp\Domain\Site;
use Phpcp\Support\Validator;

/**
 * ตั้งเจ้าของไฟล์ของเว็บไซต์ให้กลับเป็นค่าที่ถูกต้อง — ทีละเว็บ
 *
 * ## ทำไมต้องมี
 *
 * เจ้าของไฟล์เพี้ยนได้ง่ายกว่าที่คิด และเป็นสาเหตุอันดับหนึ่งของอาการ
 * "อัปโหลดไฟล์แล้วเว็บขึ้น 403" หรือ "ปลั๊กอินอัปเดตตัวเองไม่ได้":
 *
 *   - แตกไฟล์สำรองด้วย root แล้วไฟล์ทั้งชุดกลายเป็นของ root
 *   - คัดลอกไฟล์เข้ามาด้วย scp/rsync จากผู้ใช้อื่น
 *   - รัน composer หรือ npm ด้วย sudo
 *   - ย้ายเว็บมาจากเครื่องเก่าที่ใช้เลข uid คนละชุด
 *   - เว็บที่สร้างก่อนระบบแก้บั๊กเรื่องกลุ่มเจ้าของ (เคยตั้งเป็น <ผู้ใช้>:<ผู้ใช้>
 *     ซึ่งทำให้เว็บเซิร์ฟเวอร์เดินผ่านไดเรกทอรีไม่ได้เลย)
 *
 * ## ค่าที่ถูกต้องคืออะไร
 *
 * เจ้าของ = ผู้ใช้ของเว็บไซต์นั้น (PHP-FPM รันด้วย uid นี้ จึงต้องเขียนไฟล์ได้)
 * กลุ่ม   = กลุ่มของเว็บเซิร์ฟเวอร์ (Apache/nginx ต้องอ่านและเดินผ่านไดเรกทอรีได้)
 *
 * ## ขอบเขต
 *
 * ทำได้ทีละเว็บไซต์เท่านั้น ไม่มีปุ่ม "ซ่อมทุกเว็บพร้อมกัน" โดยเจตนา —
 * คำสั่ง chown ที่ครอบคลุมทั้งเครื่องคือคำสั่งที่พังแล้วกู้ยากที่สุดคำสั่งหนึ่ง
 * และการทำทีละเว็บทำให้เห็นผลก่อนว่าถูกต้องแล้วค่อยทำเว็บถัดไป
 */
final class SiteResetOwner extends SiteCapability implements Capability
{
    public static function name(): string
    {
        return 'site.reset_owner';
    }

    public function permission(): string
    {
        return 'site.edit';
    }

    public function isMutating(): bool
    {
        return true;
    }

    public function summary(): string
    {
        return 'ตั้งเจ้าของไฟล์ของเว็บไซต์ให้กลับเป็นค่าที่ถูกต้อง';
    }

    public function validate(array $args): array
    {
        return [
            'site_id' => Validator::requireInt($args, 'site_id', 1),
            // ซ่อมสิทธิ์ (0755/0644) ด้วยหรือไม่ — แยกจากการเปลี่ยนเจ้าของ
            // เพราะบางเว็บตั้งสิทธิ์พิเศษไว้เองอย่างจงใจ เช่นสคริปต์ที่ต้อง execute
            'fix_permissions' => (bool) ($args['fix_permissions'] ?? false),
        ];
    }

    public function run(array $args, Executor $executor, Context $context): array
    {
        $this->assertSiteAccess($context, $args['site_id']);

        $site = $this->loadSite($context, $args['site_id']);
        $root = $site->root();
        $resolved = $executor->path($root);

        // กันการเผลอสั่งกับเส้นทางของ panel เอง แม้ฐานข้อมูลจะถูกแก้ให้ชี้ไปที่นั่น
        SelfProtection::assertPath($root);

        if (!$executor->exists($resolved)) {
            throw new ValidationError("ไม่พบไดเรกทอรีของเว็บไซต์: {$root}");
        }

        // ตรวจซ้ำหลัง realpath — symlink ที่ชี้ออกนอกพื้นที่ของเว็บไซต์จะทำให้
        // chown -R ไล่ตามไปเปลี่ยนเจ้าของไฟล์นอกขอบเขตได้
        $real = $executor->realPath($resolved);

        if ($real === null || $real !== rtrim($resolved, '/')) {
            throw new ValidationError(
                'เส้นทางของเว็บไซต์ไม่ตรงกับที่คาดไว้หลังแปลง symlink — ยกเลิกเพื่อความปลอดภัย',
            );
        }

        $owner = $site->systemUser();
        $group = $this->provisioner($context)->webserver()->runAsGroup();

        $before = $this->sample($executor, $site);

        /*
         * ทุกไดเรกทอรีของเว็บ ไม่ใช่แค่ `root()`
         *
         * เดิม chown แค่ `root()` ตัวเดียว ซึ่งใช้ได้ตอนเลย์เอาต์เก็บทุกอย่างไว้ใต้กล่อง
         * เดียวกัน · **ในเลย์เอาต์ cpanel `root()` คือ `.phpcp/<โดเมน>`** ส่วนไฟล์เว็บ
         * อยู่ที่ `public_html` และ log อยู่ที่ `logs/<โดเมน>` ซึ่งอยู่คนละที่ —
         * ปุ่มซ่อมเจ้าของไฟล์จึงรายงานว่าสำเร็จโดยที่ไฟล์ที่พังจริงไม่ถูกแตะเลย
         *
         * ใช้รายการเดียวกับตอนสร้างเว็บ (SiteProvisioner::ownershipTargets) เพื่อให้
         * "ซ่อมให้กลับเป็นค่าที่ถูกต้อง" หมายถึงค่าเดียวกับที่ระบบตั้งไว้ตอนสร้างจริง ๆ
         */
        foreach (SiteProvisioner::ownershipTargets($site) as $target) {
            $path = $executor->path($target);

            if (!$executor->exists($path)) {
                continue;
            }

            // -h เปลี่ยนตัว symlink เอง ไม่ไล่ตามไปเปลี่ยนปลายทาง
            $result = $executor->exec(
                ['/usr/bin/chown', '-Rh', $owner . ':' . $group, $path],
                timeout: 300,
            );

            if (!$result->ok()) {
                throw new ExecutionFailed('เปลี่ยนเจ้าของไฟล์ไม่สำเร็จ: ' . trim($result->stderr));
            }
        }

        $changedModes = 0;

        if ($args['fix_permissions']) {
            $changedModes = $this->resetModes($executor, $real);
        }

        $after = $this->sample($executor, $site);

        return [
            'site_id' => $site->id,
            'domain' => $site->domain,
            'owner' => $owner,
            'group' => $group,
            'path' => $root,
            'before' => $before,
            'after' => $after,
            'permissions_reset' => $args['fix_permissions'],
            'permission_passes' => $changedModes,
            'message' => sprintf(
                'ตั้งเจ้าของไฟล์ของ %s เป็น %s:%s เรียบร้อยแล้ว%s',
                $site->domain,
                $owner,
                $group,
                $args['fix_permissions']
                    ? ' และตั้งสิทธิ์ไดเรกทอรีเป็น 0750 ไฟล์เป็น 0640'
                    : '',
            ),
        ];
    }

    /**
     * ตั้งสิทธิ์กลับเป็นค่ามาตรฐานของระบบ
     *
     * 0750 / 0640 ไม่ใช่ 0755 / 0644 ที่คุ้นตากว่า — เพราะแต่ละเว็บมีผู้ใช้ของตัวเอง
     * และไฟล์ตั้งเป็นกลุ่มของเว็บเซิร์ฟเวอร์อยู่แล้ว การให้สิทธิ์ "ผู้ใช้อื่น"
     * จึงหมายถึงให้ผู้ใช้ของ *เว็บไซต์อื่น* อ่านได้ ซึ่งทำลายการแยกเว็บออกจากกัน
     */
    private function resetModes(Executor $executor, string $root): int
    {
        $count = 0;

        foreach ([['-type', 'd', '0750'], ['-type', 'f', '0640']] as [$flag, $kind, $mode]) {
            $result = $executor->exec(
                ['/usr/bin/find', $root, $flag, $kind, '-exec', '/usr/bin/chmod', $mode, '{}', '+'],
                timeout: 300,
            );

            if ($result->ok()) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * เจ้าของปัจจุบันของไดเรกทอรีหลัก ใช้แสดงผลก่อน/หลังให้เห็นว่าเปลี่ยนอะไรไป
     *
     * @return array<string,string>
     */
    private function sample(Executor $executor, Site $site): array
    {
        $out = [];

        foreach (['root' => $site->root(), 'public' => $site->docroot()] as $label => $path) {
            $resolved = $executor->path($path);

            if (!$executor->exists($resolved)) {
                continue;
            }

            $stat = $executor->stat($resolved);

            if ($stat === null) {
                continue;
            }

            $out[$label] = sprintf(
                '%s:%s %04o',
                self::nameOf('posix_getpwuid', (int) ($stat['uid'] ?? -1), 'name'),
                self::nameOf('posix_getgrgid', (int) ($stat['gid'] ?? -1), 'name'),
                ((int) ($stat['mode'] ?? 0)) & 0777,
            );
        }

        return $out;
    }

    /**
     * แปลง uid/gid เป็นชื่อ ถ้าแปลงไม่ได้ให้แสดงตัวเลขไปตามตรง
     *
     * แปลงไม่ได้เป็นเรื่องปกติเมื่อเว็บถูกย้ายมาจากเครื่องอื่นที่ใช้เลข uid คนละชุด —
     * ซึ่งเป็นหนึ่งในสาเหตุหลักที่ต้องใช้คำสั่งนี้ การแสดงเลขดิบจึงมีประโยชน์กว่า
     * การแสดงเครื่องหมายคำถาม
     */
    private static function nameOf(string $function, int $id, string $key): string
    {
        if ($id < 0) {
            return '?';
        }

        if (!function_exists($function)) {
            return (string) $id;
        }

        $info = @$function($id);

        return is_array($info) && isset($info[$key]) ? (string) $info[$key] : (string) $id;
    }
}
