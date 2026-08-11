<?php

declare(strict_types=1);

namespace Phpcp\Agent\Capability;

use Phpcp\Agent\Context;
use Phpcp\Agent\Executor\Executor;
use Phpcp\Agent\ValidationError;
use Phpcp\Domain\ServiceCatalog;
use Phpcp\Driver\ConfigTransaction;
use Phpcp\Driver\Security\Fail2banManager;
use Phpcp\Support\Validator;

/**
 * ลบเว็บไซต์ — งานที่ย้อนกลับไม่ได้ จึงมีมาตรการเพิ่มสองชั้น
 *
 *   1. ต้องส่งชื่อโดเมนมายืนยันให้ตรงกับที่จะลบ (ป้องกันการกดผิดรายการ)
 *   2. ไฟล์ไม่ถูกลบทันที แต่ย้ายไปที่ถังพัก แล้วค่อยลบจริงตามนโยบายเวลา
 *      ตาม SECURITY §4 — ลบผิดแล้วยังกู้คืนได้
 *
 * ฐานข้อมูล MySQL ของเว็บไซต์ไม่ถูกแตะที่นี่ (มาในเฟส 3) แถวในตาราง databases_
 * จะถูกปลดการผูกด้วย ON DELETE SET NULL ไม่ใช่ถูกลบตาม
 */
final class SiteDelete extends SiteCapability
{
    public static function name(): string
    {
        return 'site.delete';
    }

    public function permission(): string
    {
        return 'site.delete';
    }

    public function isMutating(): bool
    {
        return true;
    }

    public function summary(): string
    {
        return 'ลบเว็บไซต์ พร้อมย้ายไฟล์ไปถังพักก่อนลบจริง';
    }

    public function validate(array $args): array
    {
        return [
            'site_id' => Validator::requireInt($args, 'site_id', 1),
            'confirm_domain' => Validator::domain(Validator::requireString($args, 'confirm_domain', 253)),
        ];
    }

