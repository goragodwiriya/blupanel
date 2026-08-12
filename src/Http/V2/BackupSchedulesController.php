<?php

declare(strict_types=1);

namespace Phpcp\Http\V2;

use Phpcp\Domain\CronSchedule;
use Phpcp\Domain\ScheduledJobRepository;
use Phpcp\Http\ApiController;
use Phpcp\Http\ApiProblem;
use Phpcp\Kernel\Request;
use Phpcp\Kernel\Response;

/**
 * ตารางเวลาสำรองข้อมูลอัตโนมัติ — `/api/v2/backup-schedules` (PLAN-V2 เฟส E1)
 *
 * เก็บเป็นแถวในตาราง `scheduled_jobs` เดิม ไม่มีตารางใหม่ · แต่**เปิดให้แก้ได้เฉพาะ
 * แถวที่ทรัพยากรนี้เป็นคนสร้าง** ซึ่งบังคับด้วยคำนำหน้าชื่อ `backup.auto.`
 *
 * **ทำไมไม่เปิด CRUD ของ `scheduled_jobs` ทั้งตาราง:** ในนั้นมี `rollback.run` ที่เป็น
 * กลไกกันผู้ดูแลล็อกตัวเองออกจากเครื่อง และ `expiry.check` ที่เป็นกลไกเชิงธุรกิจ ·
 * การเปิดให้ปิดงานเหล่านั้นผ่านหน้าเว็บคือการลดการป้องกันของระบบผ่านหน้าเว็บ ซึ่งเป็น
 * สิ่งเดียวกับที่ `SelfProtection` กันไว้ที่ชั้น service อยู่แล้ว · `ScheduledJobsController`
 * จึงยังอ่านอย่างเดียวต่อไป และทรัพยากรนี้แตะได้เฉพาะงานสำรองที่ผู้ใช้สร้างเอง
 *
 * **capability ถูกตรึงไว้ที่ `backup.create` เสมอ** — ผู้เรียกเลือกไม่ได้ · ถ้าเลือกได้
 * ก็เท่ากับตั้งเวลาให้ระบบรันคำสั่งอะไรก็ได้ในนามของ "ระบบ" ซึ่งเป็นสิทธิ์สูงสุดที่มี
 */
final class BackupSchedulesController extends ApiController
{
    /** คำนำหน้าที่แยกงานของทรัพยากรนี้ออกจากงานของระบบ */
    private const PREFIX = 'backup.auto.';

    public function index(Request $request): Response
    {
        $rows = array_values(array_filter(
            (new ScheduledJobRepository($this->app->db()))->all(),
            static fn (array $row): bool => str_starts_with((string) $row['name'], self::PREFIX),
        ));

        // เงื่อนไขปุ่มในตารางอ่านได้แค่ค่าในแถวเดียวกัน — สิทธิ์จึงต้องมากับแถว
        $canManage = $this->ctx->can('backup.offsite');
        $items = array_map(
            fn (array $row): array => $this->present($row) + ['can_manage' => $canManage],
            $rows,
        );

        return $this->ok($items, [
            'presets' => CronSchedule::presets(),
        ]);
    }

    /**
     * โครงเปล่าของฟอร์มตั้งเวลาสำรอง พร้อมคำสั่งเปิด modal
     *
     * แก้ตารางเวลาที่มีอยู่ยังทำผ่านปุ่มเปิด/ปิดในตารางเท่านั้น (PATCH เฉพาะ `enabled`)
     * ที่นี่จึงมีแต่ฟอร์มของใหม่ — ถ้าเพิ่มการแก้ไขเต็มรูปแบบเมื่อไหร่ ใช้ไฟล์เดียวกันนี้
     * ได้ทันทีด้วย `id` ที่ซ่อนไว้ แบบเดียวกับงานอัตโนมัติ
     */
    public function form(Request $request): Response
    {
        return $this->ok(
            ['label' => '', 'type' => 'site', 'site_id' => 0, 'schedule' => '0 1 * * *', 'destination_id' => 0],
            ['presets' => CronSchedule::presets()],
            [[
                'type' => 'modal',
                'action' => 'show',
                'template' => 'backup-schedule-form.html',
                'title' => '{LNG_Add schedule}',
                'titleClass' => 'icon-clock',
            ]],
        );
    }

