<?php

declare(strict_types=1);

namespace Phpcp\Agent;

use Phpcp\Agent\Executor\Executor;
use Phpcp\Agent\Executor\ExecutorFactory;
use Phpcp\Domain\Notifier;
use Phpcp\Kernel\Config;
use Phpcp\Kernel\Db;
use Phpcp\Security\AuditLog;

/**
 * เส้นทางเดียวที่คำสั่งเดินผ่าน — ARCHITECTURE §4.1
 *
 * ลำดับตายตัว ห้ามสลับ:
 *   resolve → ตรวจ permission → validate → บันทึก audit "ก่อนทำ" → run → บันทึกผล
 *
 * ที่ต้องบันทึก audit ก่อนลงมือ เพราะคำสั่งที่ทำให้เครื่องดับกลางคัน
 * (เช่น restart ตัวเอง, แก้ firewall จนหลุด) ต้องยังมีร่องรอยว่าใครสั่ง
 */
final class Dispatcher
{
    private readonly ExecutorFactory $executors;

    public function __construct(
        private readonly CapabilityRegistry $registry,
        private readonly Config $config,
        private readonly Db $db,
        private readonly AuditLog $audit,
    ) {
        $this->executors = new ExecutorFactory($config);
    }

    /**
     * @param array<string,mixed> $args
     * @return array<string,mixed>
     */
    public function dispatch(string $name, array $args, Actor $actor): array
    {
        $startedAt = hrtime(true);
        $capability = $this->registry->resolve($name);

        if (!$actor->can($capability->permission())) {
            $this->audit->write($actor, $name, self::targetOf($args), 'denied', [
                'reason' => 'ไม่มีสิทธิ์ ' . $capability->permission(),
            ]);

            throw new PermissionDenied('คุณไม่มีสิทธิ์ใช้คำสั่งนี้');
        }

        // validate โยน ValidationError/ProtectedResource ออกไปให้ handle() ข้างนอกบันทึก
        $clean = $capability->validate($args);

        $auditId = null;
        if ($capability->isMutating()) {
            $auditId = $this->audit->write($actor, $name, self::targetOf($clean), 'ok', [
                'phase' => 'เริ่มดำเนินการ',
                'args' => self::redact($clean),
                'mode' => $this->config->mode->value,
            ]);
        }

        $executor = $this->executors->for($capability);
        $context = new Context($actor, $this->config, $this->db);

        try {
            $data = $capability->run($clean, $executor, $context);
        } catch (\Throwable $e) {
            if ($capability->isMutating()) {
                $this->audit->write($actor, $name, self::targetOf($clean), 'error', [
                    'phase' => 'ล้มเหลว',
                    'audit_ref' => $auditId,
                    'error' => $e->getMessage(),
                ]);

                $this->notify($name, $actor, self::targetOf($clean), false, $e->getMessage(), $executor);
            }
            throw $e;
        }

        if ($capability->isMutating()) {
            $this->audit->write($actor, $name, self::targetOf($clean), 'ok', [
                'phase' => 'สำเร็จ',
                'audit_ref' => $auditId,
                'simulated' => $executor->isSimulated(),
            ]);

            $this->notify($name, $actor, self::targetOf($clean), true, (string) ($data['message'] ?? ''), $executor);
        }

        $meta = [
            'mode' => $this->config->mode->value,
            'simulated' => $executor->isSimulated(),
            'duration_ms' => (int) ((hrtime(true) - $startedAt) / 1_000_000),
        ];

        // โหมด dryrun ต้องบอกผู้ใช้ว่า "จะรันอะไร" ไม่ใช่แค่บอกว่าไม่ได้ทำ
        $commands = $executor->simulatedCommands();
        if ($commands !== []) {
            $meta['commands'] = $commands;
        }

        return ['data' => $data, 'meta' => $meta];
    }

    /**
     * เดา target จาก args สำหรับบันทึกใน audit — เอาไว้ค้นย้อนหลังว่าใครทำอะไรกับอะไร
     *
     * @param array<string,mixed> $args
     */
    /**
     * คำสั่งที่ควรแจ้งเตือน พร้อมหมวดที่ผู้ใช้เปิด/ปิดได้
     *
     * เป็นรายการที่คัดมาแล้ว ไม่ใช่ "ทุกคำสั่งที่เปลี่ยนแปลงระบบ" — การสร้างเว็บไซต์
     * หรือแก้ไฟล์เกิดขึ้นวันละหลายสิบครั้ง ถ้าแจ้งทั้งหมดผู้ดูแลจะปิดการแจ้งเตือน
     * ภายในสัปดาห์เดียว แล้ววันที่ firewall ถูกปิดจริงก็จะไม่มีใครเห็น
     *
     * **`service.start` อยู่ในรายการเพราะการแจ้งที่ไม่ปิดวงจรสร้างความกังวลค้างไว้** —
     * ผู้ดูแลที่ได้ข้อความ "หยุดบริการสำเร็จ" ตอนตีสอง แล้วไม่ได้ข้อความตอนเปิดกลับ
     * ต้องเปิดเครื่องมาตรวจเองว่าใครเปิดคืนหรือยัง · การเริ่มบริการเกิดไม่บ่อยพอ
     * ที่จะกลายเป็นสแปม ต่างจากการสร้างเว็บหรือแก้ไฟล์
     *
     * @var array<string,string>
     */
    private const NOTIFY = [
        'firewall.disable' => 'security',
        'firewall.enable' => 'security',
        'ssh.config_set' => 'security',
        'rollback.run' => 'security',
        'service.stop' => 'service',
        'service.start' => 'service',
        'service.restart' => 'service',
        'ssl.issue' => 'ssl',
        'ssl.renew' => 'ssl',
        'ssl.delete' => 'ssl',
        'backup.create' => 'backup',
        'backup.restore' => 'backup',
        'site.delete' => 'security',
    ];

