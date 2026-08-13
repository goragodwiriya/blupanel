<?php

declare(strict_types=1);

namespace Phpcp\Agent\Capability;

use Phpcp\Agent\Context;
use Phpcp\Agent\Executor\Executor;
use Phpcp\Agent\ValidationError;
use Phpcp\Domain\SettingsRepository;
use Phpcp\Driver\Mail\MailManager;

/**
 * บันทึกค่าเมลขาออกแล้วเขียนลง Postfix ในคำสั่งเดียว — PLAN-MAIL เฟส M3
 *
 * แยกจาก `settings.set` เพราะการบันทึกค่าลงฐานข้อมูลกับการเขียนไฟล์ค่าตั้งของบริการ
 * ระบบเป็นคนละความเสี่ยงกัน อย่างหลังต้องผ่าน `ConfigTransaction` และ `postfix check`
 * ก่อนมีผลจริง · รวมสองอย่างไว้ในคำสั่งเดียวแบบเดียวกับ `webserver.apply` เพราะ
 * "บันทึกแล้วแต่ยังไม่มีผล" คือสภาพที่ผู้ดูแลมองไม่ออกว่าตกลงเครื่องส่งเมลทางไหนอยู่
 *
 * **เขียน `main.cf` ผ่าน `sync()` เท่านั้น ไม่เรียก MailManager เอง** — ที่นี่รู้แค่
 * เรื่องเมลขาออก ไม่รู้ว่าเครื่องนี้เปิดเมลโฮสติ้งให้โดเมนไหนอยู่บ้าง · ตอนที่เคย
 * เรียกเองตรง ๆ การกดบันทึกที่อยู่ผู้ส่งจะเขียน `main.cf` ทับด้วยค่าที่ไม่มีส่วนรับ
 * เมลเลย แล้ว Postfix กลับไปฟังแค่ loopback — **กล่องทุกกล่องบนเครื่องหยุดรับเมล**
 * โดยไม่มีข้อความผิดพลาดใด ๆ เพราะทุกอย่างที่เขียนไปนั้น "สำเร็จ"
 */
final class MailApply extends MailCapability
{
    public static function name(): string
    {
        return 'mail.apply';
    }

    /**
     * ค่าเมลขาออกเป็นของทั้งเครื่อง ไม่ใช่ของโดเมนใดโดเมนหนึ่ง — เจ้าของเว็บที่มี
     * `mail.manage` จัดการกล่องของตัวเองได้ แต่เปลี่ยนทางออกของเมลทั้งเครื่องไม่ได้
     */
    public function permission(): string
    {
        return 'settings.manage';
    }

    public function summary(): string
    {
        return 'บันทึกค่าเมลขาออกแล้วเขียนลง Postfix';
    }

    public function validate(array $args): array
    {
        $out = ['values' => []];

        // ส่งมาเฉพาะช่องที่ต้องการเปลี่ยน — ช่องที่ไม่ได้ส่งใช้ค่าเดิมในฐานข้อมูล
        foreach (['mail.from', 'mail.relay_host', 'mail.relay_user', 'mail.relay_password', 'mail.hostname'] as $key) {
            if (array_key_exists($key, $args)) {
                $out['values'][$key] = trim((string) $args[$key]);
            }
        }

        if (array_key_exists('mail.mode', $args)) {
            $mode = trim((string) $args['mail.mode']);

            if (!in_array($mode, ['local', 'relay'], true)) {
                throw new ValidationError('โหมดเมลขาออกต้องเป็น local หรือ relay');
            }

            $out['values']['mail.mode'] = $mode;
        }

        foreach (['mail.enabled', 'mail.relay_tls'] as $key) {
            if (array_key_exists($key, $args)) {
                $out['values'][$key] = in_array($args[$key], [true, 1, '1', 'on', 'true'], true) ? '1' : '0';
            }
        }

        if (array_key_exists('mail.relay_port', $args)) {
            $out['values']['mail.relay_port'] = (string) MailManager::assertPort((int) $args['mail.relay_port']);
        }

        if (($out['values']['mail.from'] ?? '') !== '') {
            MailManager::assertEmail($out['values']['mail.from']);
        }

        if (($out['values']['mail.relay_host'] ?? '') !== '') {
            MailManager::assertHost($out['values']['mail.relay_host']);
        }

        return $out;
    }

    public function run(array $args, Executor $executor, Context $context): array
    {
        $settings = new SettingsRepository($context->db);
        $values = $args['values'];

        /*
         * โหมด relay ที่ไม่มีโฮสต์ปลายทางคือเมลที่ไม่ออกไปไหนเลย · Postfix จะรับ
         * เมลไว้ในคิวแล้วพยายามส่งเองแบบ local ต่อไปเงียบ ๆ ซึ่งตรงข้ามกับที่เลือกไว้
         */
        $mode = $values['mail.mode'] ?? $settings->get('mail.mode');
        $host = $values['mail.relay_host'] ?? $settings->get('mail.relay_host');

        if ($mode === 'relay' && $host === '') {
            throw new ValidationError(
                'เลือกส่งผ่าน relay แล้วต้องระบุโฮสต์ของผู้ให้บริการด้วย '
                . '(เช่น smtp.sendgrid.net) ไม่งั้นเมลจะค้างอยู่ในคิวโดยไม่มีอะไรฟ้อง',
            );
        }

        // ดอกจัน = ผู้ใช้ไม่ได้แตะช่องรหัสผ่าน เก็บของเดิมไว้ ไม่ใช่เขียนทับด้วยดอกจัน
        if (($values['mail.relay_password'] ?? '') === '********') {
            unset($values['mail.relay_password']);
        }

        if ($values !== []) {
            $settings->save($values);
        }

        // เขียน main.cf/master.cf ใหม่ทั้งชุดจากภาพรวมทั้งเครื่อง รวมส่วนรับเมลเข้า
        // ของโดเมนที่เปิดเมลอยู่ — ดูเหตุผลที่หัวคลาส
        $counts = $this->sync($executor, $context);

        $relay = $settings->get('mail.relay_host');
        $applied = $settings->get('mail.mode');

        return [
            'mode' => $applied,
            'relay' => $relay,
            'domains' => $counts['domains'],
            'message' => $applied === 'relay'
                ? sprintf('ตั้งค่าให้ส่งเมลผ่าน %s เรียบร้อยแล้ว', $relay)
                : 'ตั้งค่าให้ส่งเมลตรงจากเครื่องนี้เรียบร้อยแล้ว',
        ];
    }
}
