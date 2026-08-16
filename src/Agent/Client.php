<?php

declare(strict_types=1);

namespace Phpcp\Agent;

/**
 * The tier-1 side's way of calling the agent
 *
 * This is the "only path" the web-side code has to reach the operating system.
 * The panel disables its own exec/proc_open inside the php-fpm pool (ARCHITECTURE
 * §3.1), so there's no other route to use even if an RCE hole turned up in the
 * panel's own PHP code.
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
     * Calls a capability once
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
                'Could not reach the agent — check whether the phpcp-agentd service is running'
                . ($errstr !== '' ? " ({$errstr})" : '')
            );
        }

        try {
            stream_set_timeout($stream, $this->timeout);

            $request = Protocol::encodeRequest($capability, $args, $actor);
            if (@fwrite($stream, $request) === false) {
                throw new TransportError('Failed to send the command to the agent');
            }

            $line = @fgets($stream, Protocol::MAX_FRAME);
            $meta = stream_get_meta_data($stream);

            if ($meta['timed_out'] ?? false) {
                throw new TransportError("The agent did not respond within {$this->timeout} seconds");
            }

            if ($line === false || $line === '') {
                throw new TransportError('The agent closed the connection without responding');
            }

            $response = Protocol::decodeResponse($line);
        } finally {
            fclose($stream);
        }

        if (!$response['ok']) {
            $error = $response['error'] ?? ['code' => 'error', 'message' => 'An unknown error occurred'];

            throw self::exceptionFor($error['code'], $error['message']);
        }

        return ['data' => $response['data'], 'meta' => $response['meta']];
    }

    /**
     * Calls and returns only data — use when meta doesn't matter
     *
     * @param array<string,mixed> $args
     * @return array<string,mixed>
     */
    public function data(string $capability, array $args, Actor $actor): array
    {
        return $this->call($capability, $args, $actor)['data'];
    }

    /** Turns an error code from the agent back into the matching exception type, so the web side can catch it by its real type */
    private static function exceptionFor(string $code, string $message): AgentException
    {
        return match ($code) {
            'validation_error' => new ValidationError($message),
            'protected_resource' => new ProtectedResource($message),
            'permission_denied' => new PermissionDenied($message),
            'unknown_capability' => new UnknownCapability($message),
            'execution_failed' => new ExecutionFailed($message),
            // The agent received the command and code inside it broke — a different thing from "couldn't reach the agent"
            'internal_error' => new InternalError($message),
            default => new TransportError($message),
        };
    }
}
