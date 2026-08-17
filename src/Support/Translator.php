<?php

declare(strict_types=1);

namespace Phpcp\Support;

/**
 * Translates the text the system sends out to people — **using the same catalogue as the web page**
 *
 * `public/assets/spa/lang/th.json` is the whole project's single catalogue ·
 * the browser side already uses it to translate template text, and this
 * class reads that exact same file, so there are never two catalogues that
 * can drift apart from each other — adding one translation covers the web page, email, and CLI commands all at once
 *
 * **The key is the English text itself**, following Now.js's own rule
 * (`noTranslateEnglish`) — so PHP code always writes its text in English, and
 * every other language comes from the catalogue · a key with no translation
 * yet returns itself, which reads fine since it's a complete English sentence, not a code like `err.not_found`
 *
 * Interpolated values use the exact same `{name}` form as the Now.js side,
 * so the same piece of text can move between the two sides without being rewritten
 */
final class Translator
{
    /** @var array<string,string> */
    private array $catalogue;

    /** @var array<string,self> already-loaded instances — re-reading the catalogue on every request has no benefit */
    private static array $loaded = [];

    /**
     * @param array<string,string> $catalogue
     */
    public function __construct(public readonly string $locale, array $catalogue = [])
    {
        $this->catalogue = $catalogue;
    }

    /**
     * Reads the given locale's catalogue from the SPA's own directory
     *
     * English deliberately has no catalogue — the text in the code is
     * already English, so reading an `en.json` (which would be empty) would just be pointless work on every request
     */
    public static function load(string $locale, string $langDir): self
    {
        $locale = preg_match('/^[a-z]{2}$/', $locale) === 1 ? $locale : 'en';
        $key = $locale . '|' . $langDir;

        if (isset(self::$loaded[$key])) {
            return self::$loaded[$key];
        }

        $catalogue = [];
        $file = $langDir . '/' . $locale . '.json';

        if ($locale !== 'en' && is_file($file)) {
            $decoded = json_decode((string) file_get_contents($file), true);

            if (is_array($decoded)) {
                foreach ($decoded as $source => $text) {
                    if (is_string($source) && is_string($text)) {
                        $catalogue[$source] = $text;
                    }
                }
            }
        }

        return self::$loaded[$key] = new self($locale, $catalogue);
    }

    /**
     * @param array<string,string|int|float> $params values that replace `{name}` in the text
     */
    public function get(string $key, array $params = []): string
    {
        $text = $this->catalogue[$key] ?? $key;

        if ($params === []) {
            return $text;
        }

        $replace = [];

        foreach ($params as $name => $value) {
            $replace['{' . $name . '}'] = (string) $value;
        }

        return strtr($text, $replace);
    }

    /** Does a translation genuinely exist for this key? — used in tests that guard against a missing translation */
    public function has(string $key): bool
    {
        return isset($this->catalogue[$key]);
    }
}
