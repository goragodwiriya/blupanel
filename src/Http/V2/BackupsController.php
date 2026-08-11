<?php

declare(strict_types=1);

namespace Phpcp\Http\V2;

use Phpcp\Http\ApiProblem;
use Phpcp\Http\Resource\BackupResource;
use Phpcp\Kernel\Request;
use Phpcp\Kernel\Response;

/**
 * ข้อมูลสำรอง — `/api/v2/backups`
 *
 * ไฟล์สำรองระดับระบบ (type `config`) ไม่ผูกกับเว็บไซต์ใด ผู้ดูแลเว็บไซต์จึงไม่เห็น
 * — การกรองด้วย JOIN ทำให้แถวที่ `site_id` เป็น null หลุดออกจากผลลัพธ์ของ webadmin
 * โดยอัตโนมัติ ซึ่งเป็นพฤติกรรมที่ต้องการพอดี
 *
 * **การกู้คืนเป็นคำสั่งที่อันตรายที่สุดในระบบ** — มันเขียนทับข้อมูลปัจจุบันทั้งหมด
 * capability จึงบังคับให้ยืนยันด้วยชื่อโดเมนและตรวจ checksum ของไฟล์ก่อนแตะอะไรเลย
 * ที่นี่ทำเป็น sub-resource `restoration` ตาม §4.1 (คำนาม ไม่ใช่กริยา)
 */
final class BackupsController extends HostingController
{
    /** เพดานของรายการ — เครื่องที่เปิดสำรองอัตโนมัติมีไฟล์สะสมเป็นพัน */
    private const MAX_ROWS = 500;

    public function index(Request $request): Response
    {
        $owner = $this->scopeOwner();
        $where = $owner === null ? '' : ' WHERE s.owner_user_id = :owner';
        $params = $owner === null ? [] : ['owner' => $owner];

        $rows = $this->app->db()->all(
            'SELECT b.*, s.primary_domain, d.name AS destination_name
             FROM backups b
        LEFT JOIN sites s ON s.id = b.site_id
        LEFT JOIN backup_destinations d ON d.id = b.destination_id' . $where . '
             ORDER BY b.created_at DESC LIMIT ' . self::MAX_ROWS,
            $params,
        );

        $type = $request->get('type');
        if ($type !== '') {
            $rows = array_values(array_filter($rows, static fn (array $r): bool => $r['type'] === $type));
        }

        $siteId = $request->queryInt('site_id', 0);
        if ($siteId > 0) {
            $rows = array_values(array_filter($rows, static fn (array $r): bool => (int) ($r['site_id'] ?? 0) === $siteId));
        }

        $page = $this->pagination($request);
        $slice = array_slice($rows, $page['offset'], $page['per_page']);

        // เงื่อนไขปุ่มในตารางอ่านได้แค่ค่าในแถวเดียวกัน — สิทธิ์จึงต้องมากับแถว
        // (ชื่อแบน ๆ can_manage/can_restore/can_offsite ไม่ใช่ can.manage ซ้อนกัน
        // เพราะเงื่อนไขของ data-row-actions ยังไม่ยืนยันว่ารองรับ property path ซ้อน)
        $canManage = $this->ctx->can('backup.manage');
        $canRestore = $this->ctx->can('backup.restore');
        $canOffsite = $this->ctx->can('backup.offsite');
        $items = array_map(
            static fn (array $row): array => $row + [
                'can_manage' => $canManage,
                'can_restore' => $canRestore,
                'can_offsite' => $canOffsite,
            ],
            BackupResource::collection($slice),
        );

        return $this->paginate($items, count($rows), $page['page'], $page['per_page'])
            ->withHeader('X-Total-Size', (string) array_sum(array_column($rows, 'size_bytes')));
    }

