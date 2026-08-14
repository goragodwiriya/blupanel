<?php

declare (strict_types = 1);

namespace Phpcp\Agent\Capability;

use Phpcp\Agent\Capability;
use Phpcp\Agent\Context;
use Phpcp\Agent\Executor\Executor;
use Phpcp\Agent\PermissionDenied;
use Phpcp\Agent\ValidationError;
use Phpcp\Domain\DiskQuota;
use Phpcp\Domain\FileRoots;
use Phpcp\Domain\FileScope;
use Phpcp\Support\PathGuard;
use Phpcp\Support\Validator;

/**
 * ฐานร่วมของ capability ทุกตัวในตัวจัดการไฟล์
 *
 * ทุกงานไฟล์เดินตามลำดับเดียวกันเสมอ และลำดับนี้คือสิ่งที่กันไม่ให้ตัวจัดการไฟล์
 * กลายเป็นช่องอ่าน/เขียนไฟล์ทั้งเครื่อง:
 *
 *   1. ตรวจรูปแบบเส้นทาง (PathGuard::clean) ก่อนแตะดิสก์
 *   2. ตรวจว่าผู้สั่งงานเป็นเจ้าของเว็บไซต์นั้นจริง (กัน IDOR)
 *   3. ลดสิทธิ์เป็นผู้ใช้ของเว็บไซต์ (asUser) แล้วค่อยทำงานไฟล์
 *   4. ตรวจเส้นทางหลังคลาย symlink อีกครั้งในสิทธิ์ที่ลดแล้ว
 *
 * ขั้นที่ 4 ต้องอยู่ข้างใน asUser เท่านั้น เพราะ realpath() ที่รันด้วย root
 * จะเห็นไฟล์ที่ผู้ใช้เว็บไซต์เข้าไม่ถึง ผลการตรวจจึงไม่ตรงกับความจริงตอนอ่านเขียน
 */
abstract class FileCapability implements Capability
{
    /**
     * @return mixed
     */
    public function permission(): string
    {
        return $this->isMutating() ? 'file.manage' : 'file.view';
    }

    /**
     * ส่วนของ argument ที่ทุกตัวใช้เหมือนกัน — คีย์ขอบเขต กับ เส้นทางสัมพัทธ์ในขอบเขตนั้น
     *
     * ผู้ใช้ไม่เคยส่ง "เส้นทางเต็ม" มาเลย ส่งได้แค่คีย์ขอบเขตที่ระบบประกาศไว้
     * กับเส้นทางที่อยู่ภายใต้ขอบเขตนั้น — เส้นทางจริงประกอบขึ้นที่ agent ที่เดียว
     *
     * @param array<string,mixed> $args
     * @return array{root:string,path:string}
     */
    protected static function baseArgs(array $args): array
    {
        return [
            'root' => Validator::pattern(
                Validator::requireString($args, 'root', 64),
                '/^[a-z][a-z0-9-]{0,63}$/',
                'คีย์ขอบเขตไฟล์ไม่ถูกต้อง',
            ),
            'path' => PathGuard::clean(Validator::optionalString($args, 'path', max: 4096))
        ];
    }

    /**
     * อ่านค่าจริง/เท็จจาก argument — ฟอร์มบนหน้าเว็บส่งค่ามาเป็นข้อความเสมอ
     *
     * ยอมรับเฉพาะรูปแบบที่ตั้งใจว่า "จริง" เท่านั้น ค่าอื่นทั้งหมดถือเป็นเท็จ
     * ไม่ใช้ (bool) ตรง ๆ เพราะสตริง '0' กับ 'false' ให้ผลต่างกันจนสับสน
     *
     * @param array<string,mixed> $args
     */
    protected static function flag(array $args, string $key): bool
    {
        $value = $args[$key] ?? false;

        return $value === true || $value === 1 || $value === '1' || $value === 'true';
    }

    /**
     * เลือกขอบเขตไฟล์พร้อมตรวจสิทธิ์ — ด่านเดียวที่ตัดสินว่าใครเปิดอะไรได้
     *
     * ตรวจซ้ำที่ชั้น agent ทั้งที่ชั้นเว็บตรวจแล้ว เพราะ agent ต้องยืนหยัดได้ลำพัง
     * ถ้าชั้นเว็บถูกเจาะ (ARCHITECTURE §4.2) — รายละเอียดนโยบายอยู่ใน FileRoots
     *
     * @param array<string,mixed> $args
     * @throws PermissionDenied
     */
    protected function scope(Context $context, array $args): FileScope
    {
        return FileRoots::require($context->actor, $context->db, (string) $args['root']);
    }

