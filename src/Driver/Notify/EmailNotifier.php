<?php

declare(strict_types=1);

namespace Phpcp\Driver\Notify;

use Phpcp\Agent\ExecutionFailed;
use Phpcp\Agent\Executor\Executor;
use Phpcp\Agent\ValidationError;
use Phpcp\Driver\Mail\MailManager;

/**
 * แจ้งเตือนทางอีเมลผ่าน Postfix ที่ติดตั้งอยู่แล้ว — PLAN-V2 เฟส E6
 *
 * **ใช้ `sendmail` ของระบบ ไม่ต่อ SMTP เอง** — เหตุผลเดียวกับที่ `MailManager::sendTest()`
 * ทำ: สิ่งที่ต้องพิสูจน์คือเมลขาออกของ*เครื่องนี้*ใช้ได้จริง ไม่ใช่ว่า PHP ต่อ SMTP ที่ไหน
 * สักแห่งได้ · Postfix จัดคิว ลองใหม่ และเขียน log ให้อยู่แล้ว ซึ่งดีกว่าที่โค้ดนี้จะทำเอง
 *
 * **ต้องมี `Executor`** ต่างจาก Telegram/webhook ที่ยิง HTTPS ตรงได้ — การเรียกโปรแกรม
 * บนเครื่องต้องผ่าน `Executor` เสมอเพื่อให้ได้ audit, โหมด dryrun และการจำกัดสิทธิ์ครบชุด
 * (ARCHITECTURE §4.4) · ผู้เรียกที่ไม่มี executor จึงส่งอีเมลไม่ได้ ซึ่งถูกต้องแล้ว
 *
 * **เตือนแล้วต้องไม่กลายเป็นช่องส่งสแปม** — ผู้รับมาจากค่าตั้งของผู้ดูแลเท่านั้น ไม่เคยมา
 * จากข้อมูลที่ผู้ใช้ปลายทางกรอก และหัวข้อ/เนื้อความถูก encode ก่อนเสมอ
 */
final class EmailNotifier
{
    private const SENDMAIL = '/usr/sbin/sendmail';

    /** สั้นเพราะเป็นการแจ้งเตือน — Postfix รับเข้าคิวแล้วจบ ไม่ได้รอส่งจริง */
    private const TIMEOUT = 15;

    /** ตัดเนื้อความยาว ๆ ก่อนส่ง — log ทั้งไฟล์ไม่ควรกลายเป็นอีเมล */
    private const MAX_BODY = 4000;

    public function __construct(
        private readonly Executor $executor,
        private readonly string $to,
        private readonly string $from,
    ) {
    }

    public function isConfigured(): bool
    {
        return $this->to !== '' && $this->from !== '';
    }

    /** ส่งแบบ "ล้มได้ ไม่โยน error" — ใช้กับการแจ้งเตือนอัตโนมัติทุกจุด */
    public function notify(string $title, string $body, string $level = 'info'): bool
    {
        if (!$this->isConfigured()) {
            return false;
        }

        try {
            $this->send($title, $body, $level);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /** ส่งแบบ "ล้มแล้วต้องรู้" — ใช้เฉพาะตอนผู้ใช้กดปุ่มทดสอบ */
    public function test(): array
    {
        if (!$this->isConfigured()) {
            throw new ValidationError('ยังไม่ได้ตั้งอีเมลผู้รับหรือผู้ส่งสำหรับการแจ้งเตือน');
        }

        $this->send(
            'ทดสอบการแจ้งเตือนทางอีเมล',
            "ถ้าคุณได้รับข้อความนี้ แปลว่าการแจ้งเตือนทางอีเมลทำงานแล้ว\n"
            . 'ส่งจาก PHP Server Control Panel เมื่อ ' . date('d/m/Y H:i:s'),
            'ok',
        );

        return ['to' => $this->to];
    }

    private function send(string $title, string $body, string $level): void
    {
        $icon = match ($level) {
            'ok' => '[OK]',
            'warn', 'warning' => '[เตือน]',
            'danger' => '[ด่วน]',
            default => '[แจ้งเตือน]',
        };

        $subject = $icon . ' ' . $title;

        // หัวข้อที่มีอักษรไทยต้อง encode เป็น Base64 ตาม RFC 2047 ไม่งั้นตัวอ่านเมล
        // แสดงเป็นอักขระเพี้ยน · เนื้อความประกาศ charset ไว้ที่ header แล้วส่งดิบได้
        $message = sprintf(
            "From: %s\r\nTo: %s\r\nSubject: =?UTF-8?B?%s?=\r\n"
            . "X-Phpcp-Level: %s\r\n"
            . "MIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\n\r\n%s\r\n",
            $this->from,
            $this->to,
            base64_encode($subject),
            $level,
            $this->footer(mb_substr($body, 0, self::MAX_BODY)),
        );

        $result = $this->executor->exec(
            [$this->executor->path(self::SENDMAIL), '-t', '-i', '-f', $this->from],
            timeout: self::TIMEOUT,
            stdin: $message,
        );

        if (!$result->ok()) {
            throw new ExecutionFailed('ส่งอีเมลแจ้งเตือนไม่สำเร็จ: ' . trim($result->stderr ?: $result->stdout));
        }
    }

    /** บอกว่ามาจากเครื่องไหน — ผู้ดูแลที่ดูหลายเครื่องต้องแยกออกได้จากตัวอีเมลเอง */
    private function footer(string $body): string
    {
        return $body . "\n\n-- \n" . 'phpcp บนเครื่อง ' . (gethostname() ?: 'ไม่ทราบชื่อ');
    }

    /** ตรวจรูปแบบอีเมลตอนบันทึกค่าตั้ง — ใช้กฎเดียวกับเมลขาออกทั้งระบบ */
    public static function assertEmail(string $email): string
    {
        return $email === '' ? '' : MailManager::assertEmail($email);
    }
}
