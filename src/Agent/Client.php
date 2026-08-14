<?php

declare(strict_types=1);

namespace Phpcp\Agent;

/**
 * ฝั่งชั้นที่ 1 ใช้เรียก agent
 *
 * นี่คือ "ทางเดียว" ที่โค้ดฝั่งเว็บติดต่อกับระบบปฏิบัติการได้
 * ตัว panel ปิด exec/proc_open ของตัวเองไว้ใน php-fpm pool แล้ว (ARCHITECTURE §3.1)
 * จึงไม่มีทางอื่นให้เลือกใช้แม้จะมีช่องโหว่ RCE ในโค้ด PHP ของ panel
 */
final class Client
{
    public function __construct(
        private readonly string $socketPath,
        private readonly int $timeout = 30,
    ) {
    }

    public function socketPath(): string
    {
        return $this->socketPath;
    }

    public function isAvailable(): bool
    {
        if (!file_exists($this->socketPath)) {
            return false;
        }

        $stream = @stream_socket_client('unix://' . $this->socketPath, $code, $message, 2);
        if ($stream === false) {
            return false;
        }
        fclose($stream);

        return true;
    }

    /**
     * เรียก capability หนึ่งครั้ง
     *
     * @param array<string,mixed> $args
     * @return array{data:array<string,mixed>,meta:array<string,mixed>}
     */
    public function call(string $capability, array $args, Actor $actor): array
    {
        $stream = @stream_socket_client(
            'unix://' . $this->socketPath,
            $errno,
            $errstr,
            (float) min($this->timeout, 10),
        );

        if ($stream === false) {
            throw new TransportError(
                'ติดต่อ agent ไม่ได้ — ตรวจว่าบริการ phpcp-agentd ทำงานอยู่หรือไม่'
                . ($errstr !== '' ? " ({$errstr})" : '')
            );
        }

        try {
            stream_set_timeout($stream, $this->timeout);

            $request = Protocol::encodeRequest($capability, $args, $actor);
            if (@fwrite($stream, $request) === false) {
                throw new TransportError('ส่งคำสั่งไปยัง agent ไม่สำเร็จ');
            }

            $line = @fgets($stream, Protocol::MAX_FRAME);
            $meta = stream_get_meta_data($stream);

            if ($meta['timed_out'] ?? false) {
                throw new TransportError("agent ไม่ตอบกลับภายใน {$this->timeout} วินาที");
            }

            if ($line === false || $line === '') {
                throw new TransportError('agent ปิดการเชื่อมต่อโดยไม่ตอบกลับ');
            }

            $response = Protocol::decodeResponse($line);
        } finally {
            fclose($stream);
        }

        if (!$response['ok']) {
            $error = $response['error'] ?? ['code' => 'error', 'message' => 'เกิดข้อผิดพลาดที่ไม่ทราบสาเหตุ'];

            throw self::exceptionFor($error['code'], $error['message']);
        }

        return ['data' => $response['data'], 'meta' => $response['meta']];
    }

    /**
     * เรียกแล้วคืนเฉพาะ data ใช้เมื่อไม่สนใจ meta
     *
     * @param array<string,mixed> $args
     * @return array<string,mixed>
     */
    public function data(string $capability, array $args, Actor $actor): array
    {
        return $this->call($capability, $args, $actor)['data'];
    }

    /** แปลง error code จาก agent กลับเป็น exception ชนิดเดิม เพื่อให้ฝั่งเว็บ catch ได้ตรงชนิด */
    private static function exceptionFor(string $code, string $message): AgentException
    {
        return match ($code) {
            'validation_error' => new ValidationError($message),
            'protected_resource' => new ProtectedResource($message),
            'permission_denied' => new PermissionDenied($message),
            'unknown_capability' => new UnknownCapability($message),
            'execution_failed' => new ExecutionFailed($message),
            // agent รับคำสั่งแล้วโค้ดข้างในพัง — คนละเรื่องกับ "ติดต่อ agent ไม่ได้"
            'internal_error' => new InternalError($message),
            default => new TransportError($message),
        };
    }
}
