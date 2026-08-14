<?php

declare(strict_types=1);

namespace Phpcp\Http\V2;

use Phpcp\Http\Resource\BackupResource;
use Phpcp\Kernel\Paths;
use Phpcp\Kernel\Request;
use Phpcp\Kernel\Response;

/**
 * ไฟล์สำรอง — `/api/v2/backups`
 *
 * **รายการมาจากโฟลเดอร์จริงในบ้านของลูกค้า ไม่ใช่จากตาราง** (PLAN-BACKUP-V2 ข้อ B4) ·
 * `<บ้าน>/backup` เปิดให้ลูกค้าเข้าถึงผ่าน SFTP โดยตั้งใจ เขาลบไฟล์ของตัวเองได้ทุกเมื่อ
 * — แถวในตารางที่บันทึกไว้ตอนสร้างจึงเป็นคำโกหกที่รอเวลา · ที่นี่จึงถาม agent ให้อ่าน
 * โฟลเดอร์ให้ทุกครั้ง (`backup.list`) แทนการ SELECT
 *
 * ไฟล์ถูกอ้างด้วย **บัญชี + ชื่อไฟล์** ไม่ใช่รหัสแถว · เส้นทางของ REST จึงเป็น
 * `/api/v2/backups/{user}/{file}` ซึ่งอ้างถึงตัวไฟล์จริงที่ยังอยู่หรือไม่อยู่ก็ได้
 * ต่างจากรหัสแถวที่ชี้ไปยังบันทึกที่อาจไม่ตรงกับความจริงแล้ว
 */
final class BackupsController extends HostingController
{
    public function index(Request $request): Response
    {
        $result = $this->agent()->data('backup.list', [
            'user_id' => $request->queryInt('user_id', 0),
        ], $this->ctx->actor($request));

        $files = is_array($result['files'] ?? null) ? $result['files'] : [];

        $type = $request->get('type');
        if ($type !== '') {
            $files = array_values(array_filter($files, static fn (array $f): bool => $f['type'] === $type));
        }

        $domain = $request->get('domain');
        if ($domain !== '') {
            $files = array_values(array_filter($files, static fn (array $f): bool => $f['domain'] === $domain));
        }

        $page = $this->pagination($request);
        $slice = array_slice($files, $page['offset'], $page['per_page']);

        // เงื่อนไขปุ่มในตารางอ่านได้แค่ค่าในแถวเดียวกัน — สิทธิ์จึงต้องมากับแถว
        // (ชื่อแบน ๆ can_manage/can_restore/can_offsite ไม่ใช่ can.manage ซ้อนกัน
        // เพราะเงื่อนไขของ data-row-actions ยังไม่ยืนยันว่ารองรับ property path ซ้อน)
        $canManage = $this->ctx->can('backup.manage');
        $canRestore = $this->ctx->can('backup.restore');
        $canOffsite = $this->ctx->can('backup.offsite');
        $sites = $this->domainToSiteId();

        $items = array_map(
            static fn (array $row): array => BackupResource::one($row + [
                'site_id' => $sites[$row['domain']] ?? 0,
            ]) + [
                'can_manage' => $canManage,
                'can_restore' => $canRestore && $row['restorable'],
                'can_offsite' => $canOffsite,
            ],
            $slice,
        );

        return $this->paginate($items, count($files), $page['page'], $page['per_page'])
            ->withHeader('X-Total-Size', (string) array_sum(array_column($files, 'bytes')));
    }

