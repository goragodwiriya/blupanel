<?php

declare(strict_types=1);

namespace Phpcp\Driver\WebServer;

use Phpcp\Agent\Executor\Executor;
use Phpcp\Agent\ValidationError;
use Phpcp\Driver\Template;
use Phpcp\Support\Validator;

/**
 * ไฟล์ตั้งค่าเพิ่มเติมที่ผู้ดูแลเขียนเอง — ของผู้ดูแลล้วน panel ไม่แตะ
 *
 * ## ทำไมถึงไม่ให้แก้ไฟล์ vhost ตรง ๆ
 *
 * ไฟล์ที่ผู้ดูแลอยากแก้ที่สุดคือไฟล์ที่ panel **เขียนทับทั้งไฟล์**ทุกครั้งที่มีคนแตะ
 * ข้อมูลที่เกี่ยวข้อง · การเปิดให้แก้ตรง ๆ คือการสร้างกับดัก: แก้แล้วใช้งานได้ทันที
 * ทุกอย่างดูถูกต้อง แล้ววันหนึ่งการเปลี่ยนแปลงนั้นหายไปเงียบ ๆ ตอนที่มีคนกดแก้อย่างอื่น
 * — อาการที่หาสาเหตุยากที่สุดชนิดหนึ่ง และเป็นบั๊กที่เจอซ้ำหลายรอบในโปรเจกต์นี้เอง
 *
 * ที่นี่จึงแยกเป็นไดเรกทอรีของผู้ดูแลโดยเฉพาะ ซึ่ง vhost ที่ generate อ่านเป็นอันสุดท้าย
 * ค่าที่เขียนที่นี่ชนะค่าเริ่มต้นเสมอ และไม่มีอะไรหายเมื่อ panel เขียน vhost ใหม่
 *
 * ## ขอบเขตอำนาจ
 *
 * เนื้อไฟล์เป็นค่าตั้งของเว็บเซิร์ฟเวอร์จริง ๆ จึงไม่มีการกรองคำสั่ง — ตัวกรองรายคำสั่ง
 * หลบเลี่ยงได้ง่ายและให้ความมั่นใจผิด ๆ · สิ่งที่จำกัดอำนาจจริงคือ **บริบท**: ไฟล์ถูก
 * include อยู่ใน `<VirtualHost>` / `server {}` คำสั่งระดับเครื่อง (User, Listen,
 * LoadModule, worker_processes) จึงถูกตัวตรวจของเว็บเซิร์ฟเวอร์ปฏิเสธเองตั้งแต่ก่อน
 * ถูกนำไปใช้ · ที่เหลือคือสิทธิ์ `settings.manage` ซึ่งเป็นผู้ดูแลเครื่องอยู่แล้ว
 *
 * **เส้นทางไฟล์ไม่เคยมาจากผู้ใช้** — ประกอบจากชื่อโดเมนที่ผ่าน `Validator::domain()`
 * แล้วเท่านั้น ผู้เรียกส่งได้แค่ "เนื้อไฟล์" ไม่ใช่ "จะเขียนที่ไหน"
 */
final class CustomConfig
{
    /** รากของไฟล์ที่ผู้ดูแลเขียนเอง — อยู่ใต้ /etc/phpcp เพราะเป็นของ panel ไม่ใช่ของดิสโทร */
    public const ROOT = '/etc/phpcp/custom';

    /**
     * ชื่อไฟล์เดียวที่หน้าจอแก้ได้
     *
     * ไดเรกทอรีถูก include ด้วย `*.conf` ผู้ดูแลจึงวางไฟล์อื่นเพิ่มเองทาง SSH ได้
     * โดย panel ไม่ยุ่ง — แต่หน้าจอแก้ได้ไฟล์เดียวเพื่อไม่ให้กลายเป็นตัวจัดการไฟล์
     * ที่เขียนอะไรที่ไหนก็ได้ ซึ่งเป็นคนละเรื่องและมีความเสี่ยงคนละระดับ
     */
    public const FILE = 'custom.conf';

    /** ใหญ่เกินนี้ไม่ใช่ค่าตั้งแล้ว — กันการยัดข้อมูลลงไฟล์ที่ถูกอ่านทุกครั้งที่ reload */
    private const MAX_BYTES = 65536;

