<?php

declare(strict_types=1);

namespace Phpcp\Agent;

/**
 * The message format between tier 1 and tier 2 — one JSON request per line
 *
 * Newline-delimited JSON was chosen because: it's easy to read while debugging, it
 * needs no special parser, and there's no state left over between requests, which
 * lets the agent fork a child per connection in a straightforward way.
 *
 * Changing this format later touches every file, so it had to be locked down as
 * of Phase 0 (ROADMAP §3).
 */
final class Protocol
{
    public const VERSION = 1;

    /** The largest size allowed for one message — stops a client sending data forever until memory runs out */
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
            throw new TransportError("Protocol version mismatch (got {$version}, expected " . self::VERSION . ')');
        }

        $capability = $payload['cap'] ?? '';
        if (!is_string($capability) || $capability === '') {
            throw new TransportError('No command specified');
        }

        // The capability name must match the required format before it's used to look up the registry
        if (preg_match('/^[a-z][a-z0-9_]*\.[a-z][a-z0-9_]*$/', $capability) !== 1) {
            throw new UnknownCapability('Invalid command name format');
        }

        $args = $payload['args'] ?? [];
        if (!is_array($args)) {
            throw new TransportError('args must be an object');
        }

        $actor = $payload['actor'] ?? [];
        if (!is_array($actor)) {
            throw new TransportError('actor must be an object');
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
            throw new TransportError('Failed to encode data as JSON');
        }

        if (strlen($json) > self::MAX_FRAME) {
            throw new TransportError('Data exceeds the size limit');
        }

        return $json . "\n";
    }

    /** @return array<string,mixed> */
    private static function decode(string $line): array
    {
        $line = trim($line);
        if ($line === '') {
            throw new TransportError('Received an empty message');
        }

        if (strlen($line) > self::MAX_FRAME) {
            throw new TransportError('Message exceeds the size limit');
        }

        try {
            $payload = json_decode($line, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new TransportError('Message is not valid JSON: ' . $e->getMessage());
        }

        if (!is_array($payload)) {
            throw new TransportError('Message must be an object');
        }

        return $payload;
    }
}