    /**
     * ที่เก็บไฟล์สำรอง — **ผู้ใช้ต้องรู้ว่าไฟล์ของตัวเองอยู่ที่ไหนจริง ๆ**
     *
     * ตอนนี้คำตอบมีความหมายกับลูกค้าโดยตรง ไม่ใช่แค่กับผู้ดูแล: เส้นทางที่ตอบไปคือ
     * เส้นทางที่เขา `cd` เข้าไปได้จริงผ่าน SFTP แล้วดาวน์โหลดไฟล์ออกมาเอง
     *
     * **แยกเป็น endpoint ของตัวเอง ไม่ใช่ `meta` ของรายการ** — `meta.*` ผูกกับ
     * `data-text`/`data-if` ของ Now.js ไม่ได้ (คอมโพเนนต์เห็นเฉพาะชั้น `data`)
     * ป้ายที่ผูกกับ meta จึงไม่เคยขึ้นเลย · เรื่องนี้เสียเวลาไปแล้วหนึ่งรอบในหน้า Mailboxes
     */
    public function storage(Request $request): Response
    {
        $result = $this->agent()->data('backup.list', [
            'user_id' => $request->queryInt('user_id', 0),
        ], $this->ctx->actor($request));

        $files = is_array($result['files'] ?? null) ? $result['files'] : [];
        $owner = $this->scopeOwner();

        // ลูกค้าเห็นเส้นทางเดียวคือของตัวเอง · ผู้ดูแลเห็นหลายบัญชีจึงบอกเป็นรูปแบบ
        $path = $owner === null
            ? Paths::usersDir() . '/<บัญชี>/backup'
            : (string) ($this->app->db()->value(
                'SELECT COALESCE(system_user, username) FROM users WHERE id = :id',
                ['id' => $owner],
                '',
            ));

        if ($owner !== null) {
            $path = Paths::usersDir() . '/' . $path . '/backup';
        }

        return $this->ok([
            'path' => $path,
            'files' => count($files),
            'bytes' => (int) ($result['bytes'] ?? 0),
            // ไฟล์อยู่ในบ้านของลูกค้าแล้ว จึงเปิดดูได้จากขอบเขตไฟล์ที่เขามีอยู่แล้ว
            // ไม่ต้องมีขอบเขตพิเศษของ panel อีก (ถอนออกแล้วใน PLAN-BACKUP-V2 §4.1)
            'scope' => '',
        ]);
    }

    /**
     * โครงเปล่าของฟอร์มสร้างไฟล์สำรอง พร้อมคำสั่งเปิด modal
     *
     * ไฟล์สำรองแก้ไม่ได้ (สร้าง · กู้คืน · ลบ) จึงมีแต่ฟอร์มของใหม่
     */
    public function form(Request $request): Response
    {
        return $this->ok(
            ['type' => 'site', 'site_id' => 0, 'database' => ''],
            [],
            [[
                'type' => 'modal',
                'action' => 'show',
                'template' => 'backup-form.html',
                'title' => '{LNG_Create backup}',
                'titleClass' => 'icon-stack',
            ]],
        );
    }

    /**
     * สร้างไฟล์สำรอง
     *
     * ตอบ 201 ไม่ใช่ 202 เพราะ capability ทำงานแบบ synchronous จริง ๆ — ไฟล์พร้อมใช้
     * ตั้งแต่ตอนที่คำตอบกลับไปถึงผู้เรียกแล้ว
     */
    public function store(Request $request): Response
    {
        $siteId = (int) $request->payload('site_id', 0);

        if ($this->findSite($siteId) === null) {
            return $this->siteNotFound();
        }

        $result = $this->agent()->data('backup.create', [
            'type' => $request->payloadString('type'),
            'site_id' => $siteId,
            'database' => trim($request->payloadString('database')),
        ], $this->ctx->actor($request));

        return $this->done(
            (string) ($result['message'] ?? 'Backup created'),
            [
                ['type' => 'modal', 'action' => 'close'],
                ['type' => 'notification', 'level' => 'success',
                 'message' => (string) ($result['message'] ?? 'Backup created')],
                ['type' => 'redirect', 'url' => 'reload', 'target' => 'backups'],
            ],
            is_array($result) ? $result : [],
            201,
        );
    }

    /**
     * กู้คืนจากไฟล์สำรอง — ต้องยืนยันด้วยชื่อโดเมน
     *
     * capability ตรวจ checksum ก่อนเสมอ อ่านใบแจ้งข้อมูลว่าไฟล์นี้เป็นของเว็บนี้จริง
     * และสร้างไฟล์สำรองนิรภัยของสถานะปัจจุบันไว้ก่อนเขียนทับ
     */
    public function restore(Request $request): Response
    {
        $result = $this->agent()->data('backup.restore', [
            'site_id' => (int) $request->payload('site_id', 0),
            'file' => self::fileParam($request),
            'confirm' => trim($request->payloadString('confirm')),
        ], $this->ctx->actor($request));

        return $this->completed(
            (string) ($result['message'] ?? 'Backup restored'),
            'backups',
            is_array($result) ? $result : [],
        );
    }

