<?php

declare(strict_types=1);

namespace Phpcp\Agent\Capability;

use Phpcp\Agent\Context;
use Phpcp\Agent\ExecutionFailed;
use Phpcp\Agent\Executor\Executor;
use Phpcp\Agent\ValidationError;
use Phpcp\Domain\ConfigFileCatalog;
use Phpcp\Driver\ConfigTransaction;
use Phpcp\Driver\RollbackGuard;
use Phpcp\Driver\WebServer\CustomConfig;
use Phpcp\Support\Validator;

/**
 * เขียนไฟล์ตั้งค่าเพิ่มเติมของระบบเมล — Postfix หรือ Dovecot
 *
 * ## ทำไมต้องเขียนไฟล์แล้วสั่ง `sync()` ไม่ใช่เขียนไฟล์เฉย ๆ
 *
 * สองบริการนี้รับค่าของผู้ดูแลคนละวิธี และความต่างนั้นสำคัญ:
 *
 *   dovecot  `!include_try` ชี้มาที่ไฟล์ตรง ๆ — เขียนไฟล์เสร็จก็มีผลทันทีที่ reload
 *   postfix  **ไม่มีคำสั่ง include สำหรับ `main.cf` เลย** เนื้อไฟล์ต้องถูกผนวกลงท้าย
 *            `main.cf` ตอนที่ panel เขียนไฟล์นั้นใหม่ · เขียนไฟล์เฉย ๆ จึงไม่มีผลอะไร
 *            จนกว่าจะมีการเขียน `main.cf` ใหม่
 *
 * เรียก `sync()` ทั้งสองกรณีเพื่อให้เส้นทางเดียวกันทำงานเหมือนกัน — และเพราะ `sync()`
 * คือที่เดียวที่รู้ว่าต้องเขียนไฟล์อะไรบ้างให้ตรงกับสถานะจริงของเครื่อง
 *
 * ## ลำดับการคืนค่าเมื่อผิดพลาด
 *
 * ไฟล์ของผู้ดูแลถูกเขียนก่อน แล้วค่อย `sync()` ซึ่งมี `postfix check`/`doveconf -n`
 * อยู่ข้างใน · ถ้าตัวตรวจไม่ผ่าน `sync()` จะโยนออกมาและ **ต้องคืนไฟล์ของผู้ดูแลด้วย**
 * ไม่ใช่แค่คืนไฟล์ที่ generate — ไม่งั้นไฟล์เสียยังค้างอยู่และการ sync ครั้งถัดไป
 * (ซึ่งเกิดจากคนอื่นแก้กล่องจดหมาย) จะล้มตามไปด้วยโดยไม่มีใครรู้ว่าเพราะอะไร
 */
final class MailCustomConfig extends MailCapability
{
    public static function name(): string
    {
        return 'mail.custom_config';
    }

    /** ค่าตั้งของบริการเมลเป็นของทั้งเครื่อง ไม่ใช่ของโดเมนใดโดเมนหนึ่ง */
    public function permission(): string
    {
        return 'settings.manage';
    }

    public function summary(): string
    {
        return 'เขียนไฟล์ตั้งค่าเพิ่มเติมของระบบเมล';
    }

    public function validate(array $args): array
    {
        $service = Validator::requireEnum($args, 'service', ['postfix', 'dovecot']);

        return [
            'service' => $service,
            'content' => CustomConfig::assertContent((string) ($args['content'] ?? '')),
            'key' => isset($args['key']) && $args['key'] !== ''
                ? ConfigFileCatalog::assertKey((string) $args['key'])
                : '',
            'window' => isset($args['window']) ? (int) $args['window'] : RollbackGuard::DEFAULT_WINDOW,
        ];
    }

    public function run(array $args, Executor $executor, Context $context): array
    {
        $service = $args['service'];

        /*
         * ตัดสินจากทะเบียนอีกรอบว่าไฟล์นี้แก้ได้จริง — ปุ่มที่ไม่ขึ้นบนหน้าจอไม่ใช่ด่าน
         * ความปลอดภัย คำขอที่ประกอบเองยังส่งคีย์ของไฟล์ที่ระบบสร้างมาได้เสมอ
         */
        if ($args['key'] !== '') {
            $file = ConfigFileCatalog::find(ConfigFileCatalog::forMail(), $args['key']);

            if ($file === null || $file['kind'] !== ConfigFileCatalog::KIND_WRITABLE) {
                throw new ValidationError(
                    'ไฟล์นี้แก้จากหน้าเว็บไม่ได้ — ระบบเขียนทับทั้งไฟล์ทุกครั้งที่ข้อมูลเมลเปลี่ยน '
                    . 'สิ่งที่แก้ลงไปจะหายไปเงียบ ๆ · เขียนค่าที่ต้องการลงไฟล์ส่วนเสริมแทน',
                );
            }
        }

        $path = CustomConfig::servicePath($service);
        $previous = (new CustomConfig())->read($executor, $service);

        $executor->makeDirectory($executor->path(CustomConfig::serviceDirectory($service)), 0755);

        $transaction = new ConfigTransaction($executor);
        $transaction->write($path, $args['content'], 0644);

        // ไฟล์ของผู้ดูแลเองยังไม่มีอะไรให้ตรวจ ณ จุดนี้ — ตัวตรวจจริงอยู่ใน sync()
        $transaction->commitWithoutValidation();

        try {
            $this->sync($executor, $context);
        } catch (\Throwable $e) {
            // คืนไฟล์ของผู้ดูแลด้วย ไม่ใช่แค่ไฟล์ที่ generate — ดูเหตุผลที่หัวคลาส
            $transaction->rollback();
            $this->sync($executor, $context);

            throw new ExecutionFailed(
                "ค่าตั้งที่เขียนไม่ผ่านการตรวจของ " . $service . " จึงคืนค่าเดิมแล้ว\n\n"
                . $e->getMessage(),
            );
        }

        $rollbackId = (new RollbackGuard($context->db))->arm(
            action: self::name(),
            description: sprintf('แก้ค่าตั้งเพิ่มเติมของ %s', $service),
            files: [$path => $previous],
            reloadUnits: [$service],
            window: $args['window'],
            actorId: $context->actor->userId,
        );

        return [
            'service' => $service,
            'path' => $path,
            'rollback_id' => $rollbackId,
            'window' => $args['window'],
            'message' => sprintf(
                'บันทึกค่าตั้งเพิ่มเติมของ %s แล้ว — ทดสอบว่าเมลยังรับส่งได้จริง '
                . 'แล้วกดยืนยันภายในเวลาที่กำหนด ไม่งั้นระบบจะคืนค่าเดิมให้เอง',
                $service,
            ),
        ];
    }
}
