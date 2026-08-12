<?php

declare(strict_types=1);

namespace Phpcp\Support;

/**
 * แปลข้อความที่ระบบส่งออกไปให้คน — **ใช้คลังคำเดียวกับหน้าเว็บ**
 *
 * `public/assets/spa/lang/th.json` คือคลังคำเดียวของทั้งโปรเจกต์ · ฝั่งเบราว์เซอร์
 * ใช้มันแปลข้อความในเทมเพลตอยู่แล้ว ที่นี่อ่านไฟล์เดียวกัน จึงไม่มีคลังคำสองชุด
 * ให้หลุดจากกัน — เพิ่มคำแปลครั้งเดียวได้ทั้งหน้าเว็บ อีเมล และคำสั่งบรรทัดคำสั่ง
 *
 * **คีย์คือข้อความภาษาอังกฤษ** ตามกติกาของ Now.js (`noTranslateEnglish`) — โค้ด PHP
 * จึงเขียนข้อความเป็นอังกฤษเสมอ แล้วภาษาอื่นมาจากคลังคำ · คีย์ที่ยังไม่มีคำแปลจะคืน
 * ตัวมันเอง ซึ่งอ่านรู้เรื่องเพราะเป็นประโยคอังกฤษเต็ม ไม่ใช่รหัสอย่าง `err.not_found`
 *
 * ค่าที่แทรกใช้รูป `{name}` เหมือนฝั่ง Now.js ทุกประการ เพื่อให้ข้อความเดียวกันย้าย
 * ไปมาระหว่างสองฝั่งได้โดยไม่ต้องเขียนใหม่
 */
final class Translator
{
    /** @var array<string,string> */
    private array $catalogue;

    /** @var array<string,self> ตัวที่โหลดแล้ว — คลังคำอ่านซ้ำทุกคำขอไม่มีประโยชน์ */
    private static array $loaded = [];

    /**
     * @param array<string,string> $catalogue
     */
    public function __construct(public readonly string $locale, array $catalogue = [])
    {
        $this->catalogue = $catalogue;
    }

    /**
     * อ่านคลังคำของภาษาที่ระบุจากไดเรกทอรีของ SPA
     *
     * ภาษาอังกฤษไม่มีคลังคำโดยตั้งใจ — ข้อความในโค้ดเป็นอังกฤษอยู่แล้ว การอ่าน
     * `en.json` (ซึ่งว่าง) จึงเป็นงานเปล่า ๆ ทุกคำขอ
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
     * @param array<string,string|int|float> $params ค่าที่แทนที่ `{ชื่อ}` ในข้อความ
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

    /** มีคำแปลของคีย์นี้จริงไหม — ใช้ในเทสต์ที่กันคำแปลตกหล่น */
    public function has(string $key): bool
    {
        return isset($this->catalogue[$key]);
    }
}
