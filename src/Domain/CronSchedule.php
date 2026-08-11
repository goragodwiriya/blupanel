<?php

declare(strict_types=1);

namespace Phpcp\Domain;

use Phpcp\Agent\ValidationError;

/**
 * ตรวจและอธิบายตารางเวลาแบบ cron
 *
 * ตรวจเองแทนที่จะปล่อยให้ cron ปฏิเสธทีหลัง เพราะ cron ที่รูปแบบผิด
 * จะถูกข้ามไปเงียบ ๆ ทั้งไฟล์ ทำให้งานอื่นที่ถูกต้องในไฟล์เดียวกันไม่ทำงานตามไปด้วย
 */
final class CronSchedule
{
    /** ช่วงค่าที่ยอมรับของแต่ละฟิลด์ ตามลำดับใน crontab */
    private const RANGES = [
        ['นาที', 0, 59],
        ['ชั่วโมง', 0, 23],
        ['วันที่', 1, 31],
        ['เดือน', 1, 12],
        ['วันในสัปดาห์', 0, 7],
    ];

    /** ตัวย่อที่ cron รองรับ แปลงเป็นห้าฟิลด์ให้เลย */
    private const ALIASES = [
        '@yearly' => '0 0 1 1 *',
        '@annually' => '0 0 1 1 *',
        '@monthly' => '0 0 1 * *',
        '@weekly' => '0 0 * * 0',
        '@daily' => '0 0 * * *',
        '@midnight' => '0 0 * * *',
        '@hourly' => '0 * * * *',
    ];

    /** ตัวเลือกสำเร็จรูปที่ให้เลือกบนหน้าจอ */
    public static function presets(): array
    {
        return [
            '*/5 * * * *' => 'ทุก 5 นาที',
            '*/15 * * * *' => 'ทุก 15 นาที',
            '0 * * * *' => 'ทุกชั่วโมง',
            '0 1 * * *' => 'ทุกวัน เวลา 01:00 น.',
            '0 3 * * 0' => 'ทุกสัปดาห์ (อาทิตย์ 03:00 น.)',
            '0 4 1 * *' => 'ทุกเดือน (วันที่ 1 เวลา 04:00 น.)',
        ];
    }

    /**
     * ตรวจรูปแบบแล้วคืนตารางเวลาที่เป็นห้าฟิลด์เสมอ
     */
    public static function normalize(string $schedule): string
    {
        $schedule = trim(preg_replace('/\s+/', ' ', $schedule) ?? '');

        if ($schedule === '') {
            throw new ValidationError('ต้องระบุตารางเวลา');
        }

        if (isset(self::ALIASES[strtolower($schedule)])) {
            return self::ALIASES[strtolower($schedule)];
        }

        if (str_starts_with($schedule, '@')) {
            throw new ValidationError('รองรับตัวย่อเฉพาะ @hourly @daily @weekly @monthly @yearly');
        }

        $fields = explode(' ', $schedule);

        if (count($fields) !== 5) {
            throw new ValidationError('ตารางเวลาต้องมี 5 ฟิลด์ เช่น "0 3 * * *"');
        }

        foreach ($fields as $index => $field) {
            [$label, $min, $max] = self::RANGES[$index];
            self::assertField($field, $label, $min, $max);
        }

        return implode(' ', $fields);
    }

    /** ตรวจฟิลด์เดียว รองรับ * , - / ตามที่ cron รองรับจริง */
    private static function assertField(string $field, string $label, int $min, int $max): void
    {
        foreach (explode(',', $field) as $part) {
            if ($part === '') {
                throw new ValidationError("ฟิลด์{$label}มีรูปแบบไม่ถูกต้อง");
            }

            // แยกส่วน step ออกก่อน เช่น */5 หรือ 1-30/2
            $step = null;
            if (str_contains($part, '/')) {
                [$part, $stepText] = explode('/', $part, 2);

                if (preg_match('/^\d{1,2}$/', $stepText) !== 1 || (int) $stepText < 1) {
                    throw new ValidationError("ค่า step ของฟิลด์{$label}ไม่ถูกต้อง");
                }

                $step = (int) $stepText;
            }

            if ($part === '*') {
                continue;
            }

            if (str_contains($part, '-')) {
                [$from, $to] = explode('-', $part, 2);
                self::assertNumber($from, $label, $min, $max);
                self::assertNumber($to, $label, $min, $max);

                if ((int) $from > (int) $to) {
                    throw new ValidationError("ช่วงของฟิลด์{$label}กลับด้าน");
                }

                continue;
            }

            self::assertNumber($part, $label, $min, $max);

            // ตัวเลขเดี่ยวกับ step ใช้ด้วยกันไม่ได้ใน cron มาตรฐาน
            if ($step !== null) {
                throw new ValidationError("ฟิลด์{$label}ใช้ step กับค่าเดี่ยวไม่ได้");
            }
        }
    }

    private static function assertNumber(string $value, string $label, int $min, int $max): void
    {
        if (preg_match('/^\d{1,2}$/', $value) !== 1) {
            throw new ValidationError("ฟิลด์{$label}ต้องเป็นตัวเลข");
        }

        $number = (int) $value;

        if ($number < $min || $number > $max) {
            throw new ValidationError("ฟิลด์{$label}ต้องอยู่ระหว่าง {$min} ถึง {$max}");
        }
    }

