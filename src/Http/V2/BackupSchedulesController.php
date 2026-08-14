<?php

declare(strict_types=1);

namespace Phpcp\Http\V2;

use Phpcp\Domain\CronSchedule;
use Phpcp\Http\ApiController;
use Phpcp\Http\ApiProblem;
use Phpcp\Kernel\Request;
use Phpcp\Kernel\Response;

/**
 * รอบสำรองอัตโนมัติ — `/api/v2/backup-schedule` · **ตัวเดียวทั้งเครื่อง** (ข้อ B10)
 *
 * ## ทำไมเหลือตัวเดียว
 *
 * ของเดิมเป็น CRUD ของตารางเวลาหลายชุด หนึ่งแถวต่อหนึ่งเว็บหนึ่งชนิด · เครื่องที่มี
 * ลูกค้าห้าสิบรายต้องมีตารางเวลาเป็นร้อยชุดที่ผู้ดูแลสร้างและดูแลเอง แล้ว**เว็บที่สร้าง
 * ใหม่จะไม่ถูกสำรองเลย**จนกว่าจะมีใครนึกได้ว่าต้องไปเพิ่มให้มัน — ความล้มเหลวที่เงียบ
 * จนถึงวันที่ต้องใช้ไฟล์สำรอง
 *
 * ตอนนี้คำถาม "สำรองอะไรบ้าง" ตอบที่สวิตช์รายบัญชี ({@see BackupTargetsController})
 * ส่วนที่นี่เหลือคำถามเดียว: **รอบนั้นเดินตอนไหน**
 *
 * เก็บเป็นแถวในตาราง `scheduled_jobs` เดิม ไม่มีตารางใหม่ · ชื่อคงที่ `backup.auto`
 * และ capability ถูกตรึงไว้ที่ `backup.run` — ผู้เรียกเลือกไม่ได้ ถ้าเลือกได้ก็เท่ากับ
 * ตั้งเวลาให้ระบบรันคำสั่งอะไรก็ได้ในนามของ "ระบบ" ซึ่งเป็นสิทธิ์สูงสุดที่มี
 */
final class BackupSchedulesController extends ApiController
{
    /** ชื่อคงที่ของงานเดียวนั้น — เป็นกุญแจ UNIQUE ในตาราง */
    public const JOB = 'backup.auto';

    /** ค่าเริ่มต้นเมื่อยังไม่เคยตั้ง — ตีหนึ่งของทุกคืน ตอนที่เว็บว่างที่สุด */
    private const DEFAULT_SCHEDULE = '0 1 * * *';

    public function show(Request $request): Response
    {
        return $this->ok($this->present($this->row()), ['presets' => CronSchedule::presets()]);
    }

    /**
     * โครงของฟอร์มตั้งเวลา พร้อมคำสั่งเปิด modal
     *
     * เป็นค่าปัจจุบันเสมอ ไม่ใช่ฟอร์มเปล่า — งานนี้มีตัวเดียวและมีอยู่ตลอด สิ่งที่ผู้ใช้
     * ทำได้คือแก้ค่าของมัน ไม่ใช่สร้างของใหม่
     */
    public function form(Request $request): Response
    {
        return $this->ok(
            $this->present($this->row()),
            ['presets' => CronSchedule::presets()],
            [[
                'type' => 'modal',
                'action' => 'show',
                'template' => 'backup-schedule-form.html',
                'title' => '{LNG_Backup schedule}',
                'titleClass' => 'icon-clock',
            ]],
        );
    }

