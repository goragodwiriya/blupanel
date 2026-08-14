<?php

declare(strict_types=1);

namespace Phpcp\Kernel;

/**
 * คำตอบหนึ่งครั้ง — สะสม header/คุกกี้ไว้แล้วส่งทีเดียวตอนจบ
 *
 * ทำเป็น object แทนการ echo ตรง ๆ เพื่อให้ middleware เติม header ความปลอดภัยทับได้
 * และเพื่อให้เขียนเทสต์โดยไม่ต้องมี output buffer
 */
final class Response
{
    /** @var array<string,string> */
    private array $headers = [];

    /** @var list<array{name:string,value:string,options:array<string,mixed>}> */
    private array $cookies = [];

    /**
     * ตัวผลิตเนื้อคำตอบทีละก้อน — null = คำตอบธรรมดาที่เนื้อทั้งก้อนอยู่ใน `$body` แล้ว
     *
     * มีไว้เพื่อไฟล์ที่ใหญ่เกินกว่าจะถือไว้ในหน่วยความจำทั้งไฟล์ · ดู {@see stream()}
     *
     * @var (callable(callable(string):void):void)|null
     */
    private $producer = null;

    private function __construct(
        private string $body,
        private int $status,
        string $contentType,
    ) {
        $this->headers['Content-Type'] = $contentType;
    }

    public static function html(string $body, int $status = 200): self
    {
        return new self($body, $status, 'text/html; charset=UTF-8');
    }

    /** @param array<string,mixed> $data */
    public static function json(array $data, int $status = 200): self
    {
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        /*
         * คำตอบ JSON คือ**สถานะสด**ของเครื่อง ห้ามให้เบราว์เซอร์เก็บไว้ใช้ซ้ำ
         *
         * GET ที่ไม่มี header กำกับ เบราว์เซอร์เก็บแคชเองได้ตามใจ (heuristic caching) ·
         * URL ของเราคงที่ทุกครั้ง เช่น `/api/v2/metrics/history?range=24h` ที่หน้า
         * เซิร์ฟเวอร์เรียกซ้ำทุกนาที — **กราฟจึงค้างอยู่ที่เวลาที่โหลดสำเร็จครั้งแรก
         * ตลอดไป** ทั้งที่ตัวเก็บข้อมูลเขียนแถวใหม่ทุกนาทีและ API ตอบข้อมูลสด
         * (เจอบนเซิร์ฟเวอร์จริง 2026-08-14 — กราฟค้างที่เวลาเดิมนานหลายชั่วโมง)
         *
         * ตั้งที่นี่ที่เดียวเพราะเป็นจริงกับทุก endpoint ของ API ไม่ใช่แค่ metrics ·
         * ผู้เรียกที่อยากให้แคชได้จริง ๆ ใช้ `withHeader()` ทับได้
         */
        return (new self($json === false ? '{}' : $json, $status, 'application/json; charset=UTF-8'))
            ->withHeader('Cache-Control', 'no-store');
    }

    public static function text(string $body, int $status = 200): self
    {
        return new self($body, $status, 'text/plain; charset=UTF-8');
    }

    /**
     * คำตอบที่ทยอยส่งออกทีละก้อน — สำหรับไฟล์ที่ใหญ่เกินกว่าจะถือไว้ทั้งไฟล์
     *
     * `$producer` รับฟังก์ชัน `emit(string $chunk)` แล้วเรียกมันกี่ครั้งก็ได้ ·
     * ทุกก้อนถูกส่งออกทันทีและล้าง buffer ตาม เพื่อให้เบราว์เซอร์เริ่มบันทึกไฟล์
     * ได้ตั้งแต่ก้อนแรก และหน่วยความจำของ PHP ไม่โตตามขนาดไฟล์
     *
     * **ไม่ส่ง `Content-Length`** เพราะผู้ผลิตอาจยังไม่รู้ขนาดรวมตอนเริ่ม ·
     * ผู้เรียกที่รู้ขนาดแน่นอนใส่เองได้ด้วย `withHeader()` แล้วเบราว์เซอร์จะขึ้น
     * แถบความคืบหน้าให้
     *
     * `body()` ยังคืนเนื้อทั้งก้อนได้ (ประกอบให้ตอนถาม) เพื่อให้เทสต์ที่ตรวจคำตอบ
     * ไม่ต้องรู้ว่า endpoint ไหนสตรีมหรือไม่สตรีม
     *
     * @param callable(callable(string):void):void $producer
     */
    public static function stream(callable $producer, string $contentType = 'application/octet-stream'): self
    {
        $response = new self('', 200, $contentType);
        $response->producer = $producer;

        return $response;
    }

    public static function redirect(string $location, int $status = 303): self
    {
        $response = new self('', $status, 'text/html; charset=UTF-8');

        // ป้องกัน open redirect: ยอมเฉพาะ path ภายในระบบ
        $response->headers['Location'] = str_starts_with($location, '/') && !str_starts_with($location, '//')
            ? $location
            : '/';

        return $response;
    }

    public static function noContent(): self
    {
        return new self('', 204, 'text/plain; charset=UTF-8');
    }

    public function withHeader(string $name, string $value): self
    {
        $this->headers[$name] = $value;

        return $this;
    }

    /** @param array<string,mixed> $options */
    public function withCookie(string $name, string $value, array $options): self
    {
        $this->cookies[] = ['name' => $name, 'value' => $value, 'options' => $options];

        return $this;
    }

    public function status(): int
    {
        return $this->status;
    }

    public function body(): string
    {
        if ($this->producer === null) {
            return $this->body;
        }

        // ประกอบทั้งก้อนเฉพาะตอนมีคนถาม (เทสต์) — ทางส่งจริงไม่เคยเดินทางนี้
        $collected = '';
        ($this->producer)(static function (string $chunk) use (&$collected): void {
            $collected .= $chunk;
        });

        return $collected;
    }

    /** @return array<string,string> */
    public function headers(): array
    {
        return $this->headers;
    }

    public function send(): void
    {
        if (headers_sent()) {
            echo $this->body;

            return;
        }

        http_response_code($this->status);

        foreach ($this->headers as $name => $value) {
            header($name . ': ' . $value, true);
        }

        foreach ($this->cookies as $cookie) {
            setcookie($cookie['name'], $cookie['value'], $cookie['options']);
        }

        // ไม่ส่ง Content-Length เพราะอาจมีการบีบอัดที่ web server
        if ($this->producer === null) {
            echo $this->body;

            return;
        }

        /*
         * ทยอยส่งทีละก้อนแล้วล้าง buffer ทุกก้อน
         *
         * ไม่ล้าง = PHP สะสมทั้งไฟล์ไว้ในหน่วยความจำอยู่ดี ซึ่งลบล้างเหตุผลทั้งหมด
         * ที่ทางนี้มีอยู่ · `ob_flush()` ถูกเรียกเฉพาะเมื่อมี buffer อยู่จริง เพราะ
         * การเรียกตอนไม่มี buffer ทำให้ PHP ขึ้น notice ทุกก้อน
         */
        ($this->producer)(static function (string $chunk): void {
            echo $chunk;

            if (ob_get_level() > 0) {
                @ob_flush();
            }

            flush();
        });
    }
}
