<?php

declare(strict_types=1);

namespace Phpcp\Agent\Capability;

use Phpcp\Agent\Capability;
use Phpcp\Agent\Context;
use Phpcp\Agent\Executor\Executor;
use Phpcp\Agent\PermissionDenied;
use Phpcp\Agent\ValidationError;
use Phpcp\Domain\CronSchedule;
use Phpcp\Domain\Site;
use Phpcp\Domain\SiteRepository;
use Phpcp\Security\Permissions;
use Phpcp\Support\Validator;

/**
 * เขียนไฟล์ cron ของเว็บไซต์ใหม่ทั้งไฟล์จากข้อมูลในฐานข้อมูลของ panel
 *
 * ใช้รูปแบบเดียวกับ vhost: ฐานข้อมูลของ panel คือความจริง แล้ว generate ไฟล์ตาม
 * ไม่แก้ไฟล์ทีละบรรทัด เพราะการแก้บางส่วนทำให้ไฟล์กับฐานข้อมูลเพี้ยนจากกันได้ง่าย
 *
 * ความปลอดภัย: งาน cron รันในสิทธิ์ของผู้ใช้เว็บไซต์ ไม่ใช่ root — ไฟล์ใน /etc/cron.d
 * ระบุผู้ใช้ไว้ในบรรทัดโดยตรง และคำสั่งถูกตรวจว่าไม่มีอักขระขึ้นบรรทัดใหม่
 * ซึ่งจะแทรกงาน cron ใหม่เข้าไปในไฟล์ได้
 */
final class CronSync implements Capability
{
    /** ไฟล์ cron ของเว็บไซต์ — ชื่อขึ้นต้นด้วย phpcp- เพื่อไม่ทับของผู้ดูแล */
    private const DIR = '/etc/cron.d';

    public static function name(): string
    {
        return 'cron.sync';
    }

    public function permission(): string
    {
        return 'cron.manage';
    }

    public function isMutating(): bool
    {
        return true;
    }

    public function summary(): string
    {
        return 'เขียนไฟล์งานอัตโนมัติของเว็บไซต์ใหม่ตามข้อมูลในระบบ';
    }

    public function validate(array $args): array
    {
        return ['site_id' => Validator::requireInt($args, 'site_id', 1)];
    }

    public function run(array $args, Executor $executor, Context $context): array
    {
        $repository = new SiteRepository($context->db);
        $site = $repository->load($args['site_id']);

        if ($site === null) {
            throw new ValidationError('ไม่พบเว็บไซต์ที่ระบุ');
        }

        $this->assertOwnership($context, $site->id);

        $jobs = $context->db->all(
            'SELECT * FROM cron_jobs WHERE site_id = :id ORDER BY id',
            ['id' => $site->id],
        );

        $path = self::DIR . '/phpcp-' . $site->domain;
        $enabled = array_values(array_filter($jobs, static fn (array $j): bool => (int) $j['enabled'] === 1));

        if ($enabled === []) {
            // ไม่มีงานที่เปิดใช้งาน — ลบไฟล์ทิ้งดีกว่าทิ้งไฟล์ว่างไว้ให้สับสน
            $resolved = $executor->path($path);
            if ($executor->exists($resolved)) {
                $executor->removePath($resolved);
            }

            return [
                'site_id' => $site->id,
                'domain' => $site->domain,
                'file' => $path,
                'jobs' => 0,
                'message' => "ไม่มีงานอัตโนมัติที่เปิดใช้งานสำหรับ {$site->domain} — ลบไฟล์ cron แล้ว",
            ];
        }

        $executor->writeFile($executor->path($path), $this->render($site, $enabled), 0644);

        return [
            'site_id' => $site->id,
            'domain' => $site->domain,
            'file' => $path,
            'jobs' => count($enabled),
            'message' => sprintf('อัปเดตงานอัตโนมัติของ %s แล้ว (%d งาน)', $site->domain, count($enabled)),
        ];
    }

    /**
     * สร้างเนื้อหาไฟล์ cron
     *
     * @param list<array<string,mixed>> $jobs
     */
    private function render(Site $site, array $jobs): string
    {
        $lines = [
            '# ไฟล์นี้สร้างโดย PHP Server Control Panel — ห้ามแก้ด้วยมือ',
            '# การแก้ไขจะถูกเขียนทับเมื่อมีการเปลี่ยนแปลงงานอัตโนมัติผ่านหน้าเว็บ',
            '#',
            '# เว็บไซต์ : ' . $site->domain,
            '# สร้างเมื่อ: ' . date('Y-m-d H:i:s'),
            '',
            'SHELL=/bin/sh',
            'PATH=/usr/local/sbin:/usr/local/bin:/sbin:/bin:/usr/sbin:/usr/bin',
            '',
        ];

        foreach ($jobs as $job) {
            $schedule = CronSchedule::normalize((string) $job['schedule']);
            $command = self::assertCommand((string) $job['command']);

            $lines[] = '# ' . self::assertComment((string) $job['name']);
            // รูปแบบของ /etc/cron.d ต่างจาก crontab ของผู้ใช้ตรงที่มีชื่อผู้ใช้คั่นกลาง
            // งานจึงรันในสิทธิ์ของเว็บไซต์นั้น ไม่ใช่ root
            $lines[] = sprintf('%s %s %s', $schedule, $site->systemUser(), $command);
            $lines[] = '';
        }

        return implode("\n", $lines);
    }

    /**
     * คำสั่งห้ามมีขึ้นบรรทัดใหม่ — ไม่อย่างนั้นแทรกงาน cron ใหม่เข้าไปในไฟล์ได้
     *
     * นี่คือช่องโหว่เดียวกับการแทรก directive ลงไฟล์ config ของ Apache
     * ต่างกันแค่ผลลัพธ์: ที่นี่คือการรันคำสั่งอะไรก็ได้ตามเวลาที่กำหนด
     */
    private static function assertCommand(string $command): string
    {
        $command = trim($command);

        if ($command === '') {
            throw new ValidationError('ต้องระบุคำสั่งที่จะให้ทำงาน');
        }

        if (preg_match('/[\x00-\x1F\x7F]/', $command) === 1) {
            throw new ValidationError('คำสั่งมีอักขระที่ไม่อนุญาต (ขึ้นบรรทัดใหม่หรืออักขระควบคุม)');
        }

        if (mb_strlen($command) > 500) {
            throw new ValidationError('คำสั่งยาวเกิน 500 ตัวอักษร');
        }

        // % ใน crontab มีความหมายพิเศษ (คั่น stdin) ต้อง escape ไม่อย่างนั้นคำสั่งจะถูกตัด
        return str_replace('%', '\\%', $command);
    }

    private static function assertComment(string $name): string
    {
        return preg_replace('/[\x00-\x1F\x7F]/', '', trim($name)) ?: 'งานอัตโนมัติ';
    }

    private function assertOwnership(Context $context, int $siteId): void
    {
        $actor = $context->actor;

        if ($actor->userId === 0
            || in_array($actor->role, [Permissions::SUPERADMIN, Permissions::SYSADMIN], true)) {
            return;
        }

        $owned = (int) $context->db->value(
            'SELECT count(*) FROM sites WHERE id = :id AND owner_user_id = :u',
            ['id' => $siteId, 'u' => $actor->userId],
            0,
        );

        if ($owned === 0) {
            throw new PermissionDenied('คุณไม่มีสิทธิ์จัดการงานอัตโนมัติของเว็บไซต์นี้');
        }
    }
}
