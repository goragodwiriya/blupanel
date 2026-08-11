<?php

declare(strict_types=1);

namespace Phpcp\Agent;

/**
 * รูปแบบข้อความระหว่างชั้นที่ 1 กับชั้นที่ 2 — JSON หนึ่ง request ต่อหนึ่งบรรทัด
 *
 * เลือก newline-delimited JSON เพราะ: อ่านง่ายตอนดีบัก, ไม่ต้องมี parser พิเศษ,
 * และไม่มีสถานะค้างระหว่าง request ทำให้ agent fork ลูกต่อ connection ได้ตรงไปตรงมา
 *
 * เปลี่ยนรูปแบบนี้ทีหลังกระทบทุกไฟล์ จึงต้องล็อกให้นิ่งตั้งแต่เฟส 0 (ROADMAP §3)
 */
final class Protocol
{
    public const VERSION = 1;

    /** ขนาดสูงสุดต่อหนึ่งข้อความ — กัน client ส่งข้อมูลไม่หยุดจนหน่วยความจำหมด */
    public const MAX_FRAME = 4_194_304;

    /** @param array<string,mixed> $args */
    public static function encodeRequest(string $capability, array $args, Actor $actor): string
    {
        return self::encode([
            'v' => self::VERSION,
            'cap' => $capability,
            'args' => (object) $args,
            'actor' => $actor->toArray(),
        ]);
    }

    /**
     * @return array{cap:string,args:array<string,mixed>,actor:Actor}
     */
    public static function decodeRequest(string $line): array
    {
        $payload = self::decode($line);

        $version = (int) ($payload['v'] ?? 0);
        if ($version !== self::VERSION) {
            throw new TransportError("โปรโตคอลเวอร์ชันไม่ตรงกัน (ได้รับ {$version} ต้องการ " . self::VERSION . ')');
        }

        $capability = $payload['cap'] ?? '';
        if (!is_string($capability) || $capability === '') {
            throw new TransportError('ไม่ได้ระบุคำสั่ง');
        }

        // ชื่อ capability ต้องอยู่ในรูปแบบที่กำหนดก่อนเอาไปค้นทะเบียน
        if (preg_match('/^[a-z][a-z0-9_]*\.[a-z][a-z0-9_]*$/', $capability) !== 1) {
            throw new UnknownCapability('รูปแบบชื่อคำสั่งไม่ถูกต้อง');
        }

        $args = $payload['args'] ?? [];
        if (!is_array($args)) {
            throw new TransportError('args ต้องเป็น object');
        }

        $actor = $payload['actor'] ?? [];
        if (!is_array($actor)) {
            throw new TransportError('actor ต้องเป็น object');
        }

        return [
            'cap' => $capability,
            'args' => $args,
            'actor' => Actor::fromArray($actor),
        ];
    }

    /**
     * @param array<string,mixed> $data
     * @param array<string,mixed> $meta
     */
    public static function encodeSuccess(array $data, array $meta = []): string
    {
        return self::encode(['ok' => true, 'data' => $data, 'meta' => $meta]);
    }

    public static function encodeError(string $code, string $message, array $meta = []): string
    {
        return self::encode([
            'ok' => false,
            'error' => ['code' => $code, 'message' => $message],
            'meta' => $meta,
        ]);
    }

    /**
     * @return array{ok:bool,data:array<string,mixed>,meta:array<string,mixed>,error:?array{code:string,message:string}}
     */
    public static function decodeResponse(string $line): array
    {
        $payload = self::decode($line);

        $ok = (bool) ($payload['ok'] ?? false);
        $error = $payload['error'] ?? null;

        return [
            'ok' => $ok,
            'data' => is_array($payload['data'] ?? null) ? $payload['data'] : [],
            'meta' => is_array($payload['meta'] ?? null) ? $payload['meta'] : [],
            'error' => is_array($error)
                ? ['code' => (string) ($error['code'] ?? 'error'), 'message' => (string) ($error['message'] ?? '')]
                : null,
        ];
    }

    /** @param array<string,mixed> $payload */
    private static function encode(array $payload): string
    {
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($json === false) {
            throw new TransportError('แปลงข้อมูลเป็น JSON ไม่สำเร็จ');
        }

        if (strlen($json) > self::MAX_FRAME) {
            throw new TransportError('ข้อมูลใหญ่เกินกำหนด');
        }

        return $json . "\n";
    }

    /** @return array<string,mixed> */
    private static function decode(string $line): array
    {
        $line = trim($line);
        if ($line === '') {
            throw new TransportError('ได้รับข้อความว่าง');
        }

        if (strlen($line) > self::MAX_FRAME) {
            throw new TransportError('ข้อความใหญ่เกินกำหนด');
        }

        try {
            $payload = json_decode($line, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new TransportError('ข้อความไม่ใช่ JSON ที่ถูกต้อง: ' . $e->getMessage());
        }

        if (!is_array($payload)) {
            throw new TransportError('ข้อความต้องเป็น object');
        }

        return $payload;
    }
}
