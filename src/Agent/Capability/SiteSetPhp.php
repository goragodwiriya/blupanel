<?php

declare(strict_types=1);

namespace Phpcp\Agent\Capability;

use Phpcp\Agent\Context;
use Phpcp\Agent\Executor\Executor;
use Phpcp\Agent\ValidationError;
use Phpcp\Driver\ConfigTransaction;
use Phpcp\Support\Validator;

/**
 * เปลี่ยนเวอร์ชัน PHP ของเว็บไซต์เดียว — PROMPT.md
 *
 *   example.com        → PHP 8.4
 *   legacy.example.com → PHP 7.4
 *
 * ตั้งแต่ migration 0006 pool ใช้ร่วมกันในระดับ (เจ้าของ × เวอร์ชัน PHP) การสลับเวอร์ชัน
 * ของเว็บเดียวจึงต้องระวังเว็บพี่น้องของเจ้าของคนเดียวกัน: ไฟล์ pool ของเวอร์ชันเดิม
 * จะถูกลบก็ต่อเมื่อไม่มีเว็บอื่นของเจ้าของใช้เวอร์ชันนั้นแล้วเท่านั้น
 *
 * นี่คือ capability ฝั่ง Hosting — ไม่ได้ควบคุมโปรเซส PHP-FPM โดยตรง
 * การ start/stop ตัวบริการอยู่ที่หน้า Services ตาม Important UX Rule
 */
final class SiteSetPhp extends SiteCapability
{
    public static function name(): string
    {
        return 'site.set_php';
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
        return 'เปลี่ยนเวอร์ชัน PHP ของเว็บไซต์';
    }

    public function validate(array $args): array
    {
        return [
            'site_id' => Validator::requireInt($args, 'site_id', 1),
            'php_version' => self::assertPhpVersion(Validator::requireString($args, 'php_version', 8)),
        ];
    }

    public function run(array $args, Executor $executor, Context $context): array
    {
        $repository = $this->repository($context);
        $provisioner = $this->provisioner($context);

        $current = $this->loadSite($context, $args['site_id']);
        $target = $args['php_version'];

        if ($current->phpVersion === $target) {
            return [
                'site_id' => $current->id,
                'domain' => $current->domain,
                'php_version' => $target,
                'changed' => false,
                'message' => "เว็บไซต์ {$current->domain} ใช้ PHP {$target} อยู่แล้ว",
            ];
        }

        if (!$provisioner->fpm()->isVersionInstalled($executor, $target)) {
            throw new ValidationError("เครื่องนี้ยังไม่ได้ติดตั้ง PHP {$target}");
        }

        $previousVersion = $current->phpVersion;
        $updated = $current->withPhpVersion($target);

        $transaction = new ConfigTransaction($executor);

        // pool ใช้ร่วมกันทั้งบัญชีตั้งแต่ migration 0006 — ลบไฟล์ของเวอร์ชันเดิมได้ก็ต่อเมื่อ
        // เจ้าของไม่เหลือเว็บอื่นที่ใช้เวอร์ชันนั้นแล้ว ไม่งั้นการย้ายเว็บเดียวไป PHP ใหม่
        // จะทำให้เว็บพี่น้องที่ยังอยู่เวอร์ชันเดิมดับไปด้วยทั้งหมด
        $othersOnOldVersion = $repository->phpVersionsOwnedBy($current->owner->userId, exceptSiteId: $current->id);

        if (!in_array($previousVersion, $othersOnOldVersion, true)) {
            $transaction->delete($current->fpmPoolFile());
        }

        $provisioner->stageConfigs(
            $transaction,
            $updated,
            $executor,
            $repository->pointerDocrootsOwnedBy($updated->owner->userId, $target),
        );

        $transaction->commit(static fn (): array => $provisioner->validate($executor, $updated));

        // บันทึกก่อน reload ไม่ใช่หลัง — ไฟล์ค่าตั้งถูก commit ไปแล้วตรงนี้
        // ถ้า reload ล้มแล้วเราไปบันทึกทีหลัง ฐานข้อมูลจะค้างเป็นเวอร์ชันเก่า
        // ทั้งที่ pool ใหม่ถูกเขียนลงดิสก์แล้ว — หน้าจอกับความจริงจะไม่ตรงกัน
        $repository->setPhpVersion($updated->id, $target);

        $provisioner->reload($executor, $updated, alsoPhpVersion: $previousVersion);

        return [
            'site_id' => $updated->id,
            'domain' => $updated->domain,
            'php_version' => $target,
            'previous_version' => $previousVersion,
            'fpm_socket' => $updated->fpmSocket(),
            'changed' => true,
            'message' => "เปลี่ยน {$updated->domain} จาก PHP {$previousVersion} เป็น PHP {$target} แล้ว",
        ];
    }
}