    /**
     * ตารางเวลานี้ตรงกับเวลาที่ระบุหรือไม่ — ตัดสินที่ความละเอียดระดับนาที
     *
     * ใช้กฎเดียวกับ cron ของ Unix ทุกข้อ รวมถึงข้อที่คนมองข้ามบ่อยที่สุด:
     * ถ้า "วันที่" กับ "วันในสัปดาห์" ถูกระบุทั้งคู่ (ไม่ใช่ *) จะตรงเมื่อ**ข้อใดข้อหนึ่ง**ตรง
     * ไม่ใช่ต้องตรงทั้งคู่ — `0 0 1 * 1` คือ "วันที่ 1 ของเดือน หรือทุกวันจันทร์"
     */
    public static function matches(string $schedule, int $timestamp): bool
    {
        [$minute, $hour, $day, $month, $weekday] = explode(' ', self::normalize($schedule));

        if (!in_array((int) date('i', $timestamp), self::expand($minute, 0, 59), true)) {
            return false;
        }

        if (!in_array((int) date('G', $timestamp), self::expand($hour, 0, 23), true)) {
            return false;
        }

        if (!in_array((int) date('n', $timestamp), self::expand($month, 1, 12), true)) {
            return false;
        }

        // 7 กับ 0 คืออาทิตย์เหมือนกันใน cron — ทำให้เป็นค่าเดียวก่อนเทียบ
        $weekdays = array_map(static fn (int $d): int => $d === 7 ? 0 : $d, self::expand($weekday, 0, 7));

        $dayMatches = in_array((int) date('j', $timestamp), self::expand($day, 1, 31), true);
        $weekdayMatches = in_array((int) date('w', $timestamp), $weekdays, true);

        if ($day !== '*' && $weekday !== '*') {
            return $dayMatches || $weekdayMatches;
        }

        return $dayMatches && $weekdayMatches;
    }

    /**
     * ถึงเวลาต้องรันหรือยัง เมื่อรู้ว่ารันครั้งล่าสุดเมื่อไร
     *
     * ไม่ได้ถามแค่ "นาทีนี้ตรงไหม" แต่ไล่ดูทุกนาทีตั้งแต่หลังรอบที่แล้วจนถึงตอนนี้
     * เพราะตัวจับเวลาอาจไม่ได้ทำงานทุกนาทีจริง (เครื่องดับ, timer ถูกหยุด, งานรอบก่อนใช้เวลานาน)
     * ถ้าเทียบแค่นาทีปัจจุบัน งานที่ตั้งไว้ตี 3 จะหายไปทั้งวันเพียงเพราะเครื่องปิดอยู่ตอนตี 3
     *
     * @param int|null $lastRunAt null = ยังไม่เคยรัน ให้ดูเฉพาะนาทีปัจจุบัน
     *                            (งานที่เพิ่งถูกเพิ่มไม่ควรระเบิดย้อนหลังทันทีที่ติดตั้ง)
     * @param int      $catchUp   ไล่ย้อนหลังได้ไกลสุดกี่วินาที — กันงานที่ค้างมานาน
     *                            รันรวดเดียวหลายสิบรอบตอนเครื่องกลับมา
     */
    public static function isDue(string $schedule, int $now, ?int $lastRunAt = null, int $catchUp = 86400): bool
    {
        $nowMinute = $now - ($now % 60);

        $from = $lastRunAt === null
            ? $nowMinute
            : max($lastRunAt - ($lastRunAt % 60) + 60, $nowMinute - max(0, $catchUp));

        for ($minute = $from; $minute <= $nowMinute; $minute += 60) {
            if (self::matches($schedule, $minute)) {
                return true;
            }
        }

        return false;
    }

    /**
     * คลี่ฟิลด์หนึ่งออกเป็นค่าที่เป็นไปได้ทั้งหมด
     *
     * รับเฉพาะรูปแบบที่ normalize() ยอมให้ผ่านแล้วเท่านั้น จึงไม่ต้องตรวจซ้ำที่นี่
     *
     * @return list<int>
     */
    private static function expand(string $field, int $min, int $max): array
    {
        $values = [];

        foreach (explode(',', $field) as $part) {
            $step = 1;

            if (str_contains($part, '/')) {
                [$part, $stepText] = explode('/', $part, 2);
                $step = max(1, (int) $stepText);
            }

            if ($part === '*') {
                $from = $min;
                $to = $max;
            } elseif (str_contains($part, '-')) {
                [$fromText, $toText] = explode('-', $part, 2);
                $from = (int) $fromText;
                $to = (int) $toText;
            } else {
                $values[] = (int) $part;
                continue;
            }

            for ($value = $from; $value <= $to; $value += $step) {
                $values[] = $value;
            }
        }

        return array_values(array_unique($values));
    }

    /** อธิบายตารางเวลาเป็นภาษาไทยแบบคร่าว ๆ สำหรับแสดงบนหน้าจอ */
    public static function describe(string $schedule): string
    {
        $known = self::presets();

        if (isset($known[$schedule])) {
            return $known[$schedule];
        }

        $fields = explode(' ', $schedule);
        if (count($fields) !== 5) {
            return $schedule;
        }

        [$minute, $hour, $day, $month, $weekday] = $fields;

        if ($minute !== '*' && $hour !== '*' && $day === '*' && $month === '*' && $weekday === '*') {
            return sprintf('ทุกวัน เวลา %02d:%02d น.', (int) $hour, (int) $minute);
        }

        if (str_starts_with($minute, '*/') && $hour === '*') {
            return 'ทุก ' . substr($minute, 2) . ' นาที';
        }

        if ($minute !== '*' && $hour === '*') {
            return sprintf('ทุกชั่วโมง นาทีที่ %d', (int) $minute);
        }

        return $schedule;
    }
}
