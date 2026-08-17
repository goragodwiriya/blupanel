<?php

declare(strict_types=1);

namespace Phpcp\Cli;

/**
 * A terminal output helper — automatically turns off color when the output
 * isn't a terminal (such as when piped into a log or run from systemd)
 */
final class Console
{
    private readonly bool $colors;

    public function __construct()
    {
        $this->colors = PHP_SAPI === 'cli'
            && function_exists('posix_isatty')
            && @posix_isatty(STDOUT)
            && getenv('NO_COLOR') === false;
    }

    public function line(string $text = ''): void
    {
        fwrite(STDOUT, $text . "\n");
    }

    public function title(string $text): void
    {
        $this->line();
        $this->line($this->paint($text, '1;37'));
        $this->line($this->paint(str_repeat('─', min(60, mb_strlen($text) + 6)), '90'));
    }

    public function ok(string $text): void
    {
        $this->line($this->paint('  ✔ ', '32') . $text);
    }

    public function warn(string $text): void
    {
        $this->line($this->paint('  ! ', '33') . $text);
    }

    public function fail(string $text): void
    {
        fwrite(STDERR, $this->paint('  ✘ ', '31') . $text . "\n");
    }

    public function info(string $text): void
    {
        $this->line($this->paint('  · ', '90') . $text);
    }

    public function item(string $label, string $value, string $tone = ''): void
    {
        $pad = max(0, 22 - mb_strlen($label));
        $this->line('  ' . $label . str_repeat(' ', $pad) . ($tone !== '' ? $this->paint($value, $tone) : $value));
    }

    public function box(string $label, string $value): void
    {
        $width = max(mb_strlen($label), mb_strlen($value)) + 4;
        $this->line();
        $this->line('  ┌' . str_repeat('─', $width) . '┐');
        $this->line('  │  ' . $label . str_repeat(' ', $width - mb_strlen($label) - 3) . '│');
        $this->line('  │  ' . $this->paint($value, '1;36') . str_repeat(' ', $width - mb_strlen($value) - 3) . '│');
        $this->line('  └' . str_repeat('─', $width) . '┘');
        $this->line();
    }

    /** Reads a value from the user — display can be hidden, for a password */
    public function ask(string $question, bool $hidden = false): string
    {
        fwrite(STDOUT, '  ' . $question . ': ');

        if ($hidden && function_exists('shell_exec')) {
            @shell_exec('stty -echo 2>/dev/null');
            $value = (string) fgets(STDIN);
            @shell_exec('stty echo 2>/dev/null');
            fwrite(STDOUT, "\n");
        } else {
            $value = (string) fgets(STDIN);
        }

        return trim($value);
    }

    public function confirm(string $question): bool
    {
        return in_array(strtolower($this->ask($question . ' [y/N]')), ['y', 'yes'], true);
    }

    private function paint(string $text, string $code): string
    {
        return $this->colors ? "\033[{$code}m{$text}\033[0m" : $text;
    }
}
