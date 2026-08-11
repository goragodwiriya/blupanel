<?php

declare(strict_types=1);

namespace Phpcp\Agent\Capability;

use Phpcp\Agent\Capability;
use Phpcp\Agent\Context;
use Phpcp\Agent\Executor\Executor;
use Phpcp\Agent\ValidationError;
use Phpcp\Domain\Site;
use Phpcp\Domain\SiteRepository;

/**
 * วัดพื้นที่ดิสก์ที่แต่ละเว็บไซต์ใช้จริง แล้วเก็บลง sites.disk_used_mb
 *
 * คอลัมน์นี้มีมาตั้งแต่ migration แรกแต่ไม่เคยมีใครคำนวณให้ — ตัวเลขบนหน้าจอจึงเป็น 0
 * เสมอบนเครื่องจริง และโควตาดิสก์ (เฟส E2) จะบังคับใช้ไม่ได้เลยถ้าไม่มีค่านี้
 *
 * ทำเครื่องหมายว่า "อ่านอย่างเดียว" โดยเจตนา: มันไม่เปลี่ยนอะไรบนเครื่องเลย
 * สิ่งที่เขียนคือค่าที่วัดได้ลงตารางของ panel เอง ไม่ใช่การกระทำที่ต้องย้อนกลับหรือสอบสวน
 * ผลพลอยได้ที่สำคัญคือมันไม่เติม audit log ทุก 15 นาทีตลอดไป และได้ Executor จริง
 * ในโหมด dryrun จึงยังวัดค่าได้ถูกต้อง
 */
final class DiskUsage implements Capability
{
    private const DU = '/usr/bin/du';

    /** เว็บใหญ่ ๆ ใช้เวลานาน แต่ต้องมีเพดาน ไม่งั้นงานรอบถัดไปซ้อนกันจนคิวยาว */
    private const TIMEOUT = 120;

    public static function name(): string
    {
        return 'disk.usage';
    }

    public function permission(): string
    {
        return 'site.view';
    }

    public function isMutating(): bool
    {
        return false;
    }

    public function summary(): string
    {
        return 'คำนวณพื้นที่ดิสก์ที่เว็บไซต์ใช้';
    }

    public function validate(array $args): array
    {
        if (!isset($args['site_id']) || $args['site_id'] === '' || $args['site_id'] === null) {
            return [];
        }

        if (!is_int($args['site_id']) && !(is_string($args['site_id']) && ctype_digit($args['site_id']))) {
            throw new ValidationError('รหัสเว็บไซต์ต้องเป็นตัวเลข');
        }

        return ['site_id' => (int) $args['site_id']];
    }

    public function run(array $args, Executor $executor, Context $context): array
    {
        $repository = new SiteRepository($context->db);

        // ตั้งแต่ migration 0006 โควตาดิสก์ย้ายไปอยู่กับ users แล้ว — sites ไม่มีคอลัมน์
        // disk_quota_mb อีกต่อไป (เดิม query นี้อ้างคอลัมน์ที่ไม่มีอยู่จริง ทำให้ SQL error
        // ทุกครั้งที่ scheduler เรียก จับได้ตอนเขียนเฟส E2 ที่ต้องพึ่งเลข disk_used_mb นี้)
        $rows = isset($args['site_id'])
            ? $context->db->all('SELECT id FROM sites WHERE id = :id', ['id' => $args['site_id']])
            : $context->db->all('SELECT id FROM sites ORDER BY id');

        $sites = [];
        $failed = 0;
        $totalMb = 0;

        foreach ($rows as $row) {
            $site = $repository->load((int) $row['id']);

            if ($site === null) {
                continue;
            }

            try {
                $usedMb = $this->measure($executor, $site);
            } catch (\Throwable $e) {
                // เว็บเดียวที่วัดไม่ได้ (โฟลเดอร์หาย, ผู้ใช้ระบบถูกลบ) ต้องไม่ทำให้ทั้งรอบล้ม
                $failed++;
                $sites[] = [
                    'site_id' => $site->id,
                    'domain' => $site->domain,
                    'error' => $e->getMessage(),
                ];

                continue;
            }

            $context->db->update(
                'sites',
                ['disk_used_mb' => $usedMb, 'updated_at' => time()],
                ['id' => $site->id],
            );

            $totalMb += $usedMb;
            $sites[] = [
                'site_id' => $site->id,
                'domain' => $site->domain,
                'disk_used_mb' => $usedMb,
            ];
        }

        $measured = count($sites) - $failed;

        return [
            'sites' => $sites,
            'measured' => $measured,
            'failed' => $failed,
            'total_mb' => $totalMb,
            // เว็บที่วัดไม่ได้ต้องอยู่ในข้อความสรุป ไม่ใช่ซ่อนอยู่ในรายการย่อยที่ไม่มีใครเปิดดู —
            // โฟลเดอร์เว็บไซต์ที่หายไปคือปัญหาจริงที่ต้องมีคนเห็น ไม่ใช่แค่ตัวเลขที่ขาดไป
            'message' => $failed === 0
                ? sprintf('วัดพื้นที่ดิสก์ %d เว็บไซต์ รวม %d MB', $measured, $totalMb)
                : sprintf('วัดพื้นที่ดิสก์ %d เว็บไซต์ รวม %d MB · วัดไม่ได้ %d เว็บไซต์', $measured, $totalMb, $failed),
        ];
    }

    /**
     * ขนาดรวมของบ้านเว็บไซต์เป็น MB
     *
     * เดินไฟล์ด้วยสิทธิ์ของเจ้าของเว็บไซต์ตาม ARCHITECTURE §4.4 — root ไม่ต้องเดินเข้าไป
     * ในต้นไม้ไฟล์ที่ผู้ใช้ควบคุมได้เอง และ `du` ไม่เดินตาม symlink อยู่แล้วโดยค่าเริ่มต้น
     * จึงหลอกให้ไปนับ /var หรือ /home ของคนอื่นด้วยลิงก์ไม่ได้
     */
    private function measure(Executor $executor, Site $site): int
    {
        $path = $executor->path($site->root());

        if (!$executor->exists($path)) {
            throw new \RuntimeException('ไม่พบไดเรกทอรีของเว็บไซต์');
        }

        $result = $executor->asUser($site->systemUser(), static function () use ($executor, $path): array {
            // -s สรุปยอดเดียว · -k เป็นกิโลไบต์ (ทุกดิสทริบิวชันเหมือนกัน ไม่ต้องเดา block size)
            // -x ไม่ข้าม filesystem — ถ้ามีการ mount อะไรไว้ข้างใน จะไม่นับซ้ำเข้ามา
            $exec = $executor->exec([self::DU, '-sk', '-x', '--', $path], timeout: self::TIMEOUT);

            return ['ok' => $exec->ok(), 'out' => $exec->output(), 'err' => trim($exec->stderr)];
        });

        if (($result['ok'] ?? false) !== true) {
            throw new \RuntimeException(mb_substr((string) ($result['err'] ?? 'วัดขนาดไม่สำเร็จ'), 0, 200));
        }

        $out = (string) ($result['out'] ?? '');

        if (preg_match('/^(\d+)/', $out, $m) !== 1) {
            throw new \RuntimeException('อ่านผลลัพธ์ของ du ไม่ได้');
        }

        return (int) ceil(((int) $m[1]) / 1024);
    }
}