    public function store(Request $request): Response
    {
        $label = trim($request->payloadString('label'));

        if ($label === '') {
            return $this->problem(ApiProblem::ValidationError, 'ต้องตั้งชื่อตารางเวลา', ['label' => 'ต้องระบุ']);
        }

        $type = $request->payloadString('type');

        if (!in_array($type, \Phpcp\Driver\BackupManager::TYPES, true)) {
            return $this->problem(ApiProblem::ValidationError, 'ชนิดข้อมูลสำรองไม่ถูกต้อง', ['type' => 'ต้องระบุ']);
        }

        try {
            $schedule = CronSchedule::normalize($request->payloadString('schedule'));
        } catch (\Throwable $e) {
            return $this->problem(ApiProblem::ValidationError, $e->getMessage(), ['schedule' => 'รูปแบบไม่ถูกต้อง']);
        }

        $name = $this->nameFor($label);
        $jobs = new ScheduledJobRepository($this->app->db());

        if ($jobs->find($name) !== null) {
            return $this->problem(ApiProblem::Conflict, 'มีตารางเวลาชื่อนี้อยู่แล้ว');
        }

        $this->app->db()->insert('scheduled_jobs', [
            'name' => $name,
            'capability' => 'backup.create',
            'args_json' => json_encode($this->argsFrom($request, $type), JSON_UNESCAPED_UNICODE) ?: '{}',
            'schedule' => $schedule,
            'enabled' => 1,
            'created_at' => time(),
            'updated_at' => time(),
        ]);

        $this->audit($request, 'backup.schedule_create', $name, ['schedule' => $schedule, 'type' => $type]);

        return $this->done(
            'เพิ่มตารางเวลาแล้ว',
            [
                ['type' => 'notification', 'level' => 'success', 'message' => 'เพิ่มตารางเวลาแล้ว'],
                ['type' => 'redirect', 'url' => 'reload', 'target' => 'schedules'],
            ],
            ['name' => $name],
            201,
        )->withHeader('Location', '/api/v2/backup-schedules');
    }

    public function update(Request $request): Response
    {
        $row = $this->findOwned($request->paramInt('id'));

        if ($row === null) {
            return $this->problem(ApiProblem::NotFound, 'ไม่พบตารางเวลาที่ระบุ');
        }

        $fields = ['updated_at' => time()];

        if ($request->payload('enabled') !== null) {
            $fields['enabled'] = (int) $request->payload('enabled') === 1 ? 1 : 0;
        }

        if ($request->payloadString('schedule') !== '') {
            try {
                $fields['schedule'] = CronSchedule::normalize($request->payloadString('schedule'));
            } catch (\Throwable $e) {
                return $this->problem(ApiProblem::ValidationError, $e->getMessage(), ['schedule' => 'รูปแบบไม่ถูกต้อง']);
            }
        }

        if ($request->payload('destination_id') !== null) {
            $args = json_decode((string) $row['args_json'], true);
            $args = is_array($args) ? $args : [];
            $args['destination_id'] = (int) $request->payload('destination_id');
            $fields['args_json'] = json_encode($args, JSON_UNESCAPED_UNICODE) ?: '{}';
        }

        if (count($fields) === 1) {
            return $this->problem(ApiProblem::ValidationError, 'ต้องระบุค่าที่ต้องการเปลี่ยนอย่างน้อยหนึ่งค่า');
        }

        $this->app->db()->update('scheduled_jobs', $fields, ['id' => (int) $row['id']]);
        $this->audit($request, 'backup.schedule_update', (string) $row['name'], $fields);

        $result = $this->present($this->app->db()->first(
            'SELECT * FROM scheduled_jobs WHERE id = :id',
            ['id' => (int) $row['id']],
        ) ?? []);

        return $this->completed('บันทึกตารางเวลาแล้ว', 'schedules', is_array($result) ? $result : []);
    }

    public function destroy(Request $request): Response
    {
        $row = $this->findOwned($request->paramInt('id'));

        if ($row === null) {
            return $this->problem(ApiProblem::NotFound, 'ไม่พบตารางเวลาที่ระบุ');
        }

        $this->app->db()->run('DELETE FROM scheduled_jobs WHERE id = :id', ['id' => (int) $row['id']]);
        $this->audit($request, 'backup.schedule_delete', (string) $row['name'], []);

        return $this->completed('ลบตารางเวลาแล้ว', 'schedules', ['id' => (int) $row['id']]);
    }

    /**
     * โหลดแถวที่ทรัพยากรนี้เป็นเจ้าของเท่านั้น
     *
     * ตรวจคำนำหน้าที่นี่จุดเดียว — ทุกเมธอดที่แก้ไขข้อมูลเรียกผ่านตัวนี้ · การส่ง id ของ
     * `rollback.run` มาจึงได้ 404 เหมือนงานที่ไม่มีอยู่จริง ไม่ใช่แก้ได้
     *
     * @return array<string,mixed>|null
     */
    private function findOwned(int $id): ?array
    {
        if ($id < 1) {
            return null;
        }

        $row = $this->app->db()->first('SELECT * FROM scheduled_jobs WHERE id = :id', ['id' => $id]);

        return $row !== null && str_starts_with((string) $row['name'], self::PREFIX) ? $row : null;
    }

