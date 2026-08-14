<?php

declare(strict_types=1);

namespace Phpcp\Driver\Notify;

use Phpcp\Agent\ExecutionFailed;
use Phpcp\Agent\ValidationError;

/**
 * แจ้งเตือนผ่าน webhook — POST JSON ไปยัง URL ที่ผู้ดูแลตั้งไว้ (PLAN-V2 เฟส E6)
 *
 * มีไว้ต่อเข้าระบบที่ผู้ดูแลใช้อยู่แล้ว (Slack/Discord ผ่าน incoming webhook, ระบบ ticket,
 * ตัวรวม log) โดยไม่ต้องให้ panel รู้จักแต่ละบริการ — ส่ง JSON รูปเดียวตายตัวแล้วให้ปลายทาง
 * แปลงเอง ซึ่งเป็นสิ่งที่ทุกบริการทำได้อยู่แล้ว
 *
 * **ลงลายเซ็น HMAC-SHA256 ทุกครั้งเมื่อตั้ง secret ไว้** — ปลายทางตรวจได้ว่าข้อความมาจาก
 * เครื่องนี้จริง ไม่ใช่ใครก็ได้ที่เดา URL ถูก · ส่งใน header `X-Phpcp-Signature` รูปแบบ
 * `sha256=<hex>` ซึ่งเป็นแบบเดียวกับที่ GitHub ใช้ จึงมีตัวอย่างโค้ดฝั่งรับให้ลอกได้ทั่วไป
 *
 * **บังคับ HTTPS** — เนื้อหาการแจ้งเตือนบอกได้ว่าเครื่องไหนมีปัญหาอะไรอยู่ตอนนี้ ซึ่งเป็น
 * ข้อมูลตั้งต้นชั้นดีของการเลือกเป้าโจมตี · ยกเว้น `127.0.0.1`/`localhost` ที่วิ่งในเครื่อง
 * เดียวกันจึงไม่ผ่านเครือข่ายเลย (ใช้ต่อกับตัวรวม log ที่รันข้าง ๆ กัน)
 */
final class WebhookNotifier
{
    /** สั้นเพราะเป็นการแจ้งเตือน ไม่ใช่งานหลัก — ช้าไม่ได้ */
    private const TIMEOUT = 8;
    private const CONNECT_TIMEOUT = 5;

    /** ตัดเนื้อความยาว ๆ (เช่น stderr ของคำสั่งที่ล้ม) ก่อนส่งออกนอกเครื่อง */
    private const MAX_BODY = 4000;

    public function __construct(
        private readonly string $url,
        private readonly string $secret = '',
    ) {
    }

    public function isConfigured(): bool
    {
        return $this->url !== '';
    }

