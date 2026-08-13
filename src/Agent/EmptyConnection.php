<?php

declare(strict_types=1);

namespace Phpcp\Agent;

/**
 * เชื่อมต่อเข้ามาแล้วปิดโดยไม่ส่งอะไรเลย — คือการตรวจว่า agent ยังรับงานได้ไหม
 *
 * แยกจาก {@see TransportError} เพราะสองอย่างนี้ต้องปฏิบัติต่างกันโดยสิ้นเชิง:
 * transport error คือคำสั่งที่ส่งมาแล้วพัง ต้องบันทึกไว้ให้ผู้ดูแลเห็น ส่วนอันนี้คือ
 * การถาม "ยังอยู่ไหม" ที่สำเร็จตามที่ตั้งใจทุกครั้ง — บันทึกมันคือการสร้างเสียงรบกวน
 * นาทีละบรรทัดที่กลบความล้มเหลวจริงจนหาไม่เจอ
 *
 * ไม่ต้องมี `code()` แบบพิเศษเพราะไม่เคยถูกส่งกลับไปให้ผู้เรียก — ปลายทางปิดไปแล้ว
 */
final class EmptyConnection extends AgentException
{
    public function __construct()
    {
        parent::__construct('ปลายทางปิดการเชื่อมต่อก่อนส่งคำสั่ง');
    }

    public function code(): string
    {
        return 'empty_connection';
    }
}
