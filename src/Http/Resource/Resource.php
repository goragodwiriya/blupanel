<?php

declare(strict_types=1);

namespace Phpcp\Http\Resource;

/**
 * The base for converting a database row → JSON the SPA can actually use
 *
 * **This layer's iron rule: always return raw values, formatting is never allowed here**
 *
 * File size is bytes (never "1.2 GB") · time is a unix timestamp (never "5
 * minutes ago") because formatting is the viewer's language and timezone to
 * decide, not the server's — if the server sent "5 minutes ago," the screen
 * could never switch to English, and the SPA could never sort or compute
 * from it further (this is what the old view layer did, and is one of the reasons this plan tears it out)
 */
abstract class Resource
{
    /** Converts a value that might be null into int or null — never turns null into 0 */
    protected static function intOrNull(mixed $value): ?int
    {
        return $value === null || $value === '' ? null : (int) $value;
    }

    /** SQLite stores booleans as 0/1 — the JSON side needs genuine true/false */
    protected static function bool(mixed $value): bool
    {
        return (int) $value === 1;
    }

    protected static function string(mixed $value): string
    {
        return $value === null ? '' : (string) $value;
    }

    /**
     * Converts a whole list using the same converter
     *
     * @param list<array<string,mixed>> $rows
     * @return list<array<string,mixed>>
     */
    public static function collection(array $rows): array
    {
        return array_map(static fn (array $row): array => static::one($row), array_values($rows));
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    abstract public static function one(array $row): array;
}
