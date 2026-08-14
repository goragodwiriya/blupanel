<?php

declare(strict_types=1);

namespace Phpcp\Http\V2;

use Phpcp\Http\ApiController;
use Phpcp\Http\ApiProblem;
use Phpcp\Kernel\Paths;
use Phpcp\Kernel\Request;
use Phpcp\Kernel\Response;
use Phpcp\Security\Permissions;

/**
 * บัญชีไหนถูกสำรองอัตโนมัติบ้าง — `/api/v2/backup-targets` (ข้อ B3, B6, B8)
 *
 * ## ทำไมเป็นตารางของ "บัญชี" ไม่ใช่ของ "เว็บ"
 *
 * ไฟล์สำรองอยู่ในบ้านของบัญชีและนับในโควตาของบัญชี · การเปิด-ปิดรายเว็บทำให้ผู้ดูแล
 * ต้องกลับมาตั้งค่าใหม่ทุกครั้งที่ลูกค้าเพิ่มเว็บ ซึ่งเป็นจังหวะที่คนลืมได้ง่ายที่สุด
 * และผลของการลืมคือเว็บที่ไม่มีสำเนาเลยโดยไม่มีอะไรฟ้อง · เปิดที่บัญชีแล้วเว็บใหม่
 * ของบัญชีนั้นเข้ารอบเองทันที
 *
 * ## ทำไมสองสวิตช์ ไม่ใช่สวิตช์เดียว
 *
 * เว็บสแตติกไม่มีฐานข้อมูล และบางบัญชีเก็บไฟล์เว็บไว้ใน git อยู่แล้วจึงต้องการเฉพาะ
 * ฐานข้อมูล · สวิตช์เดียวแปลว่าทั้งสองกลุ่มจ่ายพื้นที่ให้ของที่ไม่ได้ต้องการ (ข้อ B8)
 *
 * ## ทำไมเขียนตรงลงฐานข้อมูล ไม่ผ่าน agent
 *
 * ค่านี้ไม่แตะเครื่องเลย — ไม่มีไฟล์ ไม่มี service ไม่มีสิทธิ์ระบบเข้ามาเกี่ยว · สิ่งที่
 * มันเปลี่ยนคือ "รอบถัดไปจะหยิบใครบ้าง" ซึ่งอยู่ในฐานข้อมูลของ panel เอง · รูปแบบเดียว
 * กับ `BackupSchedulesController` · audit ยังเขียนเองที่นี่เพราะไม่ได้ผ่าน Dispatcher
 */
