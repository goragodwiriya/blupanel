<?php

declare(strict_types=1);

namespace Phpcp\Http\Resource;

/**
 * โดเมนหนึ่งรายการที่ผูกกับเว็บไซต์
 *
 * `type` บอกว่าลบได้หรือไม่: `primary` ลบเดี่ยว ๆ ไม่ได้ (ต้องลบทั้งเว็บไซต์)
 * ส่วน `subdomain`/`alias`/`redirect` ลบได้ — ส่ง `removable` มาให้ตรง ๆ
 * เพื่อให้หน้าจอไม่ต้องรู้กฎนี้เองแล้วเผลอแสดงปุ่มลบที่กดแล้วได้ error
 */
final class DomainResource extends Resource
{
    public static function one(array $row): array
    {
        $type = self::string($row['type'] ?? '');

        $id = (int) ($row['id'] ?? 0);

        $domain = [
            'id' => $id,
            // ซ้ำกับ id โดยตั้งใจ — ใช้ในหน้าที่ URL ของหน้าเองมี ?id= อยู่แล้ว (เช่น
            // site.html คือ /site?id={เว็บไซต์}) การใส่ {id} ใน data-row-actions ตรงนั้น
            // จะถูก RouterManager.render (js/ui.js) แทนค่าด้วย id ของ "หน้า" ก่อนที่
            // TableManager จะได้เห็นเทมเพลตด้วยซ้ำ — ปุ่มทุกแถวจึงพาไปที่ id เดียวกันหมด
            // แทนที่จะเป็น id ของแถวนั้นเอง ชื่อที่ไม่ชนกับพารามิเตอร์ของหน้าไหนเลยแก้ที่ต้นตอ
            'row_id' => $id,
            'site_id' => (int) ($row['site_id'] ?? 0),
            'domain' => self::string($row['domain'] ?? ''),
            'type' => $type,
            'removable' => in_array($type, ['subdomain', 'alias', 'redirect'], true),
            'redirect_target' => self::string($row['redirect_target'] ?? ''),
            'redirect_code' => self::intOrNull($row['redirect_code'] ?? null),
            'created_at' => self::intOrNull($row['created_at'] ?? null),
        ];

        // มาจาก JOIN ตอนดึงรายการโดเมนทั้งระบบ — ไม่มีตอนดึงเฉพาะของเว็บเดียว
        foreach (['site_name' => 'site_name', 'primary_domain' => 'site_domain', 'site_status' => 'site_status'] as $column => $key) {
            if (array_key_exists($column, $row)) {
                $domain[$key] = self::string($row[$column]);
            }
        }

        return $domain;
    }
}