    /**
     * ส่งแบบ "ล้มได้ ไม่โยน error" — ใช้กับการแจ้งเตือนอัตโนมัติทุกจุด
     *
     * งานหลักสำเร็จไปแล้วตอนที่เรียกมาถึงตรงนี้ ถ้าปล่อยให้ exception หลุดออกไป
     * การกระทำที่สำเร็จแล้วจะถูกรายงานว่าล้มเหลวเพียงเพราะ webhook ปลายทางล่ม
     */
    public function notify(string $event, string $title, string $body, string $level = 'info'): bool
    {
        if (!$this->isConfigured()) {
            return false;
        }

        try {
            $this->send($event, $title, $body, $level);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * ส่งแบบ "ล้มแล้วต้องรู้" — ใช้เฉพาะตอนผู้ใช้กดปุ่มทดสอบ
     *
     * @return array{status:int}
     */
    public function test(): array
    {
        if (!$this->isConfigured()) {
            throw new ValidationError('ยังไม่ได้ตั้ง URL ของ webhook');
        }

        return ['status' => $this->send(
            'test',
            'ทดสอบการแจ้งเตือน',
            'ถ้าปลายทางได้รับข้อความนี้ แปลว่าการตั้งค่า webhook ถูกต้องแล้ว',
            'ok',
        )];
    }

    /** @return int รหัสสถานะ HTTP ที่ปลายทางตอบ */
    private function send(string $event, string $title, string $body, string $level): int
    {
        $payload = json_encode([
            'source' => 'phpcp',
            'host' => gethostname() ?: '',
            'event' => $event,
            'level' => $level,
            'title' => $title,
            'body' => mb_substr($body, 0, self::MAX_BODY),
            'sent_at' => date(DATE_ATOM),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($payload === false) {
            throw new ExecutionFailed('ประกอบข้อมูล webhook ไม่สำเร็จ');
        }

        $headers = ['Content-Type: application/json', 'User-Agent: phpcp/' . PHPCP_VERSION];

        if ($this->secret !== '') {
            // เซ็นจากเนื้อ payload ที่ส่งจริงเป๊ะ ๆ — ปลายทางต้องตรวจจาก raw body เช่นกัน
            $headers[] = 'X-Phpcp-Signature: sha256=' . hash_hmac('sha256', $payload, $this->secret);
        }

        $handle = curl_init($this->url);

        if ($handle === false) {
            throw new ExecutionFailed('เริ่มการเชื่อมต่อ webhook ไม่สำเร็จ');
        }

        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => self::TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            // ปลายทางที่ redirect ไป http:// จะข้ามการบังคับ HTTPS ที่ assertUrl() ทำไว้
            CURLOPT_FOLLOWLOCATION => false,
        ]);

        $raw = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error = curl_error($handle);
        curl_close($handle);

        if ($raw === false || $error !== '') {
            throw new ExecutionFailed('ส่ง webhook ไม่สำเร็จ: ' . $error);
        }

        if ($status < 200 || $status >= 300) {
            throw new ExecutionFailed("ปลายทางตอบรหัส {$status} — ตรวจว่า URL ถูกต้องและเปิดรับ POST");
        }

        return $status;
    }

    /**
     * ตรวจรูปแบบ URL ตอนบันทึกค่าตั้ง — จับความผิดพลาดตั้งแต่ตอนกรอก ไม่ใช่ตอนที่
     * การแจ้งเตือนสำคัญส่งไม่ออกในจังหวะที่ต้องการที่สุด
     */
    public static function assertUrl(string $url): string
    {
        if ($url === '') {
            return '';
        }

        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            throw new ValidationError('URL ของ webhook ผิดรูปแบบ');
        }

        $host = (string) (parse_url($url, PHP_URL_HOST) ?? '');

        // parse_url คืน IPv6 มาพร้อมวงเล็บ (`[::1]`) — ข้อยกเว้น localhost ที่เขียนไว้
        // ข้างบนจึงไม่เคยตรงกับ `::1` เลยจนกว่าจะถอดวงเล็บออกก่อนเทียบ
        $isLocal = in_array(trim($host, '[]'), ['127.0.0.1', 'localhost', '::1'], true);

        if (!str_starts_with($url, 'https://') && !$isLocal) {
            throw new ValidationError(
                'URL ของ webhook ต้องเป็น https:// — เนื้อหาการแจ้งเตือนบอกได้ว่าเครื่องนี้'
                . 'มีปัญหาอะไรอยู่ ซึ่งไม่ควรวิ่งผ่านเครือข่ายแบบไม่เข้ารหัส '
                . '(ยกเว้นปลายทางในเครื่องเดียวกัน)',
            );
        }

        /*
         * ชื่อผู้ใช้/รหัสผ่านใน URL — ปฏิเสธ ไม่ใช่ส่งต่อ
         *
         * มันจะถูกบันทึกลงตาราง settings เป็นข้อความธรรมดา (คีย์ `notify.webhook.url`
         * ไม่ใช่ชนิด `secret` จึงไม่ถูกปิดบังตอนส่งกลับไปหน้าจอ) และโผล่ในข้อความ
         * ผิดพลาดของ curl ด้วย · ใครที่อยากยืนยันตัวตนกับปลายทางควรใช้ HMAC ที่มีอยู่แล้ว
         */
        if (parse_url($url, PHP_URL_USER) !== null || parse_url($url, PHP_URL_PASS) !== null) {
            throw new ValidationError(
                'URL ของ webhook ต้องไม่มีชื่อผู้ใช้หรือรหัสผ่านฝังอยู่ — ค่านี้ถูกเก็บและ'
                . 'แสดงเป็นข้อความธรรมดา · ใช้ช่อง "รหัสลับ" เพื่อลงลายเซ็น HMAC แทน',
            );
        }

        if (!$isLocal) {
            self::assertNotInternal($host);
        }

        return $url;
    }

    /**
     * ปลายทางต้องไม่ใช่ที่อยู่ภายในเครือข่าย — กัน panel ถูกใช้เป็นตัวยิงแทน (SSRF)
     *
     * ผู้ดูแลที่ถูกหลอก (หรือบัญชีผู้ดูแลที่ถูกยึด) ตั้ง URL เป็น `https://10.0.0.5/`
     * หรือ `https://169.254.169.254/` ได้ · ปลายทางแรกคือบริการภายในที่ไม่ได้เปิดออก
     * อินเทอร์เน็ต ส่วนตัวหลังคือ metadata ของผู้ให้บริการคลาวด์ ซึ่งตอบ credential
     * ของเครื่องกลับมา · เครื่องนี้ยิงถึงทั้งคู่ได้ทั้งที่คนนอกยิงไม่ถึง
     *
     * **ที่อยู่ที่เป็นตัวเลขตรง ๆ ถูกตรวจเสมอ · ชื่อโฮสต์ตรวจเท่าที่แปลงได้**
     * ชื่อที่แปลงไม่ออก (DNS ล่ม, ยังไม่ได้ตั้งเรกคอร์ด) ไม่ถูกปฏิเสธ — การทำให้
     * ฟอร์มตั้งค่าใช้ไม่ได้ทุกครั้งที่ DNS สะดุด แลกกับด่านที่เลี่ยงได้อยู่แล้ว
     * ไม่คุ้ม · และด่านนี้ตรวจ ณ ตอนบันทึก ไม่ได้ตรึงที่อยู่ไว้ตอนส่ง ชื่อที่ชี้
     * ไปที่อยู่สาธารณะวันนี้แล้วเปลี่ยนเป็นที่อยู่ภายในพรุ่งนี้ (DNS rebinding)
     * จึงยังผ่านได้ — ข้อจำกัดที่ยอมรับสำหรับค่าที่มีแต่ผู้ดูแลเท่านั้นที่ตั้งได้
     */
    private static function assertNotInternal(string $host): void
    {
        $public = FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE;

        foreach (self::addressesOf($host) as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP, $public) === false) {
                throw new ValidationError(sprintf(
                    'URL ของ webhook ชี้ไปที่อยู่ภายใน (%s) — ปลายทางต้องเป็นที่อยู่สาธารณะ'
                    . ' เพื่อไม่ให้เครื่องนี้ถูกใช้ยิงเข้าเครือข่ายภายในแทนผู้อื่น',
                    $ip,
                ));
            }
        }
    }

    /** @return list<string> ที่อยู่ของโฮสต์ · ว่าง = แปลงไม่ได้ ซึ่งไม่ถือว่าผิด */
    private static function addressesOf(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }

        // ตัดวงเล็บของ IPv6 ในรูป URL (`[::1]`) ออกก่อน — parse_url คืนมาพร้อมวงเล็บ
        $bare = trim($host, '[]');

        if (filter_var($bare, FILTER_VALIDATE_IP) !== false) {
            return [$bare];
        }

        $records = @dns_get_record($host, DNS_A | DNS_AAAA);

        if (!is_array($records)) {
            return [];
        }

        $addresses = [];

        foreach ($records as $record) {
            $ip = (string) ($record['ip'] ?? $record['ipv6'] ?? '');

            if ($ip !== '') {
                $addresses[] = $ip;
            }
        }

        return $addresses;
    }
}
