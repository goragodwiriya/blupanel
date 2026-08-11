<?php

declare(strict_types=1);

namespace Phpcp\Driver;

/**
 * ข้อความหลายบรรทัดที่ผ่านการตรวจทีละบรรทัดมาแล้ว
 *
 * มีไว้เพื่อให้ Template แยกออกได้ว่า "ค่าหลายบรรทัดนี้ตั้งใจให้เป็นหลายบรรทัด"
 * ต่างจากค่าธรรมดาที่ถ้ามีขึ้นบรรทัดใหม่แปลว่ามีคนพยายามแทรก directive เข้ามา
 *
 * สร้างได้ทางเดียวคือผ่าน Template::lines() ซึ่งตรวจทุกบรรทัดก่อนเสมอ
 */
final readonly class SafeBlock
{
    public function __construct(public string $text)
    {
    }

    public function __toString(): string
    {
        return $this->text;
    }
}
