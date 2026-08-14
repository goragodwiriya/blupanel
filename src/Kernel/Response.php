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
        return $this->body;
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
        echo $this->body;
    }
}
