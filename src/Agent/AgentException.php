<?php

declare(strict_types=1);

namespace Phpcp\Agent;

/**
 * รากของข้อผิดพลาดทั้งหมดในชั้น agent
 *
 * ทุกตัวมี code สั้น ๆ ที่ส่งข้ามโปรโตคอลได้ ฝั่งเว็บเอาไปแปลงกลับเป็น exception ชนิดเดิม
 * และเอาไปเลือกวิธีแสดงผลได้ ข้อความเป็นภาษาไทยเสมอเพราะถูกแสดงบนหน้าจอโดยตรง
 */
abstract class AgentException extends \RuntimeException
{
    abstract public function code(): string;

    /** ควรบันทึกลง audit log เป็นผลลัพธ์อะไร */
    public function auditResult(): string
    {
        return 'error';
    }
}
