<?php

declare(strict_types=1);

namespace Phpcp\Domain;

use Phpcp\Kernel\Db;

/**
 * งานตามเวลาระดับระบบ — ตาราง scheduled_jobs
 *
 * ต่างจาก cron_jobs ตรงที่ cron_jobs เป็นของ "เว็บไซต์ของลูกค้า" และไปจบที่ crontab ของระบบ
 * ส่วนตารางนี้เป็นงานของตัว panel เอง ที่ bin/phpcp-scheduler อ่านแล้วสั่งผ่าน agent
 *
 * รายการตั้งต้นอยู่ในโค้ดไม่ใช่ในไฟล์ migration เพราะต้องเติมให้เครื่องที่ติดตั้งไปแล้วด้วย
 * ทุกครั้งที่อัปเดต (install.sh เรียก `phpcp db:migrate` ทุกรอบ) — ถ้าอยู่ใน migration
 * เครื่องที่ผ่าน migration นั้นไปแล้วจะไม่มีวันได้งานที่เพิ่มเข้ามาทีหลังเลย
 */
final class ScheduledJobRepository
{
    /**
     * งานที่ระบบต้องมีเสมอ — ชื่อซ้ำจะไม่ถูกเขียนทับ ผู้ดูแลจึงปรับเวลาเองได้โดยไม่ถูกย้อน
     *
     * @var list<array{name:string,capability:string,schedule:string,args:array<string,mixed>}>
     */
    public const DEFAULTS = [
        // ทุกนาที — กลไกคืนค่าอัตโนมัติต้องทำงานได้แม้ไม่มีใครเปิดหน้าเว็บ
        // นี่คือกรณีที่กลไกนี้ถูกออกแบบมาเพื่อรับมือโดยเฉพาะ (ผู้ดูแลหลุดการเชื่อมต่อไปแล้ว)
        ['name' => 'rollback.run', 'capability' => 'rollback.run', 'schedule' => '* * * * *', 'args' => []],

        // ทุกวันตี 3 — แจ้งเตือนล่วงหน้าและเปลี่ยนสถานะลูกค้าที่หมดอายุ
        ['name' => 'expiry.check', 'capability' => 'expiry.check', 'schedule' => '0 3 * * *', 'args' => []],

        // ทุก 15 นาที ตาม ARCHITECTURE §14
        ['name' => 'disk.usage', 'capability' => 'disk.usage', 'schedule' => '*/15 * * * *', 'args' => []],

        // ทุกนาที — ความละเอียดของชั้นล่างสุดคือ 1 นาที ถ้าเก็บถี่กว่านี้ค่าจะถูกเฉลี่ยรวม
        // เข้าแถวเดิมอยู่ดี · ถ้าห่างกว่านี้จะมีช่วงที่ไม่มีข้อมูลเลยในกราฟ 24 ชั่วโมง
        // (PLAN-V2 เฟส E6) · งานนี้เบามาก: อ่าน /proc แล้วเขียนหนึ่งแถว
        ['name' => 'metrics.record', 'capability' => 'metrics.record', 'schedule' => '* * * * *', 'args' => []],

        // ทุกชั่วโมง — วัดพื้นที่ดิสก์ระดับบัญชี (ทั้งบ้าน ไม่ใช่รายเว็บ) แล้วแจ้งเตือน
        // ที่ 80/90/100% · เบากว่า disk.usage ต่อครั้งแต่ยังเป็นการเดินทั้งต้นไม้ไฟล์
        // ของบัญชี จึงไม่ตั้งถี่เท่า (PLAN-V2 เฟส E2)
        ['name' => 'quota.disk_check', 'capability' => 'quota.disk_check', 'schedule' => '0 * * * *', 'args' => []],

        // ทุก 5 นาที — ตรวจเกณฑ์เตือนของเครื่อง (ดิสก์ แรม โหลด บริการ ใบรับรอง)
        // ถี่พอให้รู้เร็วเมื่อบริการสำคัญล่ม แต่ไม่ถี่จนเปลืองเพราะต้องถาม systemctl ทีละบริการ
        // · การกันข้อความซ้ำอยู่ที่ `AlertRules` ไม่ใช่ที่ความถี่ของงานนี้ (PLAN-V2 เฟส E6)
        ['name' => 'alert.check', 'capability' => 'alert.check', 'schedule' => '*/5 * * * *', 'args' => []],

        // ทุกวันตี 4 ครึ่ง — หลัง timer ของ certbot ที่มักทำงานช่วงเช้ามืด
        ['name' => 'cert.sync', 'capability' => 'cert.sync', 'schedule' => '30 4 * * *', 'args' => []],

        // ทุกวันตี 5 — หลังงานสำรองที่ผู้ดูแลมักตั้งไว้ช่วงดึก จึงเก็บกวาดของรอบนั้นได้เลย
        // ตัวเก็บกวาดจะไม่ลบไฟล์ที่ยังไม่มีสำเนานอกเครื่อง (ดู BackupPrune)
        ['name' => 'backup.prune', 'capability' => 'backup.prune', 'schedule' => '0 5 * * *', 'args' => []],
    ];

