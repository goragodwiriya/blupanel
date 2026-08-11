<?php

declare(strict_types=1);

namespace Phpcp\Agent\Capability;

use Phpcp\Agent\Context;
use Phpcp\Agent\Executor\Executor;
use Phpcp\Driver\ConfigTransaction;
use Phpcp\Support\Validator;

/**
 * ระงับหรือเปิดใช้งานเว็บไซต์
 *
 * การระงับไม่ลบอะไรเลย — เขียน vhost ใหม่ให้ตอบ 503 ทุกเส้นทาง ไฟล์และฐานข้อมูลยังอยู่ครบ
 * เลือกตอบ 503 ไม่ใช่ 403 เพราะสื่อว่าเป็นการหยุดชั่วคราว เครื่องมือค้นหาจะไม่ถอดหน้าออกจากดัชนี
 *
 * pool ของ FPM ถูกลบด้วยเพื่อคืนหน่วยความจำ — เว็บที่ถูกระงับไม่ควรกินทรัพยากรต่อ
 */
abstract class SiteSetStatus extends SiteCapability
{
    abstract protected function targetStatus(): string;

    public function permission(): string
    {
        return 'site.suspend';
    }

    public function isMutating(): bool
    {
        return true;
    }

    public function validate(array $args): array
    {
        return ['site_id' => Validator::requireInt($args, 'site_id', 1)];
    }

    public function run(array $args, Executor $executor, Context $context): array
    {
        $repository = $this->repository($context);
        $provisioner = $this->provisioner($context);

        $site = $this->loadSite($context, $args['site_id']);
        $target = $this->targetStatus();

        if ($site->status === $target) {
            return [
                'site_id' => $site->id,
                'domain' => $site->domain,
                'status' => $target,
                'changed' => false,
                'message' => $target === 'active'
                    ? "เว็บไซต์ {$site->domain} ใช้งานได้อยู่แล้ว"
                    : "เว็บไซต์ {$site->domain} ถูกระงับอยู่แล้ว",
            ];
        }

        $updated = $site->withStatus($target);
        $transaction = new ConfigTransaction($executor);

        if ($target === 'active') {
            $provisioner->stageConfigs(
                $transaction,
                $updated,
                $executor,
                $repository->pointerDocrootsOwnedBy($updated->owner->userId, $updated->phpVersion),
            );
        } else {
            // ระงับ: vhost ตอบ 503 และไม่มี pool ให้เว็บทำงานได้อีก
            $transaction->delete($updated->fpmPoolFile());
            $transaction->write(
                $provisioner->webserver()->vhostPath($updated),
                $provisioner->webserver()->renderVhost($updated, $executor),
                0644,
            );
        }

        $transaction->commit(static fn (): array => $provisioner->validate($executor, $updated));
        $provisioner->reload($executor, $updated);

        $repository->setStatus($site->id, $target);

        return [
            'site_id' => $site->id,
            'domain' => $site->domain,
            'status' => $target,
            'changed' => true,
            'message' => $target === 'active'
                ? "เปิดใช้งานเว็บไซต์ {$site->domain} แล้ว"
                : "ระงับเว็บไซต์ {$site->domain} แล้ว — ไฟล์และฐานข้อมูลยังอยู่ครบ",
        ];
    }
}