    /**
     * โควตาดิสก์ของเจ้าของเว็บรับการเขียนครั้งนี้ไหวหรือไม่
     *
     * **ตัวจัดการไฟล์ไม่เคยมีด่านนี้เลย** ทั้งที่เป็นทางที่เขียนข้อมูลเข้าเครื่องได้ตรง
     * ที่สุด — อัปโหลด เขียนไฟล์ แตกไฟล์ บีบไฟล์ ล้วนเดินผ่านที่นี่โดยไม่มีใครถามว่า
     * บัญชีนี้ยังมีที่เหลือไหม · บัญชีเดียวจึงเติมดิสก์ที่ใช้ร่วมกันจนเว็บของลูกค้า
     * รายอื่นเขียน session หรือไฟล์อัปโหลดไม่ได้
     *
     * ขอบเขตระดับเครื่องข้ามด่านนี้ เพราะเปิดให้เฉพาะผู้ดูแลระดับเซิร์ฟเวอร์ ซึ่งไม่ถูก
     * จำกัดโควตาอยู่แล้ว (กฎเดียวกับ QuotaChecker) และไฟล์ระบบไม่ได้เป็นของบัญชีไหน
     *
     * @param int $bytes ขนาดที่กำลังจะเขียน · {@see DiskQuota::UNKNOWN} เมื่อยังไม่รู้
     * @throws ValidationError
     */
    protected function assertQuotaAllows(Context $context, FileScope $scope, int $bytes = DiskQuota::UNKNOWN): void
    {
        if (!$scope->isSite() || $scope->siteId < 1) {
            return;
        }

        $row = $context->db->first(
            'SELECT owner_user_id FROM sites WHERE id = :id',
            ['id' => $scope->siteId],
        );

        if ($row === null) {
            return;
        }

        DiskQuota::assertFits($context->db, (int) $row['owner_user_id'], $bytes);
    }

    /**
     * รากของขอบเขตตามที่ executor ของโหมดนั้นมองเห็น
     *
     * โหมดทดสอบเติม prefix ให้อัตโนมัติ เส้นทางที่ capability ใช้จึงไม่ต้องรู้เรื่องโหมดเลย
     */
    protected function root(Executor $executor, FileScope $scope): string
    {
        return $executor->path($scope->root);
    }

    /**
     * ทำงานไฟล์ในสิทธิ์ของเว็บไซต์ โดยส่ง "ตัวคลี่เส้นทาง" ให้แทนเส้นทางสำเร็จรูป
     *
     * งานอย่างย้าย/คัดลอก/บีบอัดมีทั้งต้นทางและปลายทาง และทั้งคู่ต้องถูกคลี่ symlink
     * ตรวจซ้ำ *ภายในสิทธิ์ที่ลดแล้ว* เหมือนกัน จึงต้องส่งตัวคลี่เข้าไปให้เรียกเองได้หลายครั้ง
     *
     * @param callable(callable(string,bool=):string,string):array<string,mixed> $work
     * @return array<string,mixed>
     */
    protected function withSite(Executor $executor, FileScope $scope, callable $work): array
    {
        $root = $this->root($executor, $scope);
        $mutating = $this->isMutating();

        // ขอบเขตของเว็บไซต์ลดสิทธิ์เป็นเจ้าของเว็บก่อนแตะไฟล์
        // ขอบเขตระดับเซิร์ฟเวอร์ทำงานด้วยสิทธิ์ของ agent เอง เพราะไฟล์ระบบ
        // ไม่ได้เป็นของผู้ใช้คนใดคนหนึ่ง — จึงเปิดให้เฉพาะผู้ดูแลระดับเซิร์ฟเวอร์
        return $executor->asUser($scope->systemUser, static function () use ($executor, $root, $work, $mutating) {
            $realRoot = $executor->realPath($root);
            if ($realRoot === null) {
                throw new ValidationError('ยังไม่มีไดเรกทอรีนี้บนเครื่อง');
            }

            $resolve = static function (string $relative, bool $mustExist = true) use ($executor, $root, $mutating): string {
                $real = PathGuard::resolve($executor, $root, PathGuard::join($root, $relative), $mustExist);

                // ไฟล์ของ Control Panel เองแตะไม่ได้ทั้งอ่านและเขียน
                // กันทั้งการทำลายตัวเองจนกู้ไม่ได้ และการอ่าน panel.db ที่มี hash รหัสผ่าน
                FileRoots::assertReadable($real);

                if ($mutating) {
                    FileRoots::assertWritable($real);
                }

                return $real;
            };

            return $work($resolve, $realRoot);
        });
    }

    /**
     * ทำงานไฟล์กับเส้นทางเดียว พร้อมส่งเส้นทางจริงที่ตรวจแล้วให้
     *
     * @param callable(string,string):array<string,mixed> $work รับ (รากจริง, เป้าหมายจริง)
     * @param bool $mustExist false เมื่อเป้าหมายคือสิ่งที่กำลังจะถูกสร้างขึ้นใหม่
     * @return array<string,mixed>
     */
    protected function withPath(
        Executor $executor,
        FileScope $scope,
        string $relative,
        callable $work,
        bool $mustExist = true,
    ): array {
        return $this->withSite(
            $executor,
            $scope,
            static fn(callable $resolve, string $realRoot): array
            => $work($realRoot, $resolve($relative, $mustExist)),
        );
    }

    /**
     * แปลงข้อมูลจาก stat เป็นรูปที่หน้าจอใช้ได้ทันที
     *
     * @param array{type:string,size:int,mode:int,mtime:int,uid:int,gid:int,link:?string} $info
     * @return array<string,mixed>
     */
    protected static function describe(string $name, string $relative, array $info): array
    {
        return [
            'name' => $name,
            'path' => $relative,
            'type' => $info['type'],
            'size' => $info['size'],
            'mode' => sprintf('%04o', $info['mode']),
            'mtime' => $info['mtime'],
            'owner' => $info['uid'],
            'group' => $info['gid'],
            'link' => $info['link']
        ];
    }
}
