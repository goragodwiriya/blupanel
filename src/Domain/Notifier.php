<?php

declare(strict_types=1);

namespace Phpcp\Domain;

use Phpcp\Agent\Executor\Executor;
use Phpcp\Driver\Notify\EmailNotifier;
use Phpcp\Driver\Notify\TelegramNotifier;
use Phpcp\Driver\Notify\WebhookNotifier;
use Phpcp\Kernel\Db;

/**
 * ตัดสินใจว่าเรื่องไหนควรแจ้ง แล้วส่งไปยังช่องทางที่เปิดไว้
 *
 * แยกจาก TelegramNotifier เพราะสองอย่างนี้ตอบคนละคำถาม:
 * ตัวนั้นตอบ "ส่งข้อความยังไง" ตัวนี้ตอบ "เรื่องนี้ควรส่งไหม"
 * ถ้ารวมกัน การเพิ่มช่องทางที่สอง (เช่น Discord) จะต้องคัดลอกตรรกะการกรองทั้งชุด
 *
 * หลักการเลือกว่าอะไรควรแจ้ง: **แจ้งเฉพาะเรื่องที่ต้องลงมือทำ**
 * การแจ้งทุกอย่างที่เกิดขึ้นทำให้คนปิดการแจ้งเตือนภายในสัปดาห์เดียว
 * แล้วตอนที่เกิดเรื่องจริงก็จะไม่มีใครเห็น — ซึ่งแย่กว่าไม่มีระบบแจ้งเตือนเลย
 * เพราะผู้ดูแลเข้าใจว่าตัวเองมีระบบเฝ้าระวังอยู่
 */
final class Notifier
{
    /**
     * หมวดของเหตุการณ์ พร้อมคีย์ที่ใช้เปิด/ปิด
     *
     * @var array<string,string>
     */
    public const EVENTS = [
        'security' => 'notify.events.security',
        'ssl' => 'notify.events.ssl',
        'service' => 'notify.events.service',
        'backup' => 'notify.events.backup',
        'login' => 'notify.events.login',
        'quota' => 'notify.events.quota',
        'alert' => 'notify.events.alert',
    ];

    public const LABELS = [
        'security' => 'ความปลอดภัย — พบความเสี่ยงที่ต้องแก้',
        'ssl' => 'ใบรับรอง SSL — ใกล้หมดอายุหรือต่ออายุไม่สำเร็จ',
        'service' => 'บริการสำคัญหยุดทำงาน',
        'backup' => 'ผลการสำรองและกู้คืนข้อมูล',
        'login' => 'เข้าสู่ระบบล้มเหลวผิดปกติ',
        'quota' => 'โควตาพื้นที่ดิสก์ของบัญชีลูกค้าใกล้เต็ม',
        'alert' => 'เกณฑ์เตือนของเครื่อง — ดิสก์ แรม โหลด บริการ และใบรับรอง',
    ];

    private SettingsRepository $settings;

    /**
     * @param Executor|null $executor จำเป็นเฉพาะช่องทางอีเมล — การเรียก `sendmail` ต้องผ่าน
     *        `Executor` เสมอ (ARCHITECTURE §4.4) ต่างจาก Telegram/webhook ที่ยิง HTTPS ตรงได้
     *        · ผู้เรียกจากชั้น web tier ที่ไม่มี executor จึงส่งได้เฉพาะสองช่องทางนั้น
     */
    public function __construct(
        private readonly Db $db,
        private readonly ?Executor $executor = null,
    ) {
        $this->settings = new SettingsRepository($db);
    }

    /**
     * ส่งการแจ้งเตือนไปทุกช่องทางที่เปิดไว้ ถ้าหมวดนี้ถูกเปิด
     *
     * คืนค่าเป็น bool แทนการโยน exception เสมอ เพราะผู้เรียกคืองานหลักที่สำเร็จไปแล้ว
     * ความล้มเหลวของการแจ้งเตือนต้องไม่ย้อนกลับไปทำให้งานนั้นดูเหมือนล้มเหลว
     *
     * **ส่งทุกช่องทางที่เปิด ไม่ใช่ช่องแรกที่สำเร็จ** — ผู้ดูแลที่ตั้งทั้ง Telegram และอีเมล
     * ตั้งใจให้ได้รับทั้งสองทาง (มือถือกับกล่องจดหมายของทีม) · คืน true ถ้ามีอย่างน้อย
     * หนึ่งช่องที่ส่งออกได้ — ช่องที่ล้มไม่ทำให้ช่องที่สำเร็จถูกนับว่าล้มไปด้วย
     */
    public function send(string $event, string $title, string $body, string $level = 'info'): bool
    {
        if (!isset(self::EVENTS[$event])) {
            return false;
        }

        try {
            if (!$this->settings->bool(self::EVENTS[$event])) {
                return false;
            }

            $sent = false;

            if ($this->settings->bool('notify.telegram.enabled')) {
                $sent = (new TelegramNotifier(
                    $this->settings->get('notify.telegram.token'),
                    $this->settings->get('notify.telegram.chat_id'),
                ))->notify($title, $body, $level) || $sent;
            }

            if ($this->settings->bool('notify.webhook.enabled')) {
                $sent = (new WebhookNotifier(
                    $this->settings->get('notify.webhook.url'),
                    $this->settings->get('notify.webhook.secret'),
                ))->notify($event, $title, $body, $level) || $sent;
            }

            // อีเมลส่งได้เฉพาะเมื่อผู้เรียกมี executor ให้ — ไม่ใช่ความล้มเหลวถ้าไม่มี
            if ($this->executor !== null && $this->settings->bool('notify.email.enabled')) {
                $sent = (new EmailNotifier(
                    $this->executor,
                    $this->settings->get('notify.email.to'),
                    $this->settings->get('mail.from'),
                ))->notify($title, $body, $level) || $sent;
            }

            return $sent;
        } catch (\Throwable) {
            // รวมถึงกรณีฐานข้อมูลล็อกอยู่ — การแจ้งเตือนที่ส่งไม่ได้ต้องเงียบ
            return false;
        }
    }

    /** มีช่องทางที่ใช้งานได้จริงอย่างน้อยหนึ่งช่องหรือไม่ */
    public function isActive(): bool
    {
        return $this->activeChannels() !== [];
    }

    /**
     * ช่องทางที่ตั้งค่าครบและเปิดใช้งานอยู่จริง
     *
     * "เปิดสวิตช์ไว้แต่ยังไม่ได้กรอก token" ต้องไม่นับว่าใช้งานได้ — ไม่งั้นหน้าจอจะบอกว่า
     * ระบบแจ้งเตือนพร้อมแล้วทั้งที่ไม่มีอะไรส่งออกได้เลย ซึ่งอันตรายกว่าไม่มีระบบแจ้งเตือน
     *
     * @return list<string>
     */
    public function activeChannels(): array
    {
        try {
            $channels = [];

            if ($this->settings->bool('notify.telegram.enabled')
                && $this->settings->get('notify.telegram.token') !== ''
                && $this->settings->get('notify.telegram.chat_id') !== '') {
                $channels[] = 'telegram';
            }

            if ($this->settings->bool('notify.webhook.enabled')
                && $this->settings->get('notify.webhook.url') !== '') {
                $channels[] = 'webhook';
            }

            if ($this->settings->bool('notify.email.enabled')
                && $this->settings->get('notify.email.to') !== ''
                && $this->settings->get('mail.from') !== '') {
                $channels[] = 'email';
            }

            return $channels;
        } catch (\Throwable) {
            return [];
        }
    }
}
