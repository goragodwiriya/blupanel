<?php

declare(strict_types=1);

namespace Phpcp\Driver;

use Phpcp\Agent\ValidationError;

/**
 * เติมค่าลงเทมเพลตไฟล์ config — ARCHITECTURE §4.3 "เขียนไฟล์จาก template + ค่าที่ตรวจแล้ว"
 *
 * กฎที่บังคับที่นี่: ค่าที่แทนลงเทมเพลตห้ามมีขึ้นบรรทัดใหม่หรืออักขระควบคุม
 *
 * เหตุผล: ไฟล์ config ของ Apache และ FPM แยกคำสั่งด้วยบรรทัด ถ้าปล่อยให้ค่าหนึ่งค่า
 * มี "\n" ติดไปได้ ผู้โจมตีจะแทรก directive ใหม่เข้าไปในไฟล์ config ได้ทันที
 * ซึ่งอันตรายเทียบเท่า command injection — ต้องกันที่ชั้นนี้ ไม่ใช่หวังว่า validator
 * ของ capability จะกันครบทุกกรณี
 *
 * ค่าที่ตั้งใจให้เป็นหลายบรรทัด (เช่นรายการ ServerAlias) ต้องสร้างผ่าน lines()
 * ซึ่งตรวจทีละบรรทัดแล้วห่อเป็น SafeBlock — เห็นชัดในโค้ดว่าจุดไหนอนุญาตหลายบรรทัด
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
            throw new \RuntimeException("ไม่พบเทมเพลต: {$name}");
        }

        $content = file_get_contents($file);
        if ($content === false) {
            throw new \RuntimeException("อ่านเทมเพลตไม่ได้: {$name}");
        }

        $replacements = [];
        foreach ($values as $key => $value) {
            $replacements['{{' . $key . '}}'] = $value instanceof SafeBlock
                ? $value->text                              // ตรวจมาแล้วทีละบรรทัดใน lines()
                : self::assertSafe($key, (string) $value);
        }

        $result = strtr($content, $replacements);

        // เหลือ placeholder ที่ไม่ได้แทนค่า = เทมเพลตกับโค้ดไม่ตรงกัน ต้องหยุดทันที
        // ปล่อยไฟล์ config ที่ยังมี {{...}} ออกไปจะทำให้ configtest ล้มโดยไม่รู้สาเหตุ
        if (preg_match('/\{\{([A-Z_]+)\}\}/', $result, $m) === 1) {
            throw new \RuntimeException("เทมเพลต {$name} ยังมีค่าที่ไม่ได้กำหนด: {$m[1]}");
        }

        return $result;
    }

    /**
     * สร้างหลายบรรทัดของ directive เดียวกัน เช่น ServerAlias หลายโดเมน
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
     * ตรวจค่าเดี่ยวก่อนนำไปประกอบ SafeBlock ด้วยมือ
     *
     * มีไว้สำหรับกรณีที่รูปแบบไม่ใช่ "หนึ่ง directive ต่อหนึ่งบรรทัด" อย่างที่ lines() รองรับ
     * เช่น server_name ของ nginx ที่ใส่ทุกโดเมนไว้ในบรรทัดเดียว — ถ้าไม่มีทางนี้
     * ผู้เขียนโค้ดจะเลี่ยงไปสร้าง SafeBlock จากสตริงดิบ ซึ่งข้ามการตรวจไปทั้งหมด
     */
    public static function assertValue(string $key, string $value): string
    {
        return self::assertSafe($key, $value);
    }

    private static function assertSafe(string $key, string $value): string
    {
        if (preg_match('/[\x00-\x08\x0A-\x1F\x7F]/', $value) === 1) {
            throw new ValidationError("ค่าของ {$key} มีอักขระที่ไม่อนุญาตในไฟล์ config");
        }

        return $value;
    }
}
