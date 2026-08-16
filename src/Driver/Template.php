<?php

declare(strict_types=1);

namespace Phpcp\Driver;

use Phpcp\Agent\ValidationError;

/**
 * Fills values into a config file template — ARCHITECTURE §4.3 "write files from a template + validated values"
 *
 * The rule enforced here: a value substituted into a template must never contain a newline or a control character.
 *
 * Why: Apache's and FPM's config files separate directives by line — if a
 * single value were allowed to carry a "\n" through, an attacker could
 * inject a new directive straight into the config file, a risk on the same
 * level as command injection — this has to be blocked at this layer, not
 * left hoping a capability's own validator catches every case.
 *
 * A value deliberately meant to be multi-line (e.g. a list of ServerAlias
 * entries) has to be built through lines(), which checks line by line and
 * wraps the result in a SafeBlock — making it obvious in the code exactly
 * where multiple lines are allowed.
 */
final class Template
{
    public function __construct(private readonly string $directory)
    {
    }

    /**
     * @param array<string,string|int|SafeBlock> $values
     */
    public function render(string $name, array $values): string
    {
        $file = $this->directory . '/' . str_replace(['..', "\0"], '', $name);

        if (!is_file($file)) {
            throw new \RuntimeException("Template not found: {$name}");
        }

        $content = file_get_contents($file);
        if ($content === false) {
            throw new \RuntimeException("Failed to read template: {$name}");
        }

        $replacements = [];
        foreach ($values as $key => $value) {
            $replacements['{{' . $key . '}}'] = $value instanceof SafeBlock
                ? $value->text                              // already checked line by line in lines()
                : self::assertSafe($key, (string) $value);
        }

        $result = strtr($content, $replacements);

        // A placeholder left unreplaced = the template and the code
        // disagree, and this must stop immediately — letting a config file
        // with a stray {{...}} go out would make configtest fail for a
        // reason nobody can see
        if (preg_match('/\{\{([A-Z_]+)\}\}/', $result, $m) === 1) {
            throw new \RuntimeException("Template {$name} still has an unset value: {$m[1]}");
        }

        return $result;
    }

    /**
     * Builds several lines of the same directive, e.g. several ServerAlias domains
     *
     * @param list<string> $values
     */
    public static function lines(string $directive, array $values, string $indent = '    '): SafeBlock
    {
        $out = [];

        foreach ($values as $value) {
            $out[] = $indent . $directive . ' ' . self::assertSafe($directive, $value);
        }

        return new SafeBlock(implode("\n", $out));
    }

    /**
     * Checks a single value before it's assembled into a SafeBlock by hand
     *
     * Exists for the shape that isn't "one directive per line" the way
     * lines() supports — e.g. nginx's server_name, which puts every domain
     * on a single line — without this path, whoever wrote the code would
     * end up building a SafeBlock from a raw string instead, skipping the check entirely.
     */
    public static function assertValue(string $key, string $value): string
    {
        return self::assertSafe($key, $value);
    }

    private static function assertSafe(string $key, string $value): string
    {
        if (preg_match('/[\x00-\x08\x0A-\x1F\x7F]/', $value) === 1) {
            throw new ValidationError("The value of {$key} contains a character not allowed in a config file");
        }

        return $value;
    }
}
