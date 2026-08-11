<?php

declare(strict_types=1);

namespace Phpcp\Driver\Notify;

use Phpcp\Agent\ExecutionFailed;
use Phpcp\Agent\ValidationError;

/**
 * แจ้งเตือนผ่าน Telegram
 *
 * เลือก Telegram เพราะเป็นช่องทางเดียวที่ตั้งค่าเสร็จใน 2 นาทีโดยไม่ต้องมีโดเมน
 * ไม่ต้องมีเมลเซิร์ฟเวอร์ และไม่มีปัญหาจดหมายเข้าถังขยะ — ซึ่งเป็นเหตุผลว่าทำไม
 * การแจ้งเตือนทางอีเมลของ panel ส่วนใหญ่ถึงไม่มีใครได้รับจริง
 *
 * ข้อควรรู้ที่สำคัญที่สุด: **การแจ้งเตือนต้องไม่ทำให้งานหลักล้ม**
 * ถ้าเครือข่ายมีปัญหาหรือ Telegram ล่ม การสร้างเว็บไซต์ต้องยังสำเร็จเหมือนเดิม
 * ทุกเมธอดที่ส่งข้อความจึงกลืนข้อผิดพลาดไว้เอง ยกเว้นตอนที่ผู้ใช้กด "ทดสอบ" ซึ่งต้องดัง
 */
final class TelegramNotifier
{
    private const API = 'https://api.telegram.org/bot';

    /** สั้น ๆ เพราะนี่คือการแจ้งเตือน ไม่ใช่งานหลัก — ช้าไม่ได้ */
    private const TIMEOUT = 8;

    /** Telegram จำกัดข้อความละ 4096 ตัวอักษร */
    private const MAX_LENGTH = 3800;

    public function __construct(
        private readonly string $token,
        private readonly string $chatId,
    ) {
    }

    public function isConfigured(): bool
    {
        return $this->token !== '' && $this->chatId !== '';
    }

    /**
     * ส่งข้อความแบบ "ล้มได้ ไม่โยน error"
     *
     * ใช้กับการแจ้งเตือนอัตโนมัติทุกจุด — งานหลักสำเร็จไปแล้วตอนที่เรียกมาถึงตรงนี้
     * ถ้าปล่อยให้ exception หลุดออกไป การสร้างเว็บไซต์ที่สำเร็จแล้วจะถูกรายงานว่าล้มเหลว
     * เพียงเพราะส่งข้อความแจ้งเตือนไม่ได้
     */
    public function notify(string $title, string $body, string $level = 'info'): bool
    {
        if (!$this->isConfigured()) {
            return false;
        }

        try {
            $this->send($this->format($title, $body, $level));

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * ส่งข้อความแบบ "ล้มแล้วต้องรู้"
     *
     * ใช้เฉพาะตอนผู้ใช้กดปุ่มทดสอบ — ถ้าเงียบไปเฉย ๆ ผู้ใช้จะคิดว่าตั้งค่าถูกแล้ว
     * ทั้งที่ token ผิด แล้วจะไม่ได้รับการแจ้งเตือนจริงตอนที่จำเป็น
     */
    public function test(): array
    {
        if (!$this->isConfigured()) {
            throw new ValidationError('ยังไม่ได้ตั้งค่า token หรือ chat id ของ Telegram');
        }

        $response = $this->send($this->format(
            'ทดสอบการแจ้งเตือน',
            "ถ้าคุณเห็นข้อความนี้ แปลว่าการตั้งค่าถูกต้องแล้ว\n"
            . 'ส่งจาก PHP Server Control Panel เมื่อ ' . date('d/m/Y H:i:s'),
            'ok',
        ));

        return ['message_id' => $response['result']['message_id'] ?? 0];
    }

    /**
     * จัดรูปข้อความ
     *
     * ใช้โหมด HTML ของ Telegram และ escape เนื้อหาทุกส่วน — ข้อความแจ้งเตือนมี
     * ชื่อโดเมนและข้อความผิดพลาดจากระบบปนอยู่ ซึ่งอาจมี < > & ที่ทำให้ Telegram
     * ปฏิเสธทั้งข้อความ กลายเป็นการแจ้งเตือนที่หายไปเงียบ ๆ ในจังหวะที่สำคัญที่สุด
     */
    private function format(string $title, string $body, string $level): string
    {
        $icon = match ($level) {
            'ok' => '✅',
            'warn' => '⚠️',
            'danger' => '🚨',
            default => 'ℹ️',
        };

        $text = sprintf(
            "%s <b>%s</b>\n\n%s",
            $icon,
            htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            htmlspecialchars($body, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
        );

        return mb_strlen($text) > self::MAX_LENGTH
            ? mb_substr($text, 0, self::MAX_LENGTH) . "\n…"
            : $text;
    }

    /** @return array<string,mixed> */
    private function send(string $text): array
    {
        $handle = curl_init(self::API . $this->token . '/sendMessage');

        if ($handle === false) {
            throw new ExecutionFailed('เริ่มการเชื่อมต่อ Telegram ไม่สำเร็จ');
        }

        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => self::TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query([
                'chat_id' => $this->chatId,
                'text' => $text,
                'parse_mode' => 'HTML',
                'disable_web_page_preview' => 'true',
            ]),
        ]);

        $raw = curl_exec($handle);
        $error = curl_error($handle);
        curl_close($handle);

        if ($raw === false) {
            throw new ExecutionFailed('ส่งข้อความไม่สำเร็จ: ' . $error);
        }

        $data = json_decode((string) $raw, true);

        if (!is_array($data) || ($data['ok'] ?? false) !== true) {
            // ข้อความผิดพลาดของ Telegram ตรงไปตรงมาพอที่จะแสดงให้ผู้ใช้เห็นได้เลย
            // เช่น "chat not found" หรือ "Unauthorized" ซึ่งบอกทางแก้ในตัว
            throw new ExecutionFailed(
                'Telegram ปฏิเสธ: ' . (is_array($data) ? (string) ($data['description'] ?? 'ไม่ทราบสาเหตุ') : 'ตอบกลับผิดรูปแบบ'),
            );
        }

        return $data;
    }

    public static function assertToken(string $token): string
    {
        if ($token === '') {
            return '';
        }

        // รูปแบบของ token คือ <ตัวเลข>:<อักขระ 35 ตัว> ตรวจไว้เพื่อจับการวางผิดช่อง
        // ตั้งแต่ตอนบันทึก ดีกว่าไปรู้ตอนที่การแจ้งเตือนสำคัญส่งไม่ออก
        if (preg_match('/^\d{5,}:[A-Za-z0-9_-]{30,}$/', $token) !== 1) {
            throw new ValidationError('รูปแบบ token ของบอทไม่ถูกต้อง (ต้องเป็น 123456789:AA...)');
        }

        return $token;
    }

    public static function assertChatId(string $chatId): string
    {
        if ($chatId === '') {
            return '';
        }

        // รับได้ทั้งตัวเลข (รวมค่าติดลบของกลุ่ม) และ @username ของช่องสาธารณะ
        if (preg_match('/^(-?\d+|@[A-Za-z][A-Za-z0-9_]{4,})$/', $chatId) !== 1) {
            throw new ValidationError('chat id ต้องเป็นตัวเลข หรือ @username ของช่อง');
        }

        return $chatId;
    }
}
