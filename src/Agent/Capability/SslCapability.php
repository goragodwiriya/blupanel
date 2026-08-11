<?php

declare(strict_types=1);

namespace Phpcp\Agent\Capability;

use Phpcp\Agent\Context;
use Phpcp\Agent\Executor\Executor;
use Phpcp\Agent\ValidationError;
use Phpcp\Domain\Site;
use Phpcp\Driver\ConfigTransaction;
use Phpcp\Driver\Ssl\CertbotManager;

/**
 * ฐานร่วมของ capability ที่จัดการใบรับรอง SSL
 *
 * สืบทอดจาก SiteCapability เพราะทุกคำสั่งของ SSL ผูกกับเว็บไซต์หนึ่งเว็บเสมอ
 * และต้องเขียน vhost ใหม่หลังเปลี่ยนใบรับรอง — ใช้เส้นทางเดียวกับการแก้เว็บไซต์อื่น ๆ
 * ทั้ง ConfigTransaction, configtest และ reload จึงไม่มีทางลัดที่ข้ามการตรวจ
 */
abstract class SslCapability extends SiteCapability
{
    protected function certbot(): CertbotManager
    {
        return new CertbotManager();
    }

    /**
     * เขียน vhost ใหม่แล้ว reload โดยให้ผู้เรียกแทรกการบันทึกฐานข้อมูลไว้ตรงกลางได้
     *
     * ลำดับสำคัญมาก และเคยผิดมาแล้ว: เดิมบันทึกฐานข้อมูล "หลัง" reload
     * พอ reload ล้ม (เช่น systemd ใช้งานไม่ได้) จะโยน error ออกไปก่อนถึงบรรทัดบันทึก
     * ผลคือไฟล์บนดิสก์เป็นค่าใหม่แล้วแต่ฐานข้อมูลยังเป็นค่าเก่า — หน้าจอโกหก
     *
     * ตอนนี้จึงเป็น: commit ไฟล์ → บันทึกฐานข้อมูล → reload
     * ถ้า reload ล้ม ฐานข้อมูลกับไฟล์ยังตรงกัน เหลือแค่ต้องไป reload ให้สำเร็จ
     * ส่วนถ้า configtest ไม่ผ่าน ConfigTransaction คืนไฟล์เดิมและยังไม่มีอะไรถูกบันทึก
     *
     * @param (callable(): void)|null $afterCommit
     */
    protected function rewriteVhost(
        Context $context,
        Executor $executor,
        Site $site,
        ?callable $afterCommit = null,
    ): void {
        $provisioner = $this->provisioner($context);
        $transaction = new ConfigTransaction($executor);

        $provisioner->stageConfigs(
            $transaction,
            $site,
            $executor,
            $this->repository($context)->pointerDocrootsOwnedBy($site->owner->userId, $site->phpVersion),
        );
        $transaction->commit(static fn (): array => $provisioner->validate($executor, $site));

        if ($afterCommit !== null) {
            $afterCommit();
        }

        $provisioner->reload($executor, $site);
    }

    /**
     * โดเมนทั้งหมดของเว็บไซต์ที่ควรอยู่ในใบรับรองใบเดียวกัน
     *
     * รวม alias ด้วยเสมอ เพราะใบที่ครอบคลุมไม่ครบทำให้เบราว์เซอร์ขึ้นคำเตือน
     * เฉพาะบาง alias ซึ่งเป็นอาการที่หาสาเหตุยากกว่าการที่ทั้งเว็บไม่มี SSL เลย
     *
     * @return list<string>
     */
    protected function domainsFor(Site $site): array
    {
        return array_values(array_unique([$site->domain, ...$site->aliases]));
    }

    protected function assertCertificateExists(Executor $executor, Site $site): array
    {
        $certificate = $this->certbot()->inspect($executor, $site);

        if ($certificate['status'] === 'none') {
            throw new ValidationError(
                'เว็บไซต์นี้ยังไม่มีใบรับรอง — ติดตั้ง SSL ก่อนจึงจะเปิดใช้งาน HTTPS ได้',
            );
        }

        return $certificate;
    }
}
