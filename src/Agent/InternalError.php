<?php

declare(strict_types=1);

namespace Phpcp\Agent;

/**
 * capability ตายกลางทางด้วยข้อผิดพลาดที่ไม่ได้คาดไว้ — agent ยังทำงานปกติ
 *
 * **ต้องแยกจาก `TransportError` ให้ขาด** ทั้งที่ผู้ใช้เห็นข้อความคล้ายกัน:
 *
 *   TransportError = ติดต่อ agent ไม่ได้ · ชั่วคราว · **ลองใหม่แล้วหายได้** · 503
 *   InternalError  = agent รับคำสั่งแล้วโค้ดข้างในพัง · **ลองใหม่กี่ครั้งก็เหมือนเดิม** · 500
 *
 * เดิมรหัส `internal_error` ตกไปที่ `default` ของ `Client::exceptionFor()` ซึ่งคือ
 * `TransportError` · หน้าเว็บจึงขึ้น AGENT_UNAVAILABLE ตอนที่ SQL ในนั้นอ้างคอลัมน์ที่
 * migration ลบไปแล้ว — ผู้ดูแลไปไล่ socket กับ `systemctl restart phpcp-agentd`
 * ทั้งที่ agent ไม่เคยมีปัญหา และคำตอบที่แท้จริงรออยู่ใน log ของ agent บรรทัดเดียว
 */
final class InternalError extends AgentException
{
    public function code(): string
    {
        return 'internal_error';
    }
}
