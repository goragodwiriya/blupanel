<?php

declare(strict_types=1);

namespace Phpcp\Agent\Capability;

use Phpcp\Agent\Capability;
use Phpcp\Agent\Context;
use Phpcp\Agent\Executor\Executor;
use Phpcp\Domain\SettingsRepository;
use Phpcp\Driver\ConfigTransaction;
use Phpcp\Driver\PanelCertificate;
use Phpcp\Driver\RollbackGuard;
use Phpcp\Support\Validator;

/**
 * เปลี่ยนใบรับรองของ **หน้าจัดการเอง** ให้เป็นใบจริง หรือกลับไปใช้ใบที่เซ็นเอง
 *
 * ## ทำไมต้องมี ทั้งที่แก้ไฟล์ด้วยมือก็ได้
 *
 * เดิมทำได้ทางเดียวคือ ssh เข้าไปสลับไฟล์เอง ผลคือแทบไม่มีใครทำ แล้วผู้ดูแลก็คลิกผ่าน
 * คำเตือนใบรับรองทุกวันไปเรื่อย ๆ · **นั่นคือการฝึกให้คนเพิกเฉยต่อคำเตือนที่วันหนึ่ง
 * จะเป็นของจริง** — ค่าเสียหายที่แท้จริงของข้อจำกัดนี้ไม่ใช่ความไม่สะดวก แต่เป็นการทำให้
 * สัญญาณเตือนที่สำคัญที่สุดของเบราว์เซอร์กลายเป็นสิ่งที่ทุกคนกดข้าม
 *
 * ## ทำไมต้องถอนคืนได้
 *
 * นี่คือคำสั่งที่ **ตัดทางเข้าของตัวเองได้** แบบเดียวกับกฎไฟร์วอลล์และค่าตั้ง SSH ·
 * ใบที่ผิดทำให้เบราว์เซอร์ปฏิเสธการเชื่อมต่อทั้งหมด แล้วผู้ดูแลจะไม่มีทางเข้ามาแก้ผ่าน
 * หน้าเว็บได้อีกเลย · จึงตั้ง `RollbackGuard` ไว้เหมือนกันทุกประการ — ไม่กดยืนยันภายใน
 * เวลาที่กำหนด ระบบคืนใบเดิมให้เอง
 *
 * มีทางกลับที่ไม่ต้องพึ่งหน้าเว็บด้วยเสมอ: `phpcp panel:cert --self-signed`
 *
 * ## ลำดับที่สำคัญ
 *
 * เก็บสภาพเดิม → ตรวจคู่กุญแจกับวันหมดอายุ → เขียนไฟล์ → **ให้ตัวตรวจของ Apache ตัดสิน** →
 * `reload` แบบ graceful (ไม่ใช่ restart เพราะคำขอที่กำลังตอบอยู่คือคำขอของคนที่เพิ่งกดปุ่ม) →
 * ตั้งเวลาถอนคืน
 */
final class PanelCertSet implements Capability
{
    /** คีย์ที่จำว่าตอนนี้หน้าจัดการผูกกับใบของโดเมนไหน — ว่าง = ใบที่เซ็นเอง */
    public const SETTING = 'panel.cert_domain';

    public static function name(): string
    {
        return 'panel.cert_set';
    }

