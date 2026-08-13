<?php

declare(strict_types=1);

namespace Phpcp\Http\V2;

use Phpcp\Kernel\Request;
use Phpcp\Kernel\Response;

/**
 * ไฟล์ตั้งค่าของระบบ — `/api/v2/config-files` (ตัวกลางที่ทุกหน้าจอเรียกใช้ได้)
 *
 * แยกออกมาเป็นทรัพยากรของตัวเองแทนที่จะซ่อนไว้ใต้ `/sites/{id}` เพราะเป็นเรื่องเดียวกัน
 * ไม่ว่าไฟล์นั้นจะเป็นของเว็บไซต์ DNS หรือเมล · หน้าจอเรียกด้วยรูปแบบเดียว เปิด Modal
 * ตัวเดียวกัน และกติกาความปลอดภัยข้อเดียวกันบังคับใช้กับทุกไฟล์โดยอัตโนมัติ
 *
 * **หน้าจอส่ง "คีย์" ไม่เคยส่งเส้นทาง** — เส้นทางจริงถูกประกอบใน `ConfigFileCatalog`
 * จากข้อมูลที่ผ่านการตรวจแล้ว ผู้เรียกจึงขอไฟล์นอกทะเบียนไม่ได้เลย
 */
final class ConfigFilesController extends HostingController
{
    /** รายการไฟล์ของขอบเขตหนึ่ง — ตอนนี้มีขอบเขตเดียวคือเว็บไซต์ */
    public function index(Request $request): Response
    {
        $siteId = (int) $request->get('site_id');

        if ($this->findSite($siteId) === null) {
            return $this->siteNotFound();
        }

        $result = $this->agent()->data(
            'config.file_read',
            ['site_id' => $siteId],
            $this->ctx->actor($request),
        );

        $rows = array_map(
            fn (array $file): array => $file + [
                'row_id' => $file['key'],
                // ปุ่มในแถวประกอบ URL จากค่าในแถว — ต้องมี site_id ติดไปด้วย
                // ไม่งั้น `{site_id}` กลายเป็นค่าว่างแล้วเปิดไฟล์ไม่ได้
                'site_id' => $siteId,
                'writable' => $file['kind'] === 'writable',
                // ป้ายที่บอกให้ชัดว่าแก้ได้หรือดูได้อย่างเดียว — เป็นคำตอบจากเซิร์ฟเวอร์
                // ไม่ใช่การเดาของหน้าจอจากชื่อไฟล์
                'kind_label' => $file['kind'] === 'writable'
                    ? $this->t('You can edit this')
                    : $this->t('Read-only — the panel rewrites it'),
                'kind_tone' => $file['kind'] === 'writable' ? 'ok' : 'muted',
                'state' => $file['exists'] ? $this->t('In use') : $this->t('Not created yet'),
            ],
            (array) ($result['files'] ?? []),
        );

        return $this->ok($rows);
    }

    /**
     * เปิดไฟล์เดียวใน Modal
     *
     * ไฟล์ที่แก้ได้เปิดเป็นฟอร์ม ไฟล์ที่แก้ไม่ได้เปิดเป็นข้อความอ่านอย่างเดียว —
     * ตัดสินที่เซิร์ฟเวอร์จากทะเบียน ไม่ใช่ที่หน้าจอ · เทมเพลตเดียวรองรับทั้งสองแบบ
     */
    public function show(Request $request): Response
    {
        $siteId = (int) $request->get('site_id');

        if ($this->findSite($siteId) === null) {
            return $this->siteNotFound();
        }

        $result = $this->agent()->data(
            'config.file_read',
            ['site_id' => $siteId, 'key' => $request->param('key')],
            $this->ctx->actor($request),
        );

        $writable = ($result['kind'] ?? '') === 'writable';

        return $this->ok(
            [
                'key' => (string) ($result['key'] ?? ''),
                'site_id' => $siteId,
                'path' => (string) ($result['path'] ?? ''),
                'content' => (string) ($result['content'] ?? ''),
                'writable' => $writable,
                'readonly' => !$writable,
            ],
            [],
            [[
                'type' => 'modal',
                'action' => 'show',
                'template' => 'config-file.html',
                'title' => (string) ($result['path'] ?? ''),
                'titleClass' => $writable ? 'icon-edit' : 'icon-lock',
            ]],
        );
    }

    /**
     * เขียนไฟล์ที่แก้ได้
     *
     * **ตัวเขียนไม่เชื่อคีย์ที่ส่งมาเรื่องสิทธิ์การเขียน** — capability เป็นคนตัดสินจาก
     * ทะเบียนอีกรอบว่าไฟล์นี้แก้ได้จริงไหม · หน้าจอที่ส่งคีย์ของไฟล์อ่านอย่างเดียวมา
     * จึงถูกปฏิเสธที่ชั้นล่างสุด ไม่ใช่แค่ปุ่มไม่ขึ้นบนหน้าจอ
     */
    public function update(Request $request): Response
    {
        $siteId = (int) $request->payload('site_id', 0);

        if ($this->findSite($siteId) === null) {
            return $this->siteNotFound();
        }

        $result = $this->agent()->data('site.custom_config', [
            'site_id' => $siteId,
            'key' => $request->param('key'),
            'content' => $request->payloadString('content'),
            'window' => (int) $request->payload('window', 0),
        ], $this->ctx->actor($request));

        return $this->saved(
            (string) ($result['message'] ?? 'Configuration saved'),
            'configFiles',
            is_array($result) ? $result : [],
        );
    }
}
