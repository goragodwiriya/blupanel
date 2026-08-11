<?php

declare(strict_types=1);

namespace Phpcp\Security;

/**
 * CSRF token ที่ผูกกับ session และผูกกับชื่อฟอร์ม
 *
 * เป็นแบบ stateless: token = HMAC(session, ชื่อฟอร์ม) จึงไม่ต้องเก็บอะไรเพิ่มในฐานข้อมูล
 * และไม่มีปัญหาเปิดหลายแท็บแล้ว token ตัวเก่าใช้ไม่ได้
 *
 * การผูกกับชื่อฟอร์มทำให้ token ของฟอร์ม "แก้ไขเว็บไซต์" เอาไปใช้กับ "ลบเว็บไซต์" ไม่ได้
 * ป้องกันกรณีที่ token รั่วจากหน้าที่มีความเสี่ยงต่ำไปใช้กับงานที่อันตราย
 */
final class Csrf
{
    public const FIELD = '_token';
    public const HEADER = 'X-CSRF-Token';

    /**
     * ชื่อผูกที่ทั้งระบบใช้ร่วมกัน
     *
     * อยู่ที่นี่เพราะมีสองที่ที่ต้องสร้าง token ให้ตรงกันเป๊ะ: middleware ที่ออก token
     * และ controller ล็อกอินที่ต้องออก token ใหม่ทันทีหลัง session id เปลี่ยน
     * ถ้าค่านี้ต่างกันแม้ตัวอักษรเดียว ทุกฟอร์มจะ 419 โดยไม่มีอะไรบอกว่าเพราะอะไร
     */
    public const SCOPE = 'session';

    public function __construct(private readonly string $secretKey)
    {
    }

    public function token(string $sessionId, string $form = 'default'): string
    {
        return hash_hmac('sha256', $sessionId . '|' . $form, $this->secretKey);
    }

    public function verify(string $sessionId, string $token, string $form = 'default'): bool
    {
        if ($token === '') {
            return false;
        }

        return hash_equals($this->token($sessionId, $form), $token);
    }
}
