<?php

declare(strict_types=1);

namespace Phpcp\Agent\Capability;

use Phpcp\Agent\Capability;
use Phpcp\Agent\Context;
use Phpcp\Agent\Executor\Executor;
use Phpcp\Domain\Notifier;
use Phpcp\Domain\SettingsRepository;
use Phpcp\Driver\Mail\MailManager;
use Phpcp\Driver\Notify\TelegramNotifier;
use Phpcp\Driver\Notify\WebhookNotifier;
use Phpcp\Support\Validator;

/**
 * บันทึกค่าตั้ง
 *
 * ค่าที่เป็นความลับ (token, รหัสผ่าน) ถ้าส่งมาเป็นค่าที่ปิดบังไว้ (********)
 * แปลว่าผู้ใช้ไม่ได้แก้ช่องนั้น ต้องเก็บค่าเดิมไว้ ไม่ใช่เขียนทับด้วยดอกจัน —
 * ถ้าเขียนทับ การกดบันทึกเพื่อแก้ค่าอื่นเพียงค่าเดียวจะทำลาย token ทิ้งทุกครั้ง
 */
final class SettingsSet implements Capability
{
    public static function name(): string
    {
        return 'settings.set';
    }

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
        return 'บันทึกค่าตั้งของระบบ';
    }

    public function validate(array $args): array
    {
        $out = [];

        foreach (SettingsRepository::keys() as $key => $type) {
            if (!array_key_exists($key, $args)) {
                continue;
            }

            $value = (string) $args[$key];

            $out[$key] = match ($type) {
                'bool' => $value === '1' || $value === 'on' || $value === 'true' ? '1' : '0',
                'int' => (string) (int) $value,
                default => Validator::pattern(
                    trim($value),
                    // กันอักขระควบคุมและขึ้นบรรทัดใหม่ — ค่าเหล่านี้ถูกนำไปเขียนลงไฟล์ config
                    '/^[^\x00-\x1F\x7F]{0,255}$/u',
                    "ค่าของ {$key} มีอักขระที่ใช้ไม่ได้",
                ),
            };
        }

        // ตรวจรูปแบบเฉพาะทางหลังจากล้างค่าพื้นฐานแล้ว
        if (isset($out['notify.telegram.token']) && $out['notify.telegram.token'] !== '********') {
            TelegramNotifier::assertToken($out['notify.telegram.token']);
        }

        if (isset($out['notify.telegram.chat_id'])) {
            TelegramNotifier::assertChatId($out['notify.telegram.chat_id']);
        }

        if (($out['mail.from'] ?? '') !== '') {
            MailManager::assertEmail($out['mail.from']);
        }

        if (($out['notify.email.to'] ?? '') !== '') {
            MailManager::assertEmail($out['notify.email.to']);
        }

        // บังคับ https และรูปแบบ URL ตั้งแต่ตอนบันทึก — จับความผิดพลาดตอนกรอก
        // ไม่ใช่ตอนที่การแจ้งเตือนสำคัญส่งไม่ออกในจังหวะที่ต้องการที่สุด
        if (isset($out['notify.webhook.url'])) {
            WebhookNotifier::assertUrl($out['notify.webhook.url']);
        }

        if (($out['mail.relay_host'] ?? '') !== '') {
            MailManager::assertHost($out['mail.relay_host']);
        }

        if (isset($out['mail.relay_port'])) {
            MailManager::assertPort((int) $out['mail.relay_port']);
        }

        return ['values' => $out];
    }

    public function run(array $args, Executor $executor, Context $context): array
    {
        $settings = new SettingsRepository($context->db);
        $values = $args['values'];

        foreach ($values as $key => $value) {
            // ค่าที่ยังเป็นดอกจัน = ผู้ใช้ไม่ได้แตะช่องนั้น เก็บของเดิมไว้
            if (SettingsRepository::isSecret($key) && $value === '********') {
                unset($values[$key]);
            }
        }

        $settings->save($values);

        $message = sprintf('บันทึกค่าตั้ง %d รายการแล้ว', count($values));
        $dns = [];

        // เปิดสวิตช์ DNS ต้อง "เปิดจริง" ไม่ใช่แค่จำค่าไว้ — ดู activateDns()
        if (($values['dns.enabled'] ?? '') === '1') {
            $dns = $this->activateDns($executor, $settings, $context->config->dnsZoneDir());
            $message .= ' · ' . $dns['message'];
        }

        return [
            'saved' => count($values),
            'notify_active' => (new Notifier($context->db, $executor))->isActive(),
            'notify_channels' => (new Notifier($context->db, $executor))->activeChannels(),
            'dns' => $dns,
            'message' => $message,
        ];
    }

    /**
     * ทำให้ BIND9 พร้อมใช้งานจริงหลังผู้ดูแลเปิดสวิตช์ `dns.enabled`
     *
     * **เดิมสวิตช์นี้แค่บันทึกค่าลงฐานข้อมูลเฉย ๆ** ผลคือบนเครื่องที่ติดตั้งโดยไม่ได้ส่ง
     * `--dns-ns` (ซึ่งเป็นค่าเริ่มต้นของ install.sh) ตัวแพ็กเกจ bind9 ถูกลงไว้แล้วก็จริง
     * แต่ service ไม่เคยถูก enable/start และ `/etc/bind/zones` ไม่เคยถูกสร้าง · ผู้ดูแล
     * กดเปิดสวิตช์แล้วหน้าจอบอก "บันทึกแล้ว" แต่พอไปเพิ่มเรกคอร์ดจริงกลับล้มที่
     * `rndc reload` ด้วยข้อความที่ไม่ได้ชี้ว่าต้องไป start service ก่อน
     *
     * ที่นี่จึงทำสิ่งที่ `install.sh --dns-ns` ทำให้ตอนติดตั้ง ให้ครบในคำขอเดียว
     * — นี่คือความหมายของ "ตั้งค่าให้เสร็จได้จากหน้าเว็บ" ไม่ใช่แค่บันทึกความตั้งใจไว้
     *
     * ไม่โยน exception เมื่อล้ม เพราะค่าถูกบันทึกไปแล้วและยังแก้ต่อได้จากหน้า Services —
     * รายงานสิ่งที่เกิดขึ้นจริงกลับไปแทน ผู้ดูแลจะได้รู้ว่าเหลืออะไรต้องทำ
     *
     * @return array{ready:bool,unit:string,message:string}
     */
    private function activateDns(Executor $executor, SettingsRepository $settings, string $zoneDir): array
    {
        $nameservers = trim($settings->get('dns.nameservers'));

        if ($nameservers === '') {
            return [
                'ready' => false,
                'unit' => '',
                'message' => 'ยังสร้าง zone ไม่ได้จนกว่าจะกรอกชื่อเนมเซิร์ฟเวอร์ — BIND9 ปฏิเสธ zone ที่ไม่มี NS record',
            ];
        }

        // zone dir ต้องมีก่อน BindZoneManager จะเขียนไฟล์ลงไปได้
        $executor->makeDirectory($executor->path(rtrim($zoneDir, '/')), 0755);

        // ชื่อ unit ต่างกันตาม distro/รุ่น: Debian รุ่นใหม่ใช้ `named`, รุ่นเก่าใช้ `bind9`
        foreach (['named', 'bind9'] as $unit) {
            if (!(ServiceProbe::read($executor, $unit)['installed'] ?? false)) {
                continue;
            }

            $result = $executor->exec(
                [$executor->path('/usr/bin/systemctl'), 'enable', '--now', $unit],
                timeout: 30,
            );

            $running = ServiceProbe::read($executor, $unit)['running'] ?? false;

            if ($result->ok() || $running) {
                return [
                    'ready' => true,
                    'unit' => $unit,
                    'message' => sprintf('เปิดบริการ %s และตั้งให้เริ่มตอนบูตแล้ว', $unit),
                ];
            }

            return [
                'ready' => false,
                'unit' => $unit,
                'message' => sprintf(
                    'เปิดบริการ %s ไม่สำเร็จ: %s — สั่งเองได้ที่หน้าบริการ',
                    $unit,
                    trim($result->stderr) !== '' ? trim($result->stderr) : 'ไม่ทราบสาเหตุ',
                ),
            ];
        }

        return [
            'ready' => false,
            'unit' => '',
            'message' => 'เครื่องนี้ยังไม่ได้ติดตั้ง BIND9 — ติดตั้งด้วย `sudo apt install bind9` แล้วเปิดสวิตช์นี้อีกครั้ง',
        ];
    }
}