    public function run(array $args, Executor $executor, Context $context): array
    {
        $repository = $this->repository($context);
        $provisioner = $this->provisioner($context);

        $site = $this->loadSite($context, $args['site_id']);

        // ชั้นที่ 1 — ชื่อที่พิมพ์ยืนยันต้องตรงกับเว็บไซต์ที่กำลังจะลบจริง ๆ
        if ($args['confirm_domain'] !== $site->domain) {
            throw new ValidationError(
                'ชื่อโดเมนที่ยืนยันไม่ตรงกับเว็บไซต์ที่จะลบ — ยกเลิกการลบเพื่อความปลอดภัย',
            );
        }

        // เวอร์ชัน PHP ที่เจ้าของยังใช้อยู่**หลังจากลบเว็บนี้แล้ว** — ไฟล์ pool ของเวอร์ชัน
        // เหล่านั้นต้องอยู่ต่อ เพราะเว็บพี่น้องของลูกค้าคนเดียวกันยังใช้อยู่
        $stillUsed = $repository->phpVersionsOwnedBy($site->owner->userId, exceptSiteId: $site->id);

        // ถอน jail ของ fail2ban ก่อนทุกอย่าง — ต้องอยู่ก่อนการย้ายไฟล์ (PLAN-V2 เฟส E5)
        //
        // jail ที่ค้างอยู่จะเฝ้า access log ที่ถูกย้ายไปถังพักแล้ว · fail2ban เตือนทุกครั้ง
        // ที่ reload ว่าหาไฟล์ไม่เจอ และถ้าโดเมนเดิมถูกสร้างใหม่ในภายหลัง jail เก่าที่ยัง
        // ชี้ไฟล์ผิดจะทำให้การเปิดใช้ครั้งใหม่ล้มเหลวโดยไม่มีใครรู้ว่าเพราะอะไร
        //
        // ห้ามให้ความล้มเหลวตรงนี้หยุดการลบเว็บ — ผู้ใช้สั่งลบและยืนยันชื่อโดเมนแล้ว
        // การค้างไว้ครึ่งทางเพราะ fail2ban มีปัญหาแย่กว่าการเหลือไฟล์ jail กำพร้า
        try {
            (new Fail2banManager($executor))->remove($site);
        } catch (\Throwable) {
            // ไฟล์ที่เหลือค้างถูกเก็บกวาดได้ด้วยมือ · ไม่มีผลต่อเว็บอื่นเพราะแยกไฟล์ต่อเว็บ
        }

        // ถอน config ออกจากระบบก่อน เว็บจะได้หยุดให้บริการทันที
        $transaction = new ConfigTransaction($executor);
        $provisioner->stageRemoval($transaction, $site, ServiceCatalog::PHP_VERSIONS, $stillUsed);
        $transaction->commit(static fn (): array => $provisioner->webserver()->testConfig($executor));

        $provisioner->reload($executor, $site);

        // ชั้นที่ 2 — ย้ายไฟล์ไปถังพัก ไม่ลบทิ้งทันที
        $trash = $this->moveToTrash($executor, $site->root(), $site->domain);

        // FPM pool ใช้ร่วมกับเว็บอื่นของเจ้าของคนเดียวกัน จึงเขียนใหม่จากรายชื่อเว็บที่
        // เหลืออยู่จริง แทนที่จะลบไฟล์ pool ทิ้ง — ลบทิ้งเมื่อไรเว็บพี่น้องล่มทันที
        $repository->delete($site->id);

        $remaining = $repository->countOwnedBy($site->owner->userId);

        if ($remaining === 0) {
            // ไม่เหลือเว็บแล้วจึงคืนบัญชีระบบ · ตราบใดที่ยังมีเว็บอยู่แม้เว็บเดียว
            // การลบบัญชีจะทำให้ไฟล์ของเว็บนั้นกลายเป็นของ uid ที่ไม่มีเจ้าของทันที
            $provisioner->account()->remove($executor, $site->owner);
            $context->db->update('users', [
                'system_user' => null,
                'uid' => 0,
                'gid' => 0,
                'updated_at' => time(),
            ], ['id' => $site->owner->userId]);
        }

        if ($this->isLocalEnvironment($executor, $context)) {
            if (str_ends_with($site->domain, '.test')) {
                $this->updateHostsFile($executor, $site->domain, false);
            }
            foreach ($site->aliases as $alias) {
                if (str_ends_with($alias, '.test')) {
                    $this->updateHostsFile($executor, $alias, false);
                }
            }
        }

        return [
            'site_id' => $site->id,
            'domain' => $site->domain,
            'trash_path' => $trash,
            'account_removed' => $remaining === 0,
            'message' => "ลบเว็บไซต์ {$site->domain} แล้ว — ไฟล์ถูกย้ายไปที่ถังพัก กู้คืนได้จนกว่าจะถูกล้าง",
        ];
    }

    /**
     * ย้ายบ้านของเว็บไซต์ไปถังพัก คืนเส้นทางปลายทาง
     *
     * ใช้ rename ซึ่งเป็น atomic เมื่ออยู่ filesystem เดียวกัน — ไม่มีสถานะครึ่ง ๆ กลาง ๆ
     */
    private function moveToTrash(Executor $executor, string $root, string $domain): string
    {
        $source = $executor->path($root);
        $target = $executor->path('/var/lib/phpcp/trash/' . $domain . '-' . date('Ymd-His'));

        if (!$executor->exists($source)) {
            return '';
        }

        $executor->makeDirectory(dirname($target), 0750);

        if (!@rename($source, $target)) {
            // ข้าม filesystem กัน rename ไม่ได้ — ใช้ mv ซึ่งจัดการกรณีนี้ให้
            $executor->exec(['/usr/bin/mv', $source, $target], timeout: 120);
        }

        return $target;
    }
}