    /** ค่าตั้งระดับเครื่อง — กระทบทางเข้าของผู้ดูแลทุกคน ไม่ใช่ของเว็บไซต์ใด */
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
        return 'เปลี่ยนใบรับรองของหน้าจัดการ';
    }

    public function validate(array $args): array
    {
        $domain = trim((string) ($args['domain'] ?? ''));

        return [
            // ว่าง = กลับไปใช้ใบที่เซ็นเอง ซึ่งเป็นทางกลับที่ต้องมีเสมอ
            'domain' => $domain === '' ? '' : Validator::domain($domain),
            // 0 = ไม่ตั้งเวลาถอนคืน (ใช้จากบรรทัดคำสั่ง) · ค่าลบถือเป็น 0 เช่นกัน
            'window' => isset($args['window']) ? max(0, (int) $args['window']) : RollbackGuard::DEFAULT_WINDOW,
        ];
    }

    public function run(array $args, Executor $executor, Context $context): array
    {
        $panel = new PanelCertificate();
        $domain = (string) $args['domain'];

        $previous = [
            PanelCertificate::CERT => $this->contents($executor, PanelCertificate::CERT),
            PanelCertificate::KEY => $this->contents($executor, PanelCertificate::KEY),
        ];

        [$certPath, $keyPath] = $domain === ''
            ? [PanelCertificate::SELF_SIGNED_CERT, PanelCertificate::SELF_SIGNED_KEY]
            : array_values($panel::sourcePaths($domain));

        /*
         * ใบที่เซ็นเองอาจยังไม่ถูกเก็บสำรองไว้ (เครื่องที่ติดตั้งก่อนมีคุณสมบัตินี้) —
         * เก็บของที่ใช้อยู่ตอนนี้ไว้ก่อนสลับ เพื่อให้ทางกลับมีจริงเสมอ ไม่ใช่มีแต่ในเอกสาร
         */
        if ($domain !== '' && !$executor->exists($executor->path(PanelCertificate::SELF_SIGNED_CERT))) {
            $this->keepSelfSigned($executor, $previous);
        }

        $files = $panel->read($executor, $certPath, $keyPath);

        $transaction = new ConfigTransaction($executor);
        $transaction->write(PanelCertificate::CERT, $files['cert'], 0644);
        // กุญแจส่วนตัวต้องอ่านได้เฉพาะ root — Apache อ่านตอนสตาร์ตในฐานะ root อยู่แล้ว
        $transaction->write(PanelCertificate::KEY, $files['key'], 0600);

        $transaction->commit(fn (): array => $panel->checkConfig($executor, $context->config));

        (new SettingsRepository($context->db))->save([self::SETTING => $domain]);

        $this->installHook($executor, $context, $domain !== '');

        // graceful — คำขอที่กำลังตอบอยู่คือคำขอของคนที่เพิ่งกดปุ่ม การ restart จะตัดมันทิ้ง
        $panel->reload($executor);

        /*
         * **`window = 0` แปลว่าไม่ตั้งเวลาถอนคืนเลย ไม่ใช่ตั้งเป็นศูนย์วินาที**
         *
         * `RollbackGuard::arm()` บีบค่าให้อยู่ในช่วง 30–900 วินาทีเสมอ — ส่ง 0 เข้าไปตรง ๆ
         * จะได้ 30 วินาที แล้วการสั่งจากบรรทัดคำสั่งจะคืนค่าเองภายในครึ่งนาทีโดยที่ผู้สั่ง
         * ไม่รู้ว่าต้องไปกดยืนยันที่ไหน · คนที่สั่งจาก CLI อยู่บนเครื่องแล้วและแก้กลับได้ทันที
         * กลไกนี้จึงมีไว้สำหรับคนที่ทำงานผ่านหน้าเว็บเท่านั้น
         */
        $rollbackId = 0;

        if ($args['window'] > 0) {
            $rollbackId = (new RollbackGuard($context->db))->arm(
                action: self::name(),
                description: $domain === ''
                    ? 'กลับไปใช้ใบรับรองที่เซ็นเองของหน้าจัดการ'
                    : sprintf('ใช้ใบรับรองของ %s กับหน้าจัดการ', $domain),
                files: $previous,
                reloadUnits: [PanelCertificate::UNIT],
                window: $args['window'],
                actorId: $context->actor->userId,
            );
        }

        $confirm = $rollbackId > 0
            ? ' แล้วกดยืนยันภายในเวลาที่กำหนด ไม่งั้นระบบคืนใบเดิมให้เอง'
            : '';

        return [
            'domain' => $domain,
            'rollback_id' => $rollbackId,
            'window' => $args['window'],
            'message' => $domain === ''
                ? 'กลับไปใช้ใบรับรองที่เซ็นเองแล้ว — เปิดหน้าจัดการซ้ำเพื่อยืนยันว่ายังเข้าได้'
                    . $confirm
                : sprintf(
                    'หน้าจัดการใช้ใบรับรองของ %s แล้ว — เปิดหน้าจัดการในแท็บใหม่เพื่อยืนยันว่า'
                        . 'ยังเข้าได้จริง%s',
                    $domain,
                    $confirm,
                ),
        ];
    }

    /** เก็บใบที่ใช้อยู่ตอนนี้ไว้เป็นทางกลับ — เรียกเฉพาะตอนที่ยังไม่มีสำรอง */
    private function keepSelfSigned(Executor $executor, array $current): void
    {
        foreach ([
            PanelCertificate::SELF_SIGNED_CERT => [$current[PanelCertificate::CERT], 0644],
            PanelCertificate::SELF_SIGNED_KEY => [$current[PanelCertificate::KEY], 0600],
        ] as $path => [$content, $mode]) {
            if ($content === null) {
                continue;
            }

            $executor->writeFile($executor->path($path), $content, $mode);
        }
    }

    /**
     * ติดตั้ง (หรือถอน) hook ที่ certbot เรียกหลังต่ออายุ
     *
     * **ขาด hook นี้คือใบจะหมดอายุใน 90 วันแล้วกลับไปเจอคำเตือนอีก** ทั้งที่ใบบนดิสก์
     * ของ certbot ถูกต้องทุกอย่าง — อาการที่ไม่มีใครโยงกลับมาที่การกดปุ่มเมื่อสามเดือนก่อน
     */
    private function installHook(Executor $executor, Context $context, bool $wanted): void
    {
        $path = $executor->path(PanelCertificate::HOOK);

        if (!$wanted) {
            if ($executor->exists($path)) {
                $executor->removePath($path);
            }

            return;
        }

        $executor->makeDirectory($executor->path(dirname(PanelCertificate::HOOK)), 0755);
        $executor->writeFile(
            $path,
            PanelCertificate::hookScript(
                PHP_BINARY,
                rtrim($context->config->paths->root, '/') . '/bin/phpcp',
            ),
            0755,
        );
    }

    /** null = ยังไม่มีไฟล์นี้ ซึ่ง RollbackGuard แปลว่า "ลบทิ้งตอนคืนค่า" */
    private function contents(Executor $executor, string $path): ?string
    {
        $resolved = $executor->path($path);

        if (!$executor->exists($resolved)) {
            return null;
        }

        try {
            return $executor->readFile($resolved);
        } catch (\Throwable) {
            return null;
        }
    }
}
