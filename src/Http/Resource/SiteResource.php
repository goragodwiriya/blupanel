<?php

declare(strict_types=1);

namespace Phpcp\Http\Resource;

use Phpcp\Domain\Site;

/**
 * เว็บไซต์หนึ่งเว็บ
 *
 * ค่าที่เป็นเส้นทางไฟล์ (`root`, `docroot`) คำนวณจาก `Site` ไม่ใช่จากคอลัมน์ในฐานข้อมูล
 * เพราะ `Site` คือแหล่งความจริงเดียวของการอนุมานเส้นทาง — ถ้าอ่านคอลัมน์ `docroot` ตรง ๆ
 * ค่าที่ API คืนอาจไม่ตรงกับที่ vhost ใช้จริงเมื่อมีการเปลี่ยน `sites.dir`
 */
final class SiteResource extends Resource
{
    public static function one(array $row): array
    {
        $site = self::toSite($row);

        return [
            'id' => (int) $row['id'],
            'domain' => self::string($row['primary_domain'] ?? ''),
            'php_version' => self::string($row['php_version'] ?? ''),
            'ssl_mode' => self::string($row['ssl_mode'] ?? 'off'),
            'status' => self::string($row['status'] ?? 'active'),
            // สีของป้ายสถานะมาจากฝั่งเซิร์ฟเวอร์ เพื่อให้เทมเพลตเขียน `pill-${status_tone}`
            // ได้ตรง ๆ โดยไม่ต้องมีตารางแปลงฝั่ง JS — แบบเดียวกับ UserResource::service_tone
            'status_tone' => match (self::string($row['status'] ?? 'active')) {
                'active' => 'ok',
                'suspended' => 'warn',
                default => 'muted',
            },
            // บัญชี Linux ที่เว็บนี้รันด้วยเป็นของ**เจ้าของ** ไม่ใช่ของเว็บ ตั้งแต่ migration 0006
            // (คอลัมน์ sites.system_user/uid/gid ถูกลบไปแล้ว — ย้ายไปอยู่กับผู้ใช้)
            'system_user' => $site?->systemUser() ?? self::string($row['owner_system_user'] ?? ''),
            'root' => $site?->root(),
            'docroot' => $site?->docroot() ?? self::string($row['docroot'] ?? ''),
            'docroot_override' => self::string($row['docroot_override'] ?? ''),
            'owner_user_id' => self::intOrNull($row['owner_user_id'] ?? null),
            // **ไบต์คือหน่วยเดียวของ API** ถึงแม้ฐานข้อมูลจะเก็บเป็น MB ก็ตาม
            //
            // ยังเป็นตัวเลขดิบ ไม่ใช่ข้อความที่จัดรูปแบบแล้ว — แต่เป็นหน่วยที่ตัวจัดรูปแบบ
            // มาตรฐานของเฟรมเวิร์ก (`data-format="bytes"`) กินได้ทันที · ถ้าคืนเป็น MB
            // หน้าจอจะต้องมีฟังก์ชัน JS เฉพาะโปรเจกต์ไว้คูณ 1024² ทุกที่ที่แสดงค่านี้
            'disk_used' => ((int) ($row['disk_used_mb'] ?? 0)) * 1048576,
            'disk_quota' => isset($row['disk_quota_mb']) && $row['disk_quota_mb'] !== null
                ? ((int) $row['disk_quota_mb']) * 1048576
                : null,
            'created_at' => self::intOrNull($row['created_at'] ?? null),
            'updated_at' => self::intOrNull($row['updated_at'] ?? null),
        ] + self::counts($row);
    }

    /**
     * ตัวเลขที่มาจาก listWithCounts() — มีเฉพาะตอนดึงเป็นรายการ
     *
     * ไม่ใส่คีย์เหล่านี้เลยเมื่อไม่มีข้อมูล ดีกว่าใส่ 0 ปลอม ๆ ที่หน้าจอแยกไม่ออกว่า
     * "ไม่มีโดเมนเลย" หรือ "ไม่ได้ถามมา"
     *
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private static function counts(array $row): array
    {
        $counts = [];

        foreach (['domain_count', 'database_count', 'cron_count'] as $key) {
            if (array_key_exists($key, $row)) {
                $counts[$key] = (int) $row[$key];
            }
        }

        if (array_key_exists('cert_status', $row)) {
            $counts['certificate'] = [
                'status' => self::string($row['cert_status'] ?? ''),
                'expires_at' => self::intOrNull($row['cert_expires'] ?? null),
            ];
        }

        return $counts;
    }

    /**
     * @param array<string,mixed> $row
     */
    private static function toSite(array $row): ?Site
    {
        // แถวที่ยังสร้างไม่เสร็จ (system_user ว่าง) ทำให้ Site โยน error — คืน null แทน
        // เพื่อให้ API ยังรายงานเว็บนั้นได้ว่ามีอยู่ในสถานะที่ยังไม่สมบูรณ์
        try {
            return Site::fromRow($row);
        } catch (\Throwable) {
            return null;
        }
    }
}
