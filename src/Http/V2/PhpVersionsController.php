<?php

declare(strict_types=1);

namespace Phpcp\Http\V2;

use Phpcp\Http\ApiController;
use Phpcp\Kernel\Request;
use Phpcp\Kernel\Response;

/**
 * เวอร์ชัน PHP ที่ติดตั้งอยู่บนเครื่อง — `/api/v2/php-versions`
 *
 * อ่านสถานะจริงจาก agent ทุกครั้ง ไม่แคช เพราะผู้ดูแลติดตั้ง/ถอน PHP ผ่าน apt
 * นอกเหนือจาก panel ได้ตลอดเวลา — รายการที่แคชไว้จะกลายเป็นคำโกหกทันทีที่เขาทำแบบนั้น
 *
 * ตามกฎ UX ใน PROMPT.md: หน้านี้เป็นข้อมูลอย่างเดียว **ไม่มีปุ่มควบคุมโปรเซส FPM**
 * การ start/stop อยู่ที่ทรัพยากร services เท่านั้น (ค่า `fpm_running` มีไว้ให้แสดงสถานะ
 * ไม่ใช่ให้สร้างปุ่มสั่งงานตรงนี้)
 */
final class PhpVersionsController extends ApiController
{
    public function index(Request $request): Response
    {
        $data = $this->fetchPhpVersions($request);
        $versions = $data['versions'];

        // สีของป้ายมาจากฝั่งเซิร์ฟเวอร์ เทมเพลตจึงเขียน `pill-${..._tone}` ได้ตรง ๆ
        // ไม่ต้องมีตารางแปลงสถานะ→สี ซ้ำกันในฝั่งหน้าจอ
        $versions = array_map(static function (array $v): array {
            $v['fpm_tone'] = match ($v['fpm_status'] ?? '') {
                'running' => 'ok',
                'failed' => 'danger',
                'transitioning' => 'warn',
                default => 'muted',
            };
            $v['supported_tone'] = ($v['supported'] ?? false) ? 'ok' : 'warn';
            // `data-template` แทนได้แค่ ${key} ตรง ๆ ไม่รองรับเงื่อนไข — ต้องมีฟิลด์
            // ข้อความสำเร็จรูปมาให้ ไม่ใช่ให้เทมเพลตคำนวณจาก boolean เอง
            $v['supported_label'] = ($v['supported'] ?? false) ? 'Supported' : 'End of life';

            return $v;
        }, $versions);

        return $this->ok($versions, [
            'installed_count' => (int) ($data['installed_count'] ?? count($versions)),
            // เวอร์ชันที่แนะนำสำหรับเว็บใหม่ — ตัวแรกหลังเรียงคือตัวที่เสิร์ฟได้อยู่จริง
            'default' => $versions[0]['version'] ?? ($data['default'] ?? null),
        ]);
    }
}
