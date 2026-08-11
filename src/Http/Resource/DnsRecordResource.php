<?php

declare(strict_types=1);

namespace Phpcp\Http\Resource;

/**
 * DNS record หนึ่งแถว
 *
 * `priority` เป็น null สำหรับทุกชนิดยกเว้น MX — คืน null จริง ๆ ไม่ใช่ 0
 * เพราะ "ไม่มีลำดับความสำคัญ" กับ "ลำดับความสำคัญเท่ากับ 0" เป็นคนละความหมายใน DNS
 */
final class DnsRecordResource extends Resource
{
    public static function one(array $row): array
    {
        $id = (int) ($row['id'] ?? 0);

        return [
            'id' => $id,
            // ซ้ำกับ id โดยตั้งใจ — domain.html เองมี ?id= (id ของโดเมน) ใน URL
            // การใส่ {id} ใน data-row-actions ของตารางนี้จะถูก RouterManager.render
            // (js/ui.js) แทนค่าด้วย id ของโดเมนก่อนที่ TableManager จะเห็นเทมเพลตด้วยซ้ำ —
            // ปุ่มลบทุกแถวจะยิงไปที่เรกคอร์ดเดียวกันหมด ชื่อที่ไม่ชนกับพารามิเตอร์ของ
            // หน้าไหนเลยแก้ที่ต้นตอ (ดู DomainResource::row_id ปัญหาเดียวกัน)
            'row_id' => $id,
            'domain_id' => (int) ($row['domain_id'] ?? 0),
            'type' => self::string($row['type'] ?? ''),
            'name' => self::string($row['name'] ?? ''),
            'value' => self::string($row['value'] ?? ''),
            'ttl' => (int) ($row['ttl'] ?? 3600),
            'priority' => self::intOrNull($row['priority'] ?? null),
        ];
    }
}