    /**
     * ส่งไฟล์สำรองออกไปยังปลายทางนอกเครื่อง
     *
     * เป็น sub-resource ชื่อ `offsite-copy` ตาม §4.1 (คำนาม ไม่ใช่กริยา) — สิ่งที่ถูก
     * สร้างขึ้นคือ "สำเนาที่อยู่นอกเครื่อง" ของไฟล์สำรองนั้น
     */
    public function pushOffsite(Request $request): Response
    {
        $result = $this->agent()->data('backup.push', [
            'user_id' => $request->paramInt('user'),
            'file' => self::fileParam($request),
            'destination_id' => (int) $request->payload('destination_id', 0),
        ], $this->ctx->actor($request));

        return $this->completed(
            (string) ($result['message'] ?? 'Backup copied offsite'),
            'backups',
            is_array($result) ? $result : [],
        );
    }

    /**
     * นำไฟล์สำรองที่อยู่ปลายทางนอกเครื่องกลับมาไว้ในโฟลเดอร์ของเจ้าของ
     *
     * จบที่การวางไฟล์ **ไม่กู้คืนต่อให้** — ผู้ดูแลกดกู้คืนเองแล้วพิมพ์ชื่อโดเมนยืนยัน
     * ตามเดิม · "ดึงไฟล์จากที่เก็บ" กับ "เขียนทับเว็บที่ใช้งานอยู่" เป็นคนละการตัดสินใจ
     */
    public function import(Request $request): Response
    {
        $result = $this->agent()->data('backup.import', [
            'destination_id' => (int) $request->payload('destination_id', 0),
            'remote_name' => trim($request->payloadString('remote_name')),
        ], $this->ctx->actor($request));

        return $this->saved(
            (string) ($result['message'] ?? 'Backup imported'),
            'backups',
            is_array($result) ? $result : [],
        );
    }

    public function destroy(Request $request): Response
    {
        $result = $this->agent()->data('backup.delete', [
            'user_id' => $request->paramInt('user'),
            'file' => self::fileParam($request),
        ], $this->ctx->actor($request));

        return $this->completed(
            (string) ($result['message'] ?? 'Backup deleted'),
            'backups',
            is_array($result) ? $result : [],
        );
    }

    /**
     * ชื่อไฟล์จากเส้นทาง — ถอดรหัส URL ก่อนเสมอ
     *
     * Router จับคู่กับส่วนของเส้นทางที่ยัง**เข้ารหัสอยู่** และไม่ถอดให้ · ชื่อไฟล์ที่
     * ระบบสร้างเองปลอดภัยกับ URL อยู่แล้ว แต่โฟลเดอร์นี้เป็นของลูกค้า เขาเปลี่ยนชื่อ
     * ไฟล์เป็นภาษาไทยหรือใส่ช่องว่างได้ · ไม่ถอด = ปุ่มลบของไฟล์พวกนั้นตอบว่า "ไม่พบไฟล์"
     *
     * `%2F` ที่ถอดออกมาเป็น `/` ยังถูก `BackupFiles::assertName()` ปฏิเสธอยู่ดี —
     * การถอดรหัสไม่ได้เปิดทางให้ไต่ออกนอกโฟลเดอร์
     */
    private static function fileParam(Request $request): string
    {
        return rawurldecode($request->param('file'));
    }

    /**
     * โดเมน → รหัสเว็บไซต์ที่ผู้เรียกมีสิทธิ์เห็น
     *
     * ปุ่มกู้คืนต้องส่ง `site_id` ไปด้วย แต่รายการอ่านมาจากชื่อไฟล์ซึ่งรู้แค่ชื่อโดเมน ·
     * แปลงที่นี่ทีเดียวแทนการ query ต่อแถว
     *
     * @return array<string,int>
     */
    private function domainToSiteId(): array
    {
        $owner = $this->scopeOwner();
        $rows = $this->app->db()->all(
            'SELECT id, primary_domain FROM sites'
            . ($owner === null ? '' : ' WHERE owner_user_id = :owner'),
            $owner === null ? [] : ['owner' => $owner],
        );

        $map = [];

        foreach ($rows as $row) {
            $map[(string) $row['primary_domain']] = (int) $row['id'];
        }

        return $map;
    }
}
