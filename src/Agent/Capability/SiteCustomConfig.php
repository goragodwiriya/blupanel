<?php

declare(strict_types=1);

namespace Phpcp\Agent\Capability;

use Phpcp\Agent\Context;
use Phpcp\Agent\ExecutionFailed;
use Phpcp\Agent\ValidationError;
use Phpcp\Agent\Executor\Executor;
use Phpcp\Driver\ConfigTransaction;
use Phpcp\Driver\RollbackGuard;
use Phpcp\Driver\Template;
use Phpcp\Domain\ConfigFileCatalog;
use Phpcp\Driver\WebServer\CustomConfig;
use Phpcp\Support\Validator;

/**
 * เขียนไฟล์ตั้งค่าเพิ่มเติมของเว็บไซต์ — สำหรับผู้ดูแลที่รู้ว่ากำลังทำอะไร
 *
 * ## สี่ชั้นที่กันไม่ให้พังทั้งเครื่อง
 *
 *   1. **เขียนผ่าน `ConfigTransaction`** — ไฟล์เดิมถูกเก็บไว้ก่อนเขียนเสมอ
 *   2. **ตัวตรวจของเว็บเซิร์ฟเวอร์เอง** (`apachectl configtest` / `nginx -t`) เป็นคน
 *      ตัดสินว่าจะ commit หรือคืนค่า — ไม่ใช่ตัวตรวจไวยากรณ์ที่เราเขียนเอง ซึ่งไม่มีวัน
 *      ตรงกับของจริงและจะกลายเป็นตัวบล็อกค่าตั้งที่ถูกต้อง
 *   3. **`RollbackGuard`** — ค่าตั้งที่ผ่าน configtest แต่ทำให้เว็บใช้ไม่ได้จริง (เช่น
 *      redirect วนไม่รู้จบ, deny ทุกอย่าง) ยังถูกถอนคืนเองถ้าไม่มีใครกดยืนยันในเวลา
 *   4. **เส้นทางไฟล์ไม่เคยมาจากผู้ใช้** — ประกอบจากโดเมนของเว็บไซต์ที่ผ่าน Validator
 *      แล้วเท่านั้น · ผู้เรียกส่งได้แค่เนื้อไฟล์
 *
 * ชั้นที่ 3 คือชั้นที่คนมักลืม: `configtest` ตอบแค่ว่า "ไวยากรณ์ถูก" ไม่ได้ตอบว่า
 * "เว็บยังใช้งานได้" · กฎที่เขียนถูกทุกตัวอักษรแต่บล็อกทุกคำขอก็ผ่าน configtest สบาย ๆ
 */
final class SiteCustomConfig extends SiteCapability
{
    public static function name(): string
    {
        return 'site.custom_config';
    }

    /**
     * **ผู้ดูแลเครื่องเท่านั้น ไม่ใช่เจ้าของเว็บ**
     *
     * ไฟล์นี้ถูกอ่านโดยเว็บเซิร์ฟเวอร์ที่รันเป็นผู้ใช้ระบบร่วมกันทั้งเครื่อง · ค่าที่
     * เขียนผิดทำให้ configtest ล้ม แล้วเว็บ**ทุกเว็บ**บนเครื่องหยุดโหลดค่าใหม่ตามไปด้วย
     * ไม่ใช่แค่เว็บของคนเขียน · `site.edit` ที่เจ้าของเว็บมีจึงไม่พอสำหรับงานนี้
     */
    public function permission(): string
    {
        return 'settings.manage';
    }

    public function isMutating(): bool
    {
        return true;
    }

    public function summary(): string
    {
        return 'เขียนไฟล์ตั้งค่าเพิ่มเติมของเว็บไซต์';
    }

    public function validate(array $args): array
    {
        return [
            'site_id' => Validator::requireInt($args, 'site_id', 1),
            'content' => CustomConfig::assertContent((string) ($args['content'] ?? '')),
            // คีย์จากหน้าจอ — ว่างได้ (ผู้เรียกที่เป็นเครื่อง) แต่ถ้าส่งมาต้องเป็นไฟล์ที่แก้ได้จริง
            'key' => isset($args['key']) && $args['key'] !== ''
                ? ConfigFileCatalog::assertKey((string) $args['key'])
                : '',
            'window' => isset($args['window'])
                ? (int) $args['window']
                : RollbackGuard::DEFAULT_WINDOW,
        ];
    }

