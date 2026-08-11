<?php

declare(strict_types=1);

namespace Phpcp\Domain;

use Phpcp\Agent\Client;
use Phpcp\Kernel\Db;

/**
 * เฝ้าดูว่า agent ยังตอบสนองอยู่ไหม — PLAN-V2 เฟส E6 (เพิ่มหลังทดสอบบนเครื่องจริง)
 *
 * **ทำไมต้องแยกออกมาจาก `alert.check`:** `alert.check` เป็น capability ที่รัน**ผ่าน agent**
 * พอ agent ตาย งานตรวจก็เรียกไม่ได้ — ระบบเฝ้าระวังตายพร้อมกับสิ่งที่มันเฝ้า และไม่มี
 * ข้อความใดออกไปเลย · ซ้ำร้าย `ServiceCatalog` ตัด unit ของ panel ออกด้วย `SelfProtection`
 * ทำให้ `alert.check` ไม่มีทางเห็น `phpcp-agentd` ตั้งแต่แรกอยู่แล้ว
 *
 * คลาสนี้จึงถูกเรียกจาก **scheduler** ซึ่งเป็นคนละโปรเซสและยังทำงานอยู่ตอน agent ล่ม
 *
 * **บันทึกสถานะอย่างเดียว ไม่ส่งข้อความ** — `phpcp-scheduler.service` ถูก hardening ไว้ด้วย
 * `RestrictAddressFamilies=AF_UNIX` (เปิด TCP ไม่ได้เลย จึงยิง Telegram/webhook ไม่ได้) และ
 * `NoNewPrivileges=yes` (ปิด setgid ของ `postdrop` จึงเข้าคิวเมลไม่ได้ — ยืนยันจาก log จริง:
 * `mail_queue_enter: Permission denied`) · ทั้งสองอย่างเป็นชั้นป้องกันที่ถูกต้องและห้ามผ่อน
 * เพียงเพื่อความสะดวกของการแจ้งเตือน (§7.1 ข้อ 2)
 *
 * หน้าที่ส่งข้อความจึงอยู่ที่ `bin/phpcp-alert` ซึ่ง systemd เรียกผ่าน `OnFailure=` ของ
 * `phpcp-agentd.service` แทน — รันเป็น root จึงส่งได้ทุกช่องทาง และรู้ทันทีที่ agent ตาย
 * โดยไม่ต้องรอรอบของ scheduler
 *
 * ที่ยังต้องมีคลาสนี้: `OnFailure=` ไม่ทำงานตอน `systemctl stop` (systemd ถือว่าเป็นการหยุด
 * ตั้งใจ) · สถานะที่บันทึกไว้ที่นี่คือสิ่งเดียวที่ทำให้หน้า `/api/v2/alerts` บอกได้ว่า
 * ตอนนี้ agent ไม่ทำงานอยู่ ไม่ว่าจะด้วยเหตุใด
 *
 * ผลที่ตามมาที่ต้องรู้: agent ที่ตายแปลว่า **ทุกอย่างหยุดหมด** ไม่ใช่แค่หน้าเว็บใช้ไม่ได้
 * — กลไกคืนค่าอัตโนมัติของ firewall/SSH ไม่มีใครกระตุ้น ผู้ดูแลที่กำลังแก้ firewall ค้างอยู่
 * จะถูกล็อกออกจากเครื่องถาวร · จึงจัดเป็น critical ตั้งแต่ครั้งแรกที่ตรวจพบ
 */
final class AgentHealth
{
    /** คีย์ของเกณฑ์นี้ใน `alert_state` — ใช้กลไกกันสแปมตัวเดียวกับเกณฑ์อื่น */
    public const ALERT_KEY = 'agent';

    public function __construct(
        private readonly Db $db,
        private readonly Client $client,
    ) {
    }

    /**
     * ตรวจหนึ่งครั้งแล้วบันทึกสถานะ
     *
     * **ไม่ส่งข้อความ** — ดูเหตุผลเต็มที่หัวคลาส (scheduler ส่งไม่ได้ทั้ง TCP และเมล)
     * · `AlertRules` ยังถูกเรียกเพื่อบันทึกสถานะและกันการนับซ้ำ ผลที่คืนบอกได้ว่า
     * รอบนี้ **ควร** แจ้งไหม เผื่อผู้เรียกที่มีสิทธิ์พอจะส่งเองได้ในอนาคต
     *
     * @return array{available:bool,changed:bool,reason:string}
     */
    public function check(?int $now = null): array
    {
        $available = $this->client->isAvailable();

        $decision = (new AlertRules($this->db))->evaluate(
            self::ALERT_KEY,
            $available ? null : 'critical',
            $available ? 1.0 : 0.0,
            $now,
        );

        return [
            'available' => $available,
            'changed' => $decision['notify'],
            'reason' => $decision['reason'],
        ];
    }
}