    public function __construct(private readonly Db $db)
    {
    }

    /** @return list<array<string,mixed>> */
    public function all(): array
    {
        return $this->db->all('SELECT * FROM scheduled_jobs ORDER BY name');
    }

    /** @return list<array<string,mixed>> */
    public function enabled(): array
    {
        return $this->db->all('SELECT * FROM scheduled_jobs WHERE enabled = 1 ORDER BY name');
    }

    /** @return array<string,mixed>|null */
    public function find(string $name): ?array
    {
        return $this->db->first('SELECT * FROM scheduled_jobs WHERE name = :name', ['name' => $name]);
    }

    /**
     * เติมงานตั้งต้นที่ยังไม่มี — ปลอดภัยที่จะเรียกซ้ำกี่ครั้งก็ได้
     *
     * @return list<string> ชื่องานที่เพิ่งถูกเพิ่ม
     */
    public function installDefaults(): array
    {
        $added = [];
        $now = time();

        foreach (self::DEFAULTS as $job) {
            if ($this->find($job['name']) !== null) {
                continue;
            }

            $this->db->insert('scheduled_jobs', [
                'name' => $job['name'],
                'capability' => $job['capability'],
                'args_json' => json_encode($job['args'], JSON_UNESCAPED_UNICODE) ?: '{}',
                'schedule' => CronSchedule::normalize($job['schedule']),
                'enabled' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $added[] = $job['name'];
        }

        return $added;
    }

    /** บันทึกผลการรันหนึ่งรอบ — last_error ว่างเสมอเมื่อสำเร็จ ไม่ปล่อยข้อความเก่าค้าง */
    public function recordRun(int $id, string $status, string $error = '', ?int $ranAt = null): void
    {
        $this->db->update('scheduled_jobs', [
            'last_run_at' => $ranAt ?? time(),
            'last_status' => $status,
            'last_error' => $error === '' ? null : mb_substr($error, 0, 500),
            'updated_at' => time(),
        ], ['id' => $id]);
    }

    public function setEnabled(string $name, bool $enabled): bool
    {
        return $this->db->update(
            'scheduled_jobs',
            ['enabled' => $enabled ? 1 : 0, 'updated_at' => time()],
            ['name' => $name],
        ) > 0;
    }

    /**
     * รอบล่าสุดที่ scheduler แตะตารางนี้ ไม่ว่างานไหน
     *
     * ใช้เป็นชีพจรของตัว scheduler เอง: ถ้าค่านี้ค้างเกินไม่กี่นาที แปลว่า timer ไม่ทำงาน
     * ซึ่งอันตรายกว่างานใดงานหนึ่งล้มเหลว เพราะ auto-rollback จะไม่มีใครกระตุ้นเลย
     */
    public function lastRunAt(): ?int
    {
        $value = $this->db->value('SELECT max(last_run_at) FROM scheduled_jobs');

        return $value === null ? null : (int) $value;
    }

    /** @return list<array<string,mixed>> งานที่รอบล่าสุดล้มเหลว */
    public function failing(): array
    {
        return $this->db->all("SELECT * FROM scheduled_jobs WHERE last_status = 'error' ORDER BY name");
    }
}
