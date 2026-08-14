<?php

declare(strict_types=1);

namespace Phpcp\Http\Resource;

/**
 * ไฟล์สำรองหนึ่งไฟล์ — **อ่านมาจากโฟลเดอร์จริง ไม่ใช่จากแถวในตาราง**
 *
 * ตั้งแต่ PLAN-BACKUP-V2 ข้อ B4 ตัวไฟล์คือแหล่งความจริง · สิ่งที่ทรัพยากรนี้ส่งออกไป
 * จึงเป็นสิ่งที่ `stat()` ตอบได้เท่านั้น ไม่มีสถานะที่ระบบเคยจดไว้เมื่อวานปนอยู่เลย
 *
 * `bytes` เป็นไบต์ดิบ ไม่ใช่ "1.2 GB" — หน้าจอจัดรูปแบบเอง และการเรียงตามขนาดต้องทำ
 * จากตัวเลขเท่านั้น
 *
 * **ไม่มี `checksum` และ `offsite_status` อีกแล้ว** · ทั้งคู่เคยมาจากแถวในตาราง ·
 * checksum ที่บันทึกไว้ตอนสร้างพิสูจน์อะไรไม่ได้เมื่อไฟล์อยู่ในมือลูกค้า — ค่าที่มี
 * ความหมายคือค่าที่คำนวณจากไฟล์เดี๋ยวนั้น ซึ่งการกู้คืนกับการส่งออกทำเองอยู่แล้วทุกครั้ง
 *
 * `restorable` คือคำตอบของคำถามที่หน้าจอต้องใช้จริง: ปุ่มกู้คืนกดได้ไหม · ไฟล์ที่จับคู่
 * กับเว็บไม่ได้ (ลูกค้าคัดลอกมาเอง หรือมาจากเว็บที่ลบไปแล้ว) ยังแสดงในรายการเพราะมัน
 * กินโควตาของเขาจริง แต่กู้คืนอัตโนมัติไม่ได้
 */
final class BackupResource extends Resource
{
    public static function one(array $row): array
    {
        $type = self::string($row['type'] ?? '');

        return [
            // ชื่อไฟล์คือกุญแจของทรัพยากรนี้ — คู่กับ `user_id` แล้วอ้างถึงไฟล์ได้ตรงตัว
            'name' => self::string($row['name'] ?? ''),
            'path' => self::string($row['path'] ?? ''),
            'type' => $type,
            'type_label' => $type === 'database' ? 'ฐานข้อมูล' : 'ไฟล์เว็บไซต์',
            'domain' => self::string($row['domain'] ?? ''),
            'site_id' => (int) ($row['site_id'] ?? 0),
            'user_id' => (int) ($row['user_id'] ?? 0),
            'username' => self::string($row['username'] ?? ''),
            'size_bytes' => (int) ($row['bytes'] ?? 0),
            'modified_at' => (int) ($row['modified_at'] ?? 0),
            'restorable' => (bool) ($row['restorable'] ?? false),
            // สีของป้ายมาจากฝั่งเซิร์ฟเวอร์ เทมเพลตจึงเขียน `pill-${type_tone}` ได้ตรง ๆ
            'type_tone' => $type === 'database' ? 'info' : 'ok',
        ];
    }
}