    /**
     * ไดเรกทอรีของเว็บไซต์หนึ่ง ตามเว็บเซิร์ฟเวอร์ที่ใช้อยู่
     *
     * แยกตามชนิดเซิร์ฟเวอร์เพราะไวยากรณ์คนละแบบ — สลับจาก Apache ไป nginx แล้ว
     * ไฟล์ของอีกฝั่งต้องไม่ถูกอ่าน ไม่งั้น configtest ล้มทั้งเครื่องโดยที่ผู้ดูแล
     * ไม่ได้แก้อะไรเลยในวันนั้น
     */
    public static function directory(string $server, string $domain): string
    {
        if (!in_array($server, ['apache', 'nginx'], true)) {
            throw new ValidationError('ชนิดเว็บเซิร์ฟเวอร์ไม่ถูกต้อง: ' . $server);
        }

        return self::ROOT . '/' . $server . '/' . Validator::domain($domain);
    }

    public static function path(string $server, string $domain): string
    {
        return self::directory($server, $domain) . '/' . self::FILE;
    }

    /** เนื้อไฟล์ปัจจุบัน — ยังไม่เคยเขียน = ค่าว่าง ไม่ใช่ข้อผิดพลาด */
    public function read(Executor $executor, string $server, string $domain): string
    {
        $path = $executor->path(self::path($server, $domain));

        if (!$executor->exists($path)) {
            return '';
        }

        try {
            return $executor->readFile($path);
        } catch (\Throwable) {
            return '';
        }
    }

    /**
     * ตรวจเนื้อไฟล์ก่อนนำไปเขียน
     *
     * ตรวจแค่สองอย่างที่เป็นเรื่องของ "ไฟล์" ไม่ใช่เรื่องของ "ค่าตั้ง": ขนาด กับ
     * ไบต์ศูนย์ · ความถูกต้องของไวยากรณ์เป็นงานของตัวตรวจของเว็บเซิร์ฟเวอร์เอง
     * ซึ่งแม่นกว่าอะไรที่เราจะเขียนเองได้ และเป็นตัวเดียวกับที่มันใช้ตอนโหลดจริง
     */
    public static function assertContent(string $content): string
    {
        if (strlen($content) > self::MAX_BYTES) {
            throw new ValidationError(sprintf(
                'ไฟล์ตั้งค่ายาวเกิน %d KB — ค่าตั้งที่ยาวขนาดนี้มักเป็นสัญญาณว่ากำลังใส่ผิดที่',
                (int) (self::MAX_BYTES / 1024),
            ));
        }

        // ไบต์ศูนย์ทำให้ไฟล์ถูกอ่านไม่ครบโดยไม่มีข้อผิดพลาด — ตัดทิ้งตั้งแต่ต้นทาง
        if (str_contains($content, "\0")) {
            throw new ValidationError('ไฟล์ตั้งค่ามีไบต์ศูนย์ปนอยู่');
        }

        // ลงท้ายด้วยขึ้นบรรทัดใหม่เสมอ — บางคำสั่งของ nginx ต้องการ และ diff อ่านง่ายกว่า
        $content = rtrim($content, "\r\n");

        return $content === '' ? '' : $content . "\n";
    }

    /**
     * เนื้อไฟล์ตั้งต้นสำหรับไฟล์ที่ยังไม่เคยถูกเขียน
     *
     * **คำอธิบายอยู่ในไฟล์ ไม่ใช่บนหน้าจอ** — เป็นที่ที่ผู้ดูแลระบบคาดว่าจะเจอมันอยู่แล้ว
     * (ไฟล์ `.conf` ของดิสโทรทุกตัวมาพร้อมคอมเมนต์อธิบาย) · และมันติดไปกับไฟล์เสมอ
     * ไม่ว่าจะเปิดจากหน้าเว็บหรือ `cat` ดูผ่าน SSH ต่างจากข้อความบนหน้าจอที่หายไปทันที
     * ที่ปิดหน้าต่าง
     *
     * ตัวอย่างในไฟล์ถูกคอมเมนต์ไว้ทั้งหมด — ไฟล์ตั้งต้นที่บันทึกทันทีต้องไม่เปลี่ยน
     * พฤติกรรมของเว็บแม้แต่นิดเดียว
     */
    public function seed(Template $templates, string $server, string $domain): string
    {
        $file = $server === 'nginx' ? 'custom/nginx.conf.tpl' : 'custom/apache.conf.tpl';

        try {
            return $templates->render($file, ['DOMAIN' => $domain]);
        } catch (\Throwable) {
            // ไม่มีเทมเพลตก็ยังแก้ไฟล์ได้ แค่ไม่มีคำอธิบายให้ — ไม่ใช่เหตุให้ทั้งหน้าพัง
            return '';
        }
    }
}
