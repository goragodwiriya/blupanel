<?php

declare(strict_types=1);

namespace Phpcp\Driver\WebServer;

/**
 * เว็บ http://localhost ของเครื่องพัฒนา — ไม่ใช่เว็บไซต์ในระบบ
 *
 * **ทำไมไม่เป็นเว็บไซต์ปกติในฐานข้อมูล:** ชื่อ `localhost` ไม่มีจุด จึงผ่านตัวตรวจ
 * ชื่อโดเมนไม่ได้ (และไม่ควรผ่าน — ชื่อไม่มีจุดกลายเป็นชื่อไฟล์ vhost ที่ชนกับ
 * ของระบบได้) · และเว็บในระบบต้องมีเจ้าของ มีโควตา มี FPM pool ของตัวเอง ซึ่ง
 * แปลว่าโฟลเดอร์งานของนักพัฒนาจะถูก `chown -R` ไปเป็นของบัญชีลูกค้า
 *
 * ที่นี่จึงเป็นแค่ "ไฟล์ตั้งค่าระดับเครื่องอีกไฟล์หนึ่ง" ของโหมดที่ใช้อยู่ — เขียนใหม่
 * ทุกครั้งที่สร้างไฟล์ตั้งค่า จึงอยู่รอดทั้งการติดตั้งซ้ำและการสลับโหมด ซึ่งเป็นสอง
 * เหตุการณ์ที่ทำให้ vhost ที่เขียนด้วยมือหายไปเงียบ ๆ
 */
final class LocalhostSite
{
    public function __construct(
        /** โฟลเดอร์ที่เสิร์ฟ — มาจาก `sites.localhost_docroot` */
        public readonly string $docroot,
        /** เวอร์ชัน PHP ที่ใช้เลือก FPM pool มาตรฐานของดิสโทร */
        public readonly string $phpVersion,
    ) {
    }

    /**
     * pool มาตรฐานของดิสโทร (www-data) ไม่ใช่ pool ของบัญชีลูกค้า
     *
     * โฟลเดอร์พัฒนาไม่ใช่ของลูกค้าคนไหน การยืม pool ของใครมาใช้แปลว่าโฟลเดอร์นั้น
     * ต้องอยู่ใน `open_basedir` ของบัญชีนั้น และไฟล์ที่ PHP สร้างจะเป็นของบัญชีนั้น
     */
    public function fpmSocket(): string
    {
        return '/run/php/php' . $this->phpVersion . '-fpm.sock';
    }

    public function errorLog(): string
    {
        return '/var/log/phpcp/localhost-error.log';
    }

    public function accessLog(): string
    {
        return '/var/log/phpcp/localhost-access.log';
    }
}
