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
        // บัญชีโฮสติ้งทุกบัญชีที่มีบ้านจริง — บัญชีผู้ดูแลไม่มีเว็บให้สำรอง
        $rows = $this->app->db()->all(
            "SELECT u.id, u.username, u.system_user, u.backup_files, u.backup_database,
                    u.disk_quota_mb, u.disk_used_mb,
                    (SELECT COUNT(*) FROM sites s WHERE s.owner_user_id = u.id) AS sites,
                    (SELECT COUNT(*) FROM databases_ d
                       JOIN sites s2 ON s2.id = d.site_id
                      WHERE s2.owner_user_id = u.id) AS databases
               FROM users u
              WHERE u.role = :role AND u.system_user IS NOT NULL
              ORDER BY u.username",
            ['role' => Permissions::WEBADMIN],
        );

        $canManage = $this->ctx->can('backup.manage');

        $items = array_map(
            static fn (array $row): array => [
                'id' => (int) $row['id'],
                'username' => (string) $row['username'],
                'backup_dir' => Paths::usersDir() . '/' . (string) ($row['system_user'] ?: $row['username']) . '/backup',
                'backup_files' => (int) $row['backup_files'] === 1,
                'backup_database' => (int) $row['backup_database'] === 1,
                'sites' => (int) $row['sites'],
                'databases' => (int) $row['databases'],
                // โควตาที่เหลือ — ไฟล์สำรองนับในนี้ด้วย ผู้ดูแลจึงต้องเห็นก่อนกดเปิด
                'disk_quota_mb' => (int) ($row['disk_quota_mb'] ?? -1),
                'disk_used_mb' => (int) ($row['disk_used_mb'] ?? 0),
                'can_manage' => $canManage,
            ],
            $rows,
        );

        return $this->ok($items);
    }

    public function update(Request $request): Response
    {
        $id = $request->paramInt('id');
        $row = $this->app->db()->first(
            'SELECT id, username, backup_files, backup_database FROM users WHERE id = :id AND role = :role',
            ['id' => $id, 'role' => Permissions::WEBADMIN],
        );

        if ($row === null) {
            return $this->problem(ApiProblem::NotFound, 'Hosting account not found');
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