    /**
     * สร้างไฟล์สำรอง
     *
     * ตอบ 201 ไม่ใช่ 202 เพราะ capability ทำงานแบบ synchronous จริง ๆ — ไฟล์พร้อมใช้
     * ตั้งแต่ตอนที่คำตอบกลับไปถึงผู้เรียกแล้ว · จะเปลี่ยนเป็น 202 ก็ต่อเมื่อย้ายไปเป็น
     * งานเบื้องหลังจริงในอนาคต (เฟส E1 ที่มีปลายทางนอกเครื่อง)
     */
    public function store(Request $request): Response
    {
        $siteId = (int) $request->payload('site_id', 0);

        // สำรองของเว็บไซต์ต้องเป็นเว็บที่ผู้เรียกมีสิทธิ์ · type config ไม่ผูกกับเว็บใด
        if ($siteId > 0 && $this->findSite($siteId) === null) {
            return $this->siteNotFound();
        }

        $result = $this->agent()->data('backup.create', [
            'type' => $request->payloadString('type'),
            'site_id' => $siteId,
            'database' => trim($request->payloadString('database')),
            'note' => trim($request->payloadString('note')),
        ], $this->ctx->actor($request));

        $backup = isset($result['backup_id'])
            ? $this->app->db()->first('SELECT * FROM backups WHERE id = :id', ['id' => $result['backup_id']])
            : null;

        return $this->done(
            (string) ($result['message'] ?? 'สร้างข้อมูลสำรองแล้ว'),
            [
                ['type' => 'notification', 'level' => 'success',
                 'message' => (string) ($result['message'] ?? 'สร้างข้อมูลสำรองแล้ว')],
                ['type' => 'redirect', 'url' => 'reload', 'target' => 'backups'],
            ],
            $result + ['backup_id' => (int) ($backup['id'] ?? 0)],
            201,
        )->withHeader('Location', '/api/v2/backups' . ($backup === null ? '' : '/' . $backup['id']));
    }

    /**
     * กู้คืนจากไฟล์สำรอง — ต้องยืนยันด้วยชื่อโดเมน/ชื่อเป้าหมาย
     *
     * capability ตรวจ checksum ก่อนเสมอ และสร้างไฟล์สำรองนิรภัยของสถานะปัจจุบันไว้ก่อน
     * เขียนทับ — ถ้าไฟล์ที่กู้เสียหาย ยังย้อนกลับไปจุดก่อนกู้ได้
     */
    public function restore(Request $request): Response
    {
        $backup = $this->findBackup($request->paramInt('id'));

        if ($backup === null) {
            return $this->problem(ApiProblem::NotFound, 'ไม่พบไฟล์สำรองที่ระบุ');
        }

        $result = $this->agent()->data('backup.restore', [
            'backup_id' => (int) $backup['id'],
            'confirm' => trim($request->payloadString('confirm')),
        ], $this->ctx->actor($request));

        return $this->completed(
            (string) ($result['message'] ?? 'กู้คืนข้อมูลแล้ว'),
            'backups',
            $result,
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
        $backup = $this->findBackup($request->paramInt('id'));

        if ($backup === null) {
            return $this->problem(ApiProblem::NotFound, 'ไม่พบไฟล์สำรองที่ระบุ');
        }

        $result = $this->agent()->data('backup.push', [
            'backup_id' => (int) $backup['id'],
            'destination_id' => (int) $request->payload('destination_id', 0),
        ], $this->ctx->actor($request));

        return $this->completed(
            (string) ($result['message'] ?? 'ส่งไฟล์สำรองออกไปแล้ว'),
            'backups',
            $result,
        );
    }

    public function destroy(Request $request): Response
    {
        $backup = $this->findBackup($request->paramInt('id'));

        if ($backup === null) {
            return $this->problem(ApiProblem::NotFound, 'ไม่พบไฟล์สำรองที่ระบุ');
        }

        $this->agent()->data('backup.delete', [
            'backup_id' => (int) $backup['id'],
        ], $this->ctx->actor($request));

        return $this->completed('ลบไฟล์สำรองแล้ว', 'backups', ['backup_id' => (int) $backup['id']]);
    }

    /**
     * โหลดไฟล์สำรองที่ผู้เรียกมีสิทธิ์เห็น
     *
     * ไฟล์ระดับระบบ (`site_id` เป็น null) ผู้ดูแลเว็บไซต์ต้องไม่เห็นและกู้ไม่ได้ —
     * มันมีค่า config ของทั้งเครื่องอยู่ข้างใน
     *
     * @return array<string,mixed>|null
     */
    private function findBackup(int $id): ?array
    {
        if ($id < 1) {
            return null;
        }

        $backup = $this->app->db()->first('SELECT * FROM backups WHERE id = :id', ['id' => $id]);

        if ($backup === null) {
            return null;
        }

        if ($this->scopeOwner() === null) {
            return $backup;
        }

        $siteId = (int) ($backup['site_id'] ?? 0);

        return $siteId > 0 && $this->mayAccessSite($siteId) ? $backup : null;
    }
}
