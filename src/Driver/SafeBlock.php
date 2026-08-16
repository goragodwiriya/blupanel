<?php

declare(strict_types=1);

namespace Phpcp\Driver;

/**
 * Multi-line text that has already been checked line by line
 *
 * Exists so Template can tell apart "this multi-line value is meant to be
 * multi-line" from an ordinary value, where a newline in it means someone is
 * attempting to inject a directive.
 *
 * The only way to construct one is through Template::lines(), which always checks every line first.
 */
final readonly class SafeBlock
{
    public function __construct(public string $text)
    {
    }

    public function __toString(): string
    {
        return $this->text;
    }
}