    /** @return array<string,mixed> */
    private function argsFrom(Request $request, string $type): array
    {
        return [
            // ชื่อที่ผู้ใช้ตั้ง — เก็บไว้ที่นี่เพราะคอลัมน์ `name` ต้องเป็น ASCII
            // (เป็นกุญแจ UNIQUE ที่ถูกใช้อ้างในหลายที่ รวมทั้ง `phpcp-scheduler --list`)
            'label' => trim($request->payloadString('label')),
            'type' => $type,
            'site_id' => (int) $request->payload('site_id', 0),
            'database' => trim($request->payloadString('database')),
            'note' => 'สำรองอัตโนมัติ',
            'destination_id' => (int) $request->payload('destination_id', 0),
        ];
    }

    /**
     * ชื่องานที่ไม่ชนกันและอ่านออกในรายการของ `phpcp-scheduler --list`
     *
     * ชื่อในตารางเป็น UNIQUE และถูกใช้เป็นกุญแจในหลายที่ จึงจำกัดให้เป็น ASCII —
     * ป้ายชื่อภาษาไทยที่ผู้ใช้ตั้งเก็บไว้ใน args แทน แล้วหน้าจอแสดงจากตรงนั้น
     */
    private function nameFor(string $label): string
    {
        $slug = strtolower((string) preg_replace('/[^A-Za-z0-9]+/', '-', $label));
        $slug = trim($slug, '-');

        return self::PREFIX . ($slug === '' ? bin2hex(random_bytes(4)) : mb_substr($slug, 0, 40));
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function present(array $row): array
    {
        $args = json_decode((string) ($row['args_json'] ?? '{}'), true);
        $args = is_array($args) ? $args : [];

        return [
            'id' => (int) ($row['id'] ?? 0),
            'name' => (string) ($row['name'] ?? ''),
            // ป้ายที่ผู้ใช้ตั้ง · งานเก่าที่ยังไม่มีป้ายให้ถอยไปใช้ชื่องานแทนช่องว่าง
            'label' => (string) ($args['label'] ?? '') ?: (string) ($row['name'] ?? ''),
            'schedule' => (string) ($row['schedule'] ?? ''),
            // คำอธิบายภาษาไทยของตารางเวลา — หน้าจอไม่ต้องแปล cron เอง
            'schedule_label' => CronSchedule::describe((string) ($row['schedule'] ?? '')),
            'type' => (string) ($args['type'] ?? ''),
            'site_id' => (int) ($args['site_id'] ?? 0),
            'destination_id' => (int) ($args['destination_id'] ?? 0),
            'enabled' => (int) ($row['enabled'] ?? 0) === 1,
            // null = ยังไม่เคยรัน — ตัวจัดรูปแบบมาตรฐานของตาราง (data-format="datetime"
            // + data-empty-text) แสดง "—" ให้เองเฉพาะค่า null เท่านั้น ค่า 0 จะถูกตีความ
            // เป็นวันที่จริง (1 ม.ค. 1970) ไม่ใช่ "ยังไม่เคยรัน"
            'last_run_at' => empty($row['last_run_at']) ? null : (int) $row['last_run_at'],
            'last_status' => (string) ($row['last_status'] ?? ''),
            'last_error' => (string) ($row['last_error'] ?? ''),
            // สีของป้ายมาจากฝั่งเซิร์ฟเวอร์ เทมเพลตจึงเขียน `pill-${run_tone}` ได้ตรง ๆ
            'run_status' => match (true) {
                empty($row['last_run_at']) => 'never',
                (string) ($row['last_status'] ?? '') !== 'ok' => 'failed',
                default => 'ok',
            },
            'run_tone' => match (true) {
                empty($row['last_run_at']) => 'muted',
                (string) ($row['last_status'] ?? '') !== 'ok' => 'danger',
                default => 'ok',
            },
        ];
    }

    /**
     * บันทึก audit — ทรัพยากรนี้ไม่ผ่าน Dispatcher จึงไม่มีใครเขียนให้อัตโนมัติ
     *
     * @param array<string,mixed> $detail
     */
    private function audit(Request $request, string $action, string $target, array $detail): void
    {
        $this->app->audit()->write($this->ctx->actor($request), $action, $target, 'ok', $detail);
    }
}