    /**
     * ส่งการแจ้งเตือนถ้าคำสั่งนี้อยู่ในรายการที่คัดไว้
     *
     * ห้ามโยน exception ออกไปเด็ดขาด — งานหลักสำเร็จ (หรือล้มเหลว) ไปแล้วตอนที่มาถึงตรงนี้
     * ถ้าการส่งข้อความล้มแล้วทำให้ทั้งคำสั่งล้มตาม ผู้ใช้จะเห็นว่าการสร้างสำรองข้อมูล
     * "ล้มเหลว" ทั้งที่ไฟล์สำรองถูกสร้างเรียบร้อยแล้ว
     */
    private function notify(
        string $name,
        Actor $actor,
        string $target,
        bool $ok,
        string $detail,
        ?Executor $executor = null,
    ): void {
        $event = self::NOTIFY[$name] ?? null;

        if ($event === null) {
            return;
        }

        try {
            $capability = $this->registry->resolve($name);

            // ส่ง executor ต่อไปด้วยเสมอ — ช่องทาง**อีเมล**ต้องเรียก `sendmail` ซึ่งเดินผ่าน
            // Executor เท่านั้น (ARCHITECTURE §4.4) · ถ้าไม่ส่ง `Notifier` จะข้ามอีเมลเงียบ ๆ
            // แล้วผู้ดูแลที่ตั้งอีเมลไว้ครบถูกต้องจะไม่ได้รับอะไรเลยสักฉบับ โดยไม่มีอะไรฟ้อง
            // — เคยเป็นแบบนั้นมาแล้วจนกว่าจะไปทดสอบบนเครื่องจริง
            (new Notifier($this->db, $executor))->send(
                $event,
                ($ok ? 'สำเร็จ: ' : 'ล้มเหลว: ') . $capability->summary(),
                sprintf(
                    "เป้าหมาย: %s\nผู้สั่ง: %s (%s)\nเครื่อง: %s\n\n%s",
                    $target !== '' ? $target : '—',
                    $actor->username,
                    $actor->ip,
                    gethostname() ?: 'ไม่ทราบ',
                    mb_substr(trim($detail), 0, 500),
                ),
                $ok ? 'info' : 'danger',
            );
        } catch (\Throwable) {
            // การแจ้งเตือนที่ส่งไม่ได้ต้องเงียบ ไม่ใช่ทำให้คำสั่งที่สำเร็จแล้วดูเหมือนล้มเหลว
        }
    }

    /**
     * ชื่อ argument ที่ห้ามลงบันทึกดิบ ๆ — เทียบแบบ "มีคำนี้อยู่ในชื่อ" ไม่ใช่ตรงตัว
     *
     * ครอบทั้ง `password`, `notify.telegram.token`, `smtp_secret` และชื่อที่ยังไม่มีในวันนี้
     * ตั้งใจให้กว้างไว้ก่อน: บันทึกคำว่า *** เกินความจำเป็นไม่ทำให้ใครเดือดร้อน
     * แต่รหัสผ่านที่หลุดลง audit log ลบออกไม่ได้เลยเพราะ chain จะขาด
     */
    private const SECRET_HINTS = ['password', 'passwd', 'secret', 'token', 'passphrase', 'private_key', 'credential'];

    /** ค่าที่ยาวกว่านี้เก็บแค่ขนาด — เนื้อหาไฟล์ทั้งไฟล์ไม่ควรอยู่ใน audit log */
    private const MAX_AUDIT_VALUE = 200;

    /**
     * ล้างค่าที่ไม่ควรอยู่ใน audit log ออกก่อนบันทึก
     *
     * ทำไมสำคัญ: `audit_log` เป็น hash-chain ที่ตั้งใจให้ลบไม่ได้ และถูก mirror ลงไฟล์
     * ที่ผู้ดูแลอ่านได้ทุกคน ถ้าปล่อยให้ args ดิบลงไป การสร้างลูกค้าหนึ่งครั้งจะฝัง
     * รหัสผ่านของลูกค้าไว้ถาวร และ `file.write` หนึ่งครั้งจะสำเนาเนื้อหาไฟล์ทั้งไฟล์
     * (รวมถึงไฟล์ .env ที่มีรหัสฐานข้อมูล) ลงไปด้วย
     *
     * @param array<string,mixed> $args
     * @return array<string,mixed>
     */
    private static function redact(array $args): array
    {
        $clean = [];

        foreach ($args as $key => $value) {
            $name = strtolower((string) $key);

            foreach (self::SECRET_HINTS as $hint) {
                if (str_contains($name, $hint)) {
                    $clean[$key] = '***';

                    continue 2;
                }
            }

            if (is_array($value)) {
                $clean[$key] = self::redact($value);

                continue;
            }

            if (is_string($value) && strlen($value) > self::MAX_AUDIT_VALUE) {
                $clean[$key] = sprintf('(ตัดทอน %s ไบต์)', number_format(strlen($value)));

                continue;
            }

            $clean[$key] = $value;
        }

        return $clean;
    }

    private static function targetOf(array $args): string
    {
        foreach (['service', 'domain', 'site_id', 'db_name', 'username', 'path', 'unit'] as $key) {
            if (isset($args[$key]) && is_scalar($args[$key])) {
                return substr((string) $args[$key], 0, 190);
            }
        }

        return '';
    }
}
