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

    /**
     * เส้นทางไฟล์ vhost หลักของเว็บไซต์นี้ (เส้นทางแบบระบบจริง ยังไม่ผ่านการแมป)
     *
     * "หลัก" = ไฟล์ของเซิร์ฟเวอร์ที่รับคำขอจากอินเทอร์เน็ตจริง — ใช้แสดงให้ผู้ดูแลเห็น
     * ว่าเว็บนี้ถูกตั้งค่าไว้ที่ไหน · โหมดที่มีหลายชั้นมีไฟล์มากกว่านี้ ดู vhostFiles()
     */
    public function vhostPath(Site $site): string;

    /**
     * ไฟล์ตั้งค่าทั้งหมดของเว็บไซต์นี้ — เส้นทาง => เนื้อหา
     *
     * มีเมธอดนี้เพราะโหมด nginx-proxy เขียน **สองไฟล์ต่อหนึ่งเว็บ** (nginx ชั้นหน้า
     * กับ Apache ชั้นหลัง) การเขียน/ลบต้องทำครบทั้งคู่ในทรานแซกชันเดียวกันเสมอ
     * ไม่งั้นจะเหลือ vhost กำพร้าที่ชี้ไปยัง backend ที่ไม่มีอยู่ แล้วทุกคำขอได้ 502
     *
     * ไดรเวอร์ชั้นเดียวคืนรายการเดียวคือ [vhostPath() => renderVhost()]
     *
     * @return array<string,string>
     */
    public function vhostFiles(Site $site, Executor $executor): array;

    /**
     * ไฟล์ระดับเครื่องของโหมดนี้ — ไม่ใช่ของเว็บใดเว็บหนึ่ง
     *
     * เขียนใหม่ทุกครั้งที่สร้างไฟล์ตั้งค่า เพราะแต่ละโหมดเขียนทับไฟล์ของอีกโหมด
     * (เห็นชัดที่สุดคือ `ports.conf` ของ Apache ที่โหมด nginx-proxy ย้ายไป 8080)
     * ถ้าโหมดใดไม่เขียนคืนของตัวเอง การสลับกลับมาจะได้เครื่องที่ค้างครึ่งทาง
     *
     * แยกจาก vhostPaths() โดยตั้งใจ — ลบเว็บหนึ่งเว็บต้องไม่ลบไฟล์ที่ทั้งเครื่องใช้ร่วมกัน
     *
     * @return array<string,string> เส้นทาง => เนื้อหา
     */
    public function globalFiles(Executor $executor): array;

    /**
     * เส้นทางไฟล์ทั้งหมดของเว็บไซต์นี้ — ใช้ตอนลบ
     *
     * แยกจาก vhostFiles() เพราะการลบไม่ควรต้องเรนเดอร์เนื้อหาก่อน · เว็บที่กำลังจะถูกลบ
     * อาจเรนเดอร์ไม่ผ่านอยู่แล้ว (เช่นใบรับรองถูกลบไปก่อน) แล้วจะกลายเป็นลบไม่ได้เลย
     *
     * @return list<string>
     */
    public function vhostPaths(Site $site): array;

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
