<?php

declare (strict_types = 1);

namespace Phpcp\Support;

/**
 * จัดรูปแบบตัวเลขและเวลาให้เป็นภาษาไทย — ใช้ในเทมเพลตทุกหน้า
 *
 * รวมไว้ที่เดียวเพื่อให้หน่วยและคำเรียกตรงกันทั้งระบบ
 * (เช่น "12 วัน 3 ชั่วโมง" ไม่ใช่บางหน้าเขียน "12d 3h")
 */
final class Fmt
{
    /** ขนาดข้อมูลแบบฐาน 1024 */
    public static function bytes(int | float $bytes, int $decimals = 1): string
    {
        if ($bytes <= 0) {
            return '0 B';
        }

        $units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
        $power = min((int) floor(log((float) $bytes, 1024)), count($units) - 1);
        $value = $bytes / (1024 ** $power);

        return number_format($value, $power === 0 ? 0 : $decimals).' '.$units[$power];
    }

    /**
     * ระยะเวลาเป็นข้อความ เช่น "12d 3h"
     *
     * เป็นภาษาอังกฤษเพราะผู้เรียกมีแต่ CLI ซึ่งออกทางเทอร์มินัลของเครื่องปลายทาง
     * ที่วางสระ/วรรณยุกต์ไทยไม่ได้ (เหตุผลเดียวกับที่ install.sh เป็นอังกฤษทั้งไฟล์)
     */
    public static function duration(int $seconds): string
    {
        if ($seconds < 60) {
            return $seconds.'s';
        }

        $days = intdiv($seconds, 86400);
        $hours = intdiv($seconds % 86400, 3600);
        $minutes = intdiv($seconds % 3600, 60);

        if ($days > 0) {
            return $hours > 0 ? "{$days}d {$hours}h" : "{$days}d";
        }

        if ($hours > 0) {
            return $minutes > 0 ? "{$hours}h {$minutes}m" : "{$hours}h";
        }

        return "{$minutes}m";
    }

    /** เวลาแบบสัมพัทธ์ เช่น "3 minutes ago" — เป็นอังกฤษด้วยเหตุผลเดียวกับ duration() */
    public static function ago(?int $timestamp): string
    {
        if ($timestamp === null || $timestamp <= 0) {
            return '-';
        }

        $diff = time() - $timestamp;

        if ($diff < 0) {
            return 'in a moment';
        }
        if ($diff < 60) {
            return 'just now';
        }
        if ($diff < 3600) {
            return intdiv($diff, 60).' minutes ago';
        }
        if ($diff < 86400) {
            return intdiv($diff, 3600).' hours ago';
        }
        if ($diff < 2592000) {
            return intdiv($diff, 86400).' days ago';
        }

        // ไม่เรียก self::date() ตรงนี้ — มันคืนชื่อเดือนไทยแบบ พ.ศ. ซึ่งจะทำให้
        // ผลของ ago() กลับไปเป็นไทยเฉพาะรายการที่เก่ากว่า 30 วัน คือเคสที่หลุด
        // ตาการทดสอบง่ายที่สุด เพราะเครื่องที่เพิ่งติดตั้งยังไม่มีข้อมูลเก่าขนาดนั้น
        return date('Y-m-d', $timestamp);
    }

    /** วันที่แบบไทย พ.ศ. */
    public static function date(?int $timestamp, bool $withTime = false): string
    {
        if ($timestamp === null || $timestamp <= 0) {
            return '—';
        }

        $months = [
            1 => 'ม.ค.', 'ก.พ.', 'มี.ค.', 'เม.ย.', 'พ.ค.', 'มิ.ย.',
            'ก.ค.', 'ส.ค.', 'ก.ย.', 'ต.ค.', 'พ.ย.', 'ธ.ค.'
        ];

        $day = (int) date('j', $timestamp);
        $month = $months[(int) date('n', $timestamp)];
        $year = (int) date('Y', $timestamp) + 543;

        $out = "{$day} {$month} {$year}";

        return $withTime ? $out.' '.date('H:i', $timestamp).' น.' : $out;
    }

    /**
     * @param int $timestamp
     * @return mixed
     */
    public static function time(?int $timestamp): string
    {
        return $timestamp === null || $timestamp <= 0 ? '—' : date('H:i:s', $timestamp);
    }

    /**
     * @param float $value
     * @param int $decimals
     */
    public static function percent(float $value, int $decimals = 1): string
    {
        return number_format($value, $decimals).'%';
    }

    /** เลือกระดับสีของแถบวัดตามเปอร์เซ็นต์ที่ใช้ไป */
    public static function levelOf(float $percent): string
    {
        return match (true) {
            $percent >= 90 => 'is-danger',
            $percent >= 75 => 'is-warn',
            default => 'is-ok',
        };
    }

    /**
     * คลาสความยาวของแถบ .meter — CSP ห้าม style="width:NN%" ความยาวจึงต้อง
     * มาจากคลาสที่มีอยู่แล้วใน app.css (.pct-0 ถึง .pct-100)
     */
    public static function meterClass(float $percent): string
    {
        return 'pct-'.(int) round(max(0.0, min(100.0, $percent)));
    }

    /** สถานะบริการ → ข้อความไทยตามที่ PROMPT.md กำหนด */
    public static function serviceStatus(string $status): string
    {
        return match ($status) {
            'running' => 'ทำงานปกติ',
            'stopped' => 'หยุดทำงาน',
            'failed' => 'มีปัญหา',
            'transitioning' => 'กำลังเปลี่ยนสถานะ',
            'not_installed' => 'ยังไม่ได้ติดตั้ง',
            default => 'ไม่ทราบสถานะ',
        };
    }

    /** สถานะบริการ → คลาส badge */
    public static function serviceTone(string $status): string
    {
        return match ($status) {
            'running' => 'ok',
            'failed' => 'danger',
            'stopped' => 'muted',
            'transitioning' => 'info',
            'not_installed' => 'warn',
            default => 'muted',
        };
    }

    /** ชื่อการกระทำใน audit log → ข้อความไทย */
    public static function action(string $action): string
    {
        return match ($action) {
            'auth.login' => 'เข้าสู่ระบบ',
            'auth.logout' => 'ออกจากระบบ',
            'auth.2fa' => 'ยืนยันตัวตนสองชั้น',
            'auth.password_changed' => 'เปลี่ยนรหัสผ่าน',
            'http.forbidden' => 'พยายามเข้าถึงส่วนที่ไม่มีสิทธิ์',
            'service.restart' => 'รีสตาร์ตบริการ',
            'service.start' => 'เริ่มบริการ',
            'service.stop' => 'หยุดบริการ',
            'service.reload' => 'โหลดค่าตั้งบริการใหม่',
            'site.create' => 'สร้างเว็บไซต์',
            'site.delete' => 'ลบเว็บไซต์',
            'system.setup' => 'ติดตั้งระบบ',
            default => $action,
        };
    }

    /**
     * @param string $result
     */
    public static function auditTone(string $result): string
    {
        return match ($result) {
            'ok' => 'ok',
            'denied' => 'warn',
            'error' => 'danger',
            default => 'muted',
        };
    }

    /** อักษรย่อสำหรับ avatar */
    public static function initials(string $name): string
    {
        $name = trim($name);
        if ($name === '') {
            return '?';
        }

        return mb_strtoupper(mb_substr($name, 0, 1));
    }
}
