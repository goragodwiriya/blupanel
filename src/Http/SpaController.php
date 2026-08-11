<?php

declare(strict_types=1);

namespace Phpcp\Http;

use Phpcp\Controller\Controller;
use Phpcp\Kernel\Request;
use Phpcp\Kernel\Response;

/**
 * ตัวส่ง shell ของ SPA — PLAN-V2 §3.1, เฟส C1
 *
 * ทำอย่างเดียวคือ **ส่งไฟล์ `public/assets/spa/index.html` ตามที่มันเป็น** ไม่ประกอบ HTML
 * ไม่แทนค่าใด ๆ ลงในนั้น · เนื้อหาของหน้าทั้งหมดมาจาก REST API v2 ฝั่งเบราว์เซอร์
 * จึงไม่ขัดกับกฎ "ห้ามสร้าง HTML จากฝั่ง PHP" ที่เฟส D จะบังคับ
 *
 * **ทำไมต้องผ่าน PHP ทั้งที่เป็นไฟล์นิ่ง:** router ของ SPA ใช้โหมด history เส้นทางอย่าง
 * `/app/sites` จึงไม่มีไฟล์อยู่จริงบนดิสก์ · ถ้าปล่อยให้ Apache จัดการเอง การกดรีเฟรช
 * หรือเปิดลิงก์ตรงจะได้ 404 ทันที · ผลพลอยได้อีกข้อคือ shell ได้ header ความปลอดภัย
 * ชุดเดียวกับทุกคำตอบของ panel (CSP, HSTS, X-Frame-Options) จาก `SecurityHeaders`
 * ซึ่งไฟล์ที่ Apache ส่งเองจะไม่ได้
 *
 * **ทำไมไฟล์จริงถึงอยู่ที่ `public/assets/spa/` ไม่ใช่ `public/app/`:** `FallbackResource`
 * ของ Apache **ข้าม URL ที่ชี้ไปยังไฟล์หรือไดเรกทอรีที่มีอยู่จริง** ถ้ามีไดเรกทอรี
 * `public/app/` อยู่ `mod_dir` จะเข้ามาก่อน — ตอบ 301 จาก `/app` ไป `/app/` แล้วมองหา
 * DirectoryIndex ในนั้น ไม่เจอ จึงจบที่ 404 ของ Apache เอง **โดยที่ PHP ไม่เคยเห็นคำขอนั้น**
 * · แยกที่เก็บไฟล์ออกจาก URL ของหน้าจอจึงเป็นทางแก้ที่ไม่ต้องแตะ config ของ Apache เลย
 *
 * เป็นเส้นทางสาธารณะโดยตั้งใจ — shell ไม่มีข้อมูลของผู้ใช้อยู่เลยแม้แต่ชื่อผู้ใช้
 * การตัดสินว่าจะแสดงหน้าล็อกอินหรือหน้าหลักเกิดที่ `GET /api/v2/session` ซึ่งเป็น
 * เส้นทางที่ยังบังคับสิทธิ์เต็มรูปแบบเหมือนเดิม
 */
final class SpaController extends Controller
{
    public function shell(Request $request): Response
    {
        $file = $this->app->config->paths->spa().'/index.html';
        $html = is_file($file) ? file_get_contents($file) : false;

        if ($html === false) {
            // ตอบเป็นข้อความล้วน ไม่ใช่หน้า HTML สวย ๆ — ถ้าไฟล์ shell หายไป แปลว่า
            // การติดตั้งไม่ครบ และการซ่อนด้วยหน้าที่ดูปกติจะทำให้หาสาเหตุยากขึ้น
            return Response::text('ไม่พบไฟล์หน้าเว็บของ panel — ตรวจด้วย `phpcp doctor`', 500);
        }

        return Response::html($html);
    }

    /**
     * รากโดเมน → แอป
     *
     * เด้งแทนที่จะส่ง shell ตรง ๆ เพื่อให้ทั้งระบบมี URL เดียวต่อหนึ่งหน้าจอ · ถ้าตอบ
     * shell ที่ `/` ด้วย หน้าแดชบอร์ดจะมีสองที่อยู่ที่ใช้ได้จริง แล้ว router ของ SPA
     * (base = `/app`) จะเขียน URL ทับเป็น `/app/` ทันทีที่เริ่มทำงานอยู่ดี
     */
    public function root(Request $request): Response
    {
        return Response::redirect('/app/');
    }
}