    public function run(array $args, Executor $executor, Context $context): array
    {
        $site = $this->loadSite($context, $args['site_id']);
        $templates = new Template($context->config->paths->templates());
        $driver = self::webServer($context, $templates);

        // ชนิดของไฟล์ต้องตรงกับเซิร์ฟเวอร์ที่ใช้อยู่จริง ไม่ใช่ที่ผู้เรียกบอกมา
        $server = $driver->name() === 'nginx' ? 'nginx' : 'apache';

        /*
         * **ตัดสินจากทะเบียนอีกรอบว่าไฟล์นี้แก้ได้จริง** ไม่ใช่เชื่อว่าหน้าจอส่งคีย์ที่ถูก
         *
         * ปุ่มที่ไม่ขึ้นบนหน้าจอไม่ใช่ด่านความปลอดภัย — คำขอที่ประกอบเองยังส่งคีย์ของ
         * ไฟล์ที่ระบบสร้าง (`site.N.vhost.0`) มาได้เสมอ · ด่านต้องอยู่ที่ชั้นล่างสุด
         */
        if ($args['key'] !== '') {
            $file = ConfigFileCatalog::find(
                ConfigFileCatalog::forSite($site->id, $site->domain, $driver->name(), $driver->vhostPaths($site)),
                $args['key'],
            );

            if ($file === null || $file['kind'] !== ConfigFileCatalog::KIND_WRITABLE) {
                throw new ValidationError(
                    'ไฟล์นี้แก้จากหน้าเว็บไม่ได้ — ระบบเขียนทับทั้งไฟล์ทุกครั้งที่เว็บไซต์เปลี่ยน '
                    . 'สิ่งที่แก้ลงไปจะหายไปเงียบ ๆ · เขียนค่าที่ต้องการลงไฟล์ส่วนเสริมแทน',
                );
            }
        }

        $path = CustomConfig::path($server, $site->domain);
        $custom = new CustomConfig();
        $previous = $custom->read($executor, $server, $site->domain);

        $executor->makeDirectory($executor->path(CustomConfig::directory($server, $site->domain)), 0755);

        $transaction = new ConfigTransaction($executor);

        /*
         * เนื้อว่าง = ผู้ดูแลลบค่าที่เคยใส่ไว้ · ยังเขียนไฟล์ (เหลือแต่หัวไฟล์) แทนที่จะ
         * ลบทิ้ง เพราะไฟล์ที่มีอยู่แต่ว่างอ่านง่ายกว่าไฟล์ที่หายไป — และการลบไฟล์ทำให้
         * ผู้ดูแลไม่รู้ว่าเคยมีที่ให้เขียนอยู่ตรงนี้
         */
        /*
         * เขียนตามที่ส่งมาตรง ๆ **ไม่เติมหัวไฟล์เอง** — คำอธิบายมากับไฟล์ตั้งต้นอยู่แล้ว
         * ถ้าเติมซ้ำที่นี่ ทุกครั้งที่บันทึกจะได้หัวไฟล์เพิ่มอีกชุดทับกันไปเรื่อย ๆ ·
         * ผู้ดูแลลบคอมเมนต์ทิ้งได้ตามใจ ไฟล์นี้เป็นของเขา
         */
        $transaction->write($path, $args['content'], 0644);

        $transaction->commit(fn (): array => $driver->testConfig($executor));

        try {
            $driver->reload($executor);
        } catch (\Throwable $e) {
            // ผ่าน configtest แล้วแต่ reload ไม่ขึ้น — คืนค่าเดิมทันที ไม่ต้องรอหมดเวลา
            $transaction->rollback();
            $driver->reload($executor);

            throw new ExecutionFailed(
                "ค่าตั้งผ่านการตรวจแล้วแต่สั่งให้เว็บเซิร์ฟเวอร์โหลดใหม่ไม่สำเร็จ จึงคืนค่าเดิมแล้ว\n\n"
                . $e->getMessage(),
            );
        }

        /*
         * ตั้งเวลาถอนคืนไว้ — `configtest` ผ่านไม่ได้แปลว่าเว็บยังใช้งานได้
         *
         * กฎที่เขียนถูกทุกตัวอักษรแต่ทำให้ทุกคำขอถูกปฏิเสธ หรือ redirect วนไม่รู้จบ
         * ผ่าน configtest สบาย ๆ · ผู้ดูแลต้องเปิดเว็บดูจริงแล้วกดยืนยัน ไม่งั้นถอนคืนเอง
         */
        $rollbackId = (new RollbackGuard($context->db))->arm(
            action: self::name(),
            description: sprintf('แก้ค่าตั้งเพิ่มเติมของ %s', $site->domain),
            files: [$path => $previous],
            reloadUnits: [$driver->unit()],
            window: $args['window'],
            actorId: $context->actor->userId,
        );

        return [
            'site_id' => $site->id,
            'domain' => $site->domain,
            'server' => $server,
            'path' => $path,
            'rollback_id' => $rollbackId,
            'window' => $args['window'],
            'message' => sprintf(
                'บันทึกค่าตั้งเพิ่มเติมของ %s แล้ว — เปิดเว็บดูว่าใช้งานได้จริง '
                . 'แล้วกดยืนยันภายในเวลาที่กำหนด ไม่งั้นระบบจะคืนค่าเดิมให้เอง',
                $site->domain,
            ),
        ];
    }
}