    public function update(Request $request): Response
    {
        $row = $this->row();
        $fields = ['updated_at' => time()];

        if ($request->payloadString('schedule') !== '') {
            try {
                $fields['schedule'] = CronSchedule::normalize($request->payloadString('schedule'));
            } catch (\Throwable $e) {
                return $this->problem(ApiProblem::ValidationError, $e->getMessage(), ['schedule' => 'Invalid format']);
            }
        }

        if ($request->payload('enabled') !== null) {
            $fields['enabled'] = in_array((string) $request->payload('enabled'), ['1', 'on', 'true', 'yes'], true) ? 1 : 0;
        }

        $args = json_decode((string) ($row['args_json'] ?? '{}'), true);
        $args = is_array($args) ? $args : [];

        foreach (['days', 'keep'] as $key) {
            if ($request->payload($key) !== null) {
                $args[$key] = max(0, (int) $request->payload($key));
            }
        }

        $fields['args_json'] = json_encode($args, JSON_UNESCAPED_UNICODE) ?: '{}';

        if (count($fields) === 2 && $fields['args_json'] === (string) ($row['args_json'] ?? '{}')) {
            return $this->problem(ApiProblem::ValidationError, 'Send at least one value to change');
        }

        $this->app->db()->update('scheduled_jobs', $fields, ['id' => (int) $row['id']]);
        // ทรัพยากรนี้ไม่ผ่าน Dispatcher จึงไม่มีใครเขียน audit ให้อัตโนมัติ
        $this->app->audit()->write($this->ctx->actor($request), 'backup.schedule_update', self::JOB, 'ok', $fields);

        return $this->done(
            'Schedule saved',
            [
                ['type' => 'modal', 'action' => 'close'],
                ['type' => 'notification', 'level' => 'success', 'message' => 'Schedule saved'],
                ['type' => 'redirect', 'url' => 'reload', 'target' => 'backups'],
            ],
            $this->present($this->row()),
        );
    }

    /**
     * เดินรอบเดี๋ยวนี้
     *
     * ผู้ดูแลต้องพิสูจน์ได้ว่าสิ่งที่ตั้งไว้ทำงานจริง **ก่อน**คืนแรก — ไม่ใช่รู้ตอนที่
     * ไฟล์สำรองควรจะมีแล้วแต่ไม่มี · เป็นคำสั่งเดียวกับที่ cron เรียกทุกประการ
     */
    public function runNow(Request $request): Response
    {
        $result = $this->agent()->data('backup.run', [], $this->ctx->actor($request));

        return $this->completed(
            (string) ($result['message'] ?? 'Backup run finished'),
            'backups',
            is_array($result) ? $result : [],
        );
    }

    /**
     * แถวของงานเดียวนั้น — สร้างให้ถ้ายังไม่มี
     *
     * **สร้างตอนอ่านโดยเจตนา** · งานนี้เป็นส่วนหนึ่งของระบบ ไม่ใช่ของที่ผู้ใช้เพิ่มเอง
     * เครื่องที่ติดตั้งก่อน migration นี้จึงต้องได้แถวของมันโดยไม่ต้องให้ใครไปกดสร้าง
     * · ค่าเริ่มต้น `enabled = 0` เพราะการเปิดรอบสำรองให้ทั้งเครื่องโดยที่ผู้ดูแลยังไม่ได้
     * เลือกว่าบัญชีไหนบ้าง แปลว่ารอบแรกจะไม่ทำอะไรเลยอยู่ดี (สวิตช์ทุกบัญชีเริ่มที่ปิด)
     *
     * @return array<string,mixed>
     */
    private function row(): array
    {
        $row = $this->app->db()->first('SELECT * FROM scheduled_jobs WHERE name = :n', ['n' => self::JOB]);

        if ($row !== null) {
            return $row;
        }

        $this->app->db()->insert('scheduled_jobs', [
            'name' => self::JOB,
            'capability' => 'backup.run',
            'args_json' => json_encode(['days' => 30, 'keep' => 7], JSON_UNESCAPED_UNICODE) ?: '{}',
            'schedule' => self::DEFAULT_SCHEDULE,
            'enabled' => 0,
            'created_at' => time(),
            'updated_at' => time(),
        ]);

        return $this->app->db()->first('SELECT * FROM scheduled_jobs WHERE name = :n', ['n' => self::JOB]) ?? [];
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
            'schedule' => (string) ($row['schedule'] ?? self::DEFAULT_SCHEDULE),
            // คำอธิบายภาษาไทยของตารางเวลา — หน้าจอไม่ต้องแปล cron เอง
            'schedule_label' => CronSchedule::describe((string) ($row['schedule'] ?? self::DEFAULT_SCHEDULE)),
            'enabled' => (int) ($row['enabled'] ?? 0) === 1,
            'days' => (int) ($args['days'] ?? 30),
            'keep' => (int) ($args['keep'] ?? 7),
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
            'can_manage' => $this->ctx->can('backup.manage'),
        ];
    }
}
