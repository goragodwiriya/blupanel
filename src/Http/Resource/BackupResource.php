<?php

declare(strict_types=1);

namespace Phpcp\Http\Resource;

/**
 * ไฟล์สำรองหนึ่งไฟล์
 *
 * `size_bytes` เป็นไบต์ดิบ ไม่ใช่ "1.2 GB" — หน้าจอจัดรูปแบบเอง และการเรียงตามขนาด
 * ต้องทำจากตัวเลขเท่านั้น
 *
 * `checksum` ส่งมาด้วยเพราะเป็นสิ่งที่พิสูจน์ว่าไฟล์ยังไม่ถูกแก้ — การกู้คืนตรวจค่านี้
 * ทุกครั้งก่อนแตะข้อมูลจริง (ไฟล์สำรองที่ไม่มี checksum กู้ไม่ได้เลยโดยเจตนา)
 * ผู้ดูแลจึงควรเห็นได้ว่าไฟล์ไหนมีค่านี้
 *
 * `offsite_status` คือคำตอบของคำถามที่สำคัญที่สุดในหน้านี้: **ไฟล์นี้มีสำเนาอยู่นอก
 * ดิสก์ก้อนนี้แล้วหรือยัง** · แยกสามค่าโดยตั้งใจ — `none` ยังไม่เคยส่ง · `failed`
 * ส่งแล้วล้มเหลว (ต้องเห็นเป็นสีเตือน) · `ok` มีสำเนาแล้ว
 */
final class BackupResource extends Resource
{
    public static function one(array $row): array
    {
        $status = self::string($row['status'] ?? '');
        $offsiteStatus = self::string($row['offsite_status'] ?? 'none');

        $backup = [
            'id' => (int) ($row['id'] ?? 0),
            'name' => self::string($row['name'] ?? ''),
            'type' => self::string($row['type'] ?? ''),
            'site_id' => self::intOrNull($row['site_id'] ?? null),
            'path' => self::string($row['path'] ?? ''),
            'size_bytes' => (int) ($row['size_bytes'] ?? 0),
            'checksum' => self::string($row['checksum'] ?? ''),
            'status' => $status,
            // สีของป้ายมาจากฝั่งเซิร์ฟเวอร์ เทมเพลตจึงเขียน `pill-${..._tone}` ได้ตรง ๆ
            'status_tone' => match ($status) {
                'ok' => 'ok',
                'failed' => 'danger',
                'running' => 'warn',
                default => 'muted',
            },
            'created_at' => self::intOrNull($row['created_at'] ?? null),
            'destination_id' => self::intOrNull($row['destination_id'] ?? null),
            'offsite_status' => $offsiteStatus,
            'offsite_tone' => match ($offsiteStatus) {
                'ok' => 'ok',
                'failed' => 'danger',
                default => 'muted',
            },
            'offsite_at' => self::intOrNull($row['offsite_at'] ?? null),
            'offsite_error' => self::string($row['offsite_error'] ?? ''),
        ];

        if (array_key_exists('destination_name', $row)) {
            $backup['destination_name'] = self::string($row['destination_name'] ?? '');
        }

        if (array_key_exists('primary_domain', $row)) {
            $backup['site_domain'] = self::string($row['primary_domain'] ?? '');
        }

        return $backup;
    }
}
