<?php

declare(strict_types=1);

namespace Phpcp\Http;

use Phpcp\Agent\AgentException;
use Phpcp\Agent\ExecutionFailed;
use Phpcp\Agent\InternalError;
use Phpcp\Agent\PermissionDenied;
use Phpcp\Agent\ProtectedResource;
use Phpcp\Agent\TransportError;
use Phpcp\Agent\ValidationError;
use Phpcp\Kernel\Request;
use Phpcp\Kernel\Response;

/**
 * รหัสข้อผิดพลาดของ REST API v2 — PLAN-V2 §4.2
 *
 * เป็น enum ไม่ใช่สตริงลอย ๆ เพราะฝั่ง SPA ต้อง "ตัดสินใจ" จากรหัสนี้ ไม่ใช่จากข้อความ:
 * 401 UNAUTHENTICATED ให้เด้งหน้าล็อกอิน · 419 CSRF_INVALID ให้ขอ token ใหม่แล้วลองใหม่ ·
 * 422 VALIDATION_ERROR ให้ทาสีช่องกรอกที่ผิด — ถ้าเทียบข้อความไทยแทน การแก้คำในข้อความ
 * จะทำให้หน้าเว็บพังโดยไม่มีใครรู้ตัว
 *
 * status code ผูกกับรหัสตายตัวที่นี่ที่เดียว จึงเป็นไปไม่ได้ที่ endpoint สองตัว
 * จะตอบ status ต่างกันด้วยรหัสเดียวกัน
 */
enum ApiProblem: string
{
    case ValidationError = 'VALIDATION_ERROR';
    case Unauthenticated = 'UNAUTHENTICATED';
    case TwoFactorRequired = 'TWO_FACTOR_REQUIRED';
    case PasswordChangeRequired = 'PASSWORD_CHANGE_REQUIRED';
    case Forbidden = 'FORBIDDEN';
    case NotFound = 'NOT_FOUND';
    case Conflict = 'CONFLICT';
    case CsrfInvalid = 'CSRF_INVALID';
    case RateLimited = 'RATE_LIMITED';
    case QuotaExceeded = 'QUOTA_EXCEEDED';
    case ProtectedResource = 'PROTECTED_RESOURCE';
    case AgentUnavailable = 'AGENT_UNAVAILABLE';
    case ExecutionFailed = 'EXECUTION_FAILED';
    case BadRequest = 'BAD_REQUEST';
    case MethodNotAllowed = 'METHOD_NOT_ALLOWED';
    case InternalError = 'INTERNAL_ERROR';

    /** เส้นทางที่ถือว่าเป็น REST API v2 — ทุกอย่างใต้เส้นทางนี้ตอบ JSON เสมอ */
    public const PREFIX = '/api/v2';

    public function status(): int
    {
        return match ($this) {
            self::BadRequest => 400,
            self::Unauthenticated, self::TwoFactorRequired => 401,
            self::Forbidden, self::PasswordChangeRequired, self::ProtectedResource => 403,
            self::NotFound => 404,
            self::MethodNotAllowed => 405,
            self::Conflict => 409,
            self::CsrfInvalid => 419,
            self::ValidationError, self::QuotaExceeded => 422,
            self::RateLimited => 429,
            self::InternalError, self::ExecutionFailed => 500,
            self::AgentUnavailable => 503,
        };
    }

    /**
     * คำขอนี้ต้องได้คำตอบเป็น JSON ตามสัญญา v2 หรือไม่
     *
     * เทียบจาก path อย่างเดียว ไม่ดู header `Accept` — สัญญาของ v2 คือ "ไม่มี HTML
     * หลุดออกไปแม้แต่ไบต์เดียว" ซึ่งต้องเป็นจริงแม้ผู้เรียกจะเปิด URL ในเบราว์เซอร์ตรง ๆ
     */
    public static function handles(Request $request): bool
    {
        return $request->path === self::PREFIX || str_starts_with($request->path, self::PREFIX . '/');
    }

    /**
     * แปลงเป็นคำตอบ JSON ตามรูปแบบใน §4.2
     *
     * @param array<string,string> $fields ชื่อฟิลด์ => เหตุผลที่ค่านั้นไม่ผ่าน
     */
    public function response(string $message, array $fields = []): Response
    {
        $error = ['code' => $this->value, 'message' => $message];

        if ($fields !== []) {
            $error['fields'] = $fields;
        }

        return Response::json(['ok' => false, 'error' => $error], $this->status());
    }

    /**
     * แปลงข้อผิดพลาดจาก agent เป็นรหัสของ API
     *
     * ชนิดของ exception คือสัญญาที่ชั้น agent ให้ไว้อยู่แล้ว การแปลงจึงเป็นแค่การ
     * เปลี่ยนภาษา ไม่ใช่การเดา — ที่สำคัญคือ TransportError ต้องเป็น 503 ไม่ใช่ 500
     * เพราะ "agent ไม่ตอบ" คือปัญหาชั่วคราวที่ลองใหม่แล้วหายได้ ต่างจากคำสั่งที่ล้มจริง
     */
    public static function fromAgentException(AgentException $e): self
    {
        return match (true) {
            $e instanceof ValidationError => self::ValidationError,
            $e instanceof PermissionDenied => self::Forbidden,
            $e instanceof ProtectedResource => self::ProtectedResource,
            $e instanceof TransportError => self::AgentUnavailable,
            // 500 ไม่ใช่ 503 — "ลองใหม่" ไม่ช่วยอะไรเมื่อโค้ดใน agent เป็นฝ่ายพัง
            $e instanceof InternalError => self::InternalError,
            $e instanceof ExecutionFailed => self::ExecutionFailed,
            default => self::InternalError,
        };
    }
}
