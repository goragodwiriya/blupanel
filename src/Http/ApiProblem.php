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
 * REST API v2's error codes — PLAN-V2 §4.2
 *
 * An enum, not a loose string, because the SPA must "decide" from this code,
 * never from the message text: 401 UNAUTHENTICATED sends it to the login page
 * · 419 CSRF_INVALID asks for a new token and retries · 422 VALIDATION_ERROR
 * highlights the wrong field — comparing against message text instead would
 * mean editing a word in the message quietly breaks the frontend, with nobody noticing
 *
 * The status code is bound to a fixed code in this one place, so it's
 * impossible for two endpoints to answer with different statuses under the same code
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

    /** The path prefix counted as REST API v2 — everything under it always answers JSON */
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
     * Whether this request must be answered as JSON per v2's contract
     *
     * Compared from the path alone, never the `Accept` header — v2's contract
     * is "not one byte of HTML ever leaks out," which must hold true even when
     * a caller opens the URL directly in a browser
     */
    public static function handles(Request $request): bool
    {
        return $request->path === self::PREFIX || str_starts_with($request->path, self::PREFIX . '/');
    }

    /**
     * Convert to a JSON response in §4.2's shape
     *
     * @param array<string,string> $fields field name => the reason that value failed
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
     * Convert an agent error into an API code
     *
     * The exception's type is already the contract the agent layer provides —
     * this conversion is just translating vocabulary, not guessing · the
     * important part is that TransportError must be 503, not 500, because
     * "the agent isn't answering" is a temporary problem that a retry can fix,
     * unlike a command that genuinely failed
     */
    public static function fromAgentException(AgentException $e): self
    {
        return match (true) {
            $e instanceof ValidationError => self::ValidationError,
            $e instanceof PermissionDenied => self::Forbidden,
            $e instanceof ProtectedResource => self::ProtectedResource,
            $e instanceof TransportError => self::AgentUnavailable,
            // 500, not 503 — "retry" doesn't help when it's the agent's own code that's broken
            $e instanceof InternalError => self::InternalError,
            $e instanceof ExecutionFailed => self::ExecutionFailed,
            default => self::InternalError,
        };
    }
}
