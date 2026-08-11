<?php

declare(strict_types=1);

namespace Phpcp\Driver\WebServer;

use Phpcp\Agent\Executor\Executor;
use Phpcp\Domain\Site;

/**
 * ชั้นนามธรรมของเว็บเซิร์ฟเวอร์ — ARCHITECTURE §10
 *
 * v1 ส่ง ApacheDriver (ตรงกับเครื่องเป้าหมาย) ส่วน NginxDriver อยู่ในเฟส 5
 * ทั้งสองใช้ PHP-FPM ผ่าน unix socket เหมือนกัน ตรรกะการสลับเวอร์ชัน PHP
 * จึงใช้ร่วมกันได้ทั้งหมดโดยไม่ต้องเขียนซ้ำ
 */
interface WebServerDriver
{
    public function name(): string;

    /** ชื่อ systemd unit ของเว็บเซิร์ฟเวอร์นี้ */
    public function unit(): string;

    /** ผู้ใช้ระบบที่เว็บเซิร์ฟเวอร์รันอยู่ — ใช้ตั้งเจ้าของ socket ของ FPM */
    public function runAsUser(): string;

    /**
     * กลุ่มของกระบวนการเว็บเซิร์ฟเวอร์
     *
     * ไฟล์ของเว็บไซต์ถูกตั้งเป็น <ผู้ใช้เว็บไซต์>:<กลุ่มนี้> เพื่อให้เว็บเซิร์ฟเวอร์
     * อ่านและเดินผ่านไดเรกทอรีได้ ขณะที่ผู้ใช้ของเว็บไซต์อื่นยังเข้าไม่ได้
     */
    public function runAsGroup(): string;

    /**
     * สร้างเนื้อหาไฟล์ vhost จากเทมเพลต ไม่เขียนลงดิสก์
     *
     * ต้องรับ Executor เพราะ "เส้นทางที่อยู่ข้างในไฟล์ config" ก็ต้องถูกแมปตามโหมด
     * เหมือนกับเส้นทางของตัวไฟล์เอง ไม่อย่างนั้นในโหมด sandbox จะได้ vhost ที่ชี้ไปยัง
     * /srv/phpcp/... ของจริงซึ่งไม่มีอยู่ แล้ว configtest จะล้มทั้งที่ไฟล์ถูกต้อง
     */
    public function renderVhost(Site $site, Executor $executor): string;

    /** เส้นทางไฟล์ vhost ของเว็บไซต์นี้ (เส้นทางแบบระบบจริง ยังไม่ผ่านการแมป) */
    public function vhostPath(Site $site): string;

    /**
     * ตรวจความถูกต้องของ config ทั้งหมด
     *
     * @return array{0:bool,1:string} [ผ่านหรือไม่, ข้อความจากเครื่องมือตรวจ]
     */
    public function testConfig(Executor $executor): array;

    /** สั่งให้เว็บเซิร์ฟเวอร์โหลด config ใหม่โดยไม่ตัดการเชื่อมต่อที่ค้างอยู่ */
    public function reload(Executor $executor): void;

    public function isInstalled(Executor $executor): bool;

    /**
     * เปิดโมดูล/ส่วนขยายที่ไฟล์ config ซึ่งระบบสร้างขึ้นจำเป็นต้องใช้
     *
     * อยู่ใน interface เพราะเว็บเซิร์ฟเวอร์แต่ละตัวมีข้อกำหนดต่างกัน —
     * nginx ไม่มีระบบโมดูลแบบเปิด/ปิดตอนรัน จึงคืน [] ไปได้เลย
     *
     * @return list<string> ชื่อโมดูลที่เพิ่งถูกเปิดในรอบนี้
     */
    public function ensureModules(Executor $executor, bool $withSsl = false): array;
}