final class BackupTargetsController extends ApiController
{
    public function index(Request $request): Response
    {
        /*
         * **บัญชีโฮสติ้งทุกบัญชี ไม่ว่าจะพร้อมหรือยัง**
         *
         * เคยกรอง `system_user IS NOT NULL` ออกไป ซึ่งแปลว่าบัญชีที่ยังไม่เคยมีเว็บ
         * หายไปจากตารางเงียบ ๆ · ผู้ดูแลที่เปิดหน้านี้มาหาลูกค้ารายนั้นจะไม่เจอชื่อเขา
         * แล้วสรุปว่าระบบพัง หรือแย่กว่านั้นคือคิดว่าเปิดสำรองให้เขาไปแล้ว
         *
         * บัญชีที่ยังไม่มีบ้านยังติ๊กไม่ได้ (ไม่มีโฟลเดอร์ให้เขียน) แต่ต้อง**เห็นพร้อม
         * เหตุผล** ไม่ใช่หายไปเฉย ๆ
         */
        $rows = $this->app->db()->all(
            "SELECT u.id, u.username, u.system_user, u.backup_files, u.backup_database,
                    u.disk_quota_mb, u.disk_used_mb, u.service_status,
                    (SELECT COUNT(*) FROM sites s WHERE s.owner_user_id = u.id) AS sites,
                    (SELECT COUNT(*) FROM databases_ d
                       JOIN sites s2 ON s2.id = d.site_id
                      WHERE s2.owner_user_id = u.id) AS databases
               FROM users u
              WHERE u.role = :role
              ORDER BY u.username",
            ['role' => Permissions::WEBADMIN],
        );

        $canManage = $this->ctx->can('backup.offsite');

        $items = array_map(
            static function (array $row) use ($canManage): array {
                $home = (string) ($row['system_user'] ?? '');
                $ready = $home !== '';

                return [
                    'id' => (int) $row['id'],
                    'username' => (string) $row['username'],
                    // ยังไม่มีบัญชีระบบ = ยังไม่มีบ้าน · บอกตรง ๆ ดีกว่าโชว์เส้นทางที่ไม่มีอยู่
                    'backup_dir' => $ready ? Paths::usersDir() . '/' . $home . '/backup' : '—',
                    'backup_files' => (int) $row['backup_files'] === 1,
                    'backup_database' => (int) $row['backup_database'] === 1,
                    'sites' => (int) $row['sites'],
                    'databases' => (int) $row['databases'],
                    // โควตาที่เหลือ — ไฟล์สำรองนับในนี้ด้วย ผู้ดูแลจึงต้องเห็นก่อนกดเปิด
                    'disk_quota_mb' => (int) ($row['disk_quota_mb'] ?? -1),
                    'disk_used_mb' => (int) ($row['disk_used_mb'] ?? 0),
                    'ready' => $ready,
                    'reason' => $ready ? '' : 'ยังไม่มีบัญชีระบบ — สร้างเว็บไซต์แรกให้บัญชีนี้ก่อน',
                    'can_manage' => $canManage && $ready,
                ];
            },
            $rows,
        );

        return $this->ok($items);
    }

    public function update(Request $request): Response
    {
        $id = $request->paramInt('id');
        $row = $this->app->db()->first(
            'SELECT id, username, system_user, backup_files, backup_database FROM users WHERE id = :id AND role = :role',
            ['id' => $id, 'role' => Permissions::WEBADMIN],
        );

        if ($row === null) {
            return $this->problem(ApiProblem::NotFound, 'Hosting account not found');
        }

        // บัญชีที่ยังไม่มีบ้านเปิดสำรองไม่ได้ — รอบจะไปล้มทุกคืนโดยไม่มีทางสำเร็จ
        if ((string) ($row['system_user'] ?? '') === '') {
            return $this->problem(
                ApiProblem::ValidationError,
                'This account has no home folder yet — create its first website first',
            );
        }

        $fields = [];

        foreach (['backup_files', 'backup_database'] as $column) {
            if ($request->payload($column) !== null) {
                $fields[$column] = $this->flag($request->payload($column));
            }
        }

        if ($fields === []) {
            return $this->problem(ApiProblem::ValidationError, 'Send at least one value to change');
        }

        $this->app->db()->update('users', $fields + ['updated_at' => time()], ['id' => $id]);
        // ทรัพยากรนี้ไม่ผ่าน Dispatcher จึงไม่มีใครเขียน audit ให้อัตโนมัติ
        $this->app->audit()->write($this->ctx->actor($request), 'backup.target_set', (string) $row['username'], 'ok', $fields);

        return $this->saved('Saved', 'backup-targets', [
            'id' => $id,
            'backup_files' => (int) ($fields['backup_files'] ?? $row['backup_files']) === 1,
            'backup_database' => (int) ($fields['backup_database'] ?? $row['backup_database']) === 1,
        ]);
    }

    /**
     * ค่าจาก checkbox ของหน้าจอ → 0/1
     *
     * รับได้ทั้ง true/1/"1"/"on" เพราะ checkbox ที่ไม่ได้ผ่าน JSON ส่ง "on" มา และ
     * การรับแค่ค่าเดียวแปลว่าฟอร์มที่ถูกส่งอีกแบบจะ "บันทึกสำเร็จ" โดยไม่เปลี่ยนอะไร
     */
    private function flag(mixed $value): int
    {
        if (is_bool($value)) {
            return $value ? 1 : 0;
        }

        return in_array((string) $value, ['1', 'on', 'true', 'yes'], true) ? 1 : 0;
    }
}
