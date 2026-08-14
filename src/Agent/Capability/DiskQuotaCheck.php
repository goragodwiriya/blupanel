<?php

declare(strict_types=1);

namespace Phpcp\Agent\Capability;

use Phpcp\Agent\Capability;
use Phpcp\Agent\Context;
use Phpcp\Agent\Executor\Executor;
use Phpcp\Domain\Notifier;
use Phpcp\Domain\Quota;
use Phpcp\Domain\UserAccount;
use Phpcp\Domain\UserRepository;

/**
 * วัดพื้นที่ดิสก์ที่แต่ละบัญชีโฮสติ้งใช้จริง แล้วแจ้งเตือนเมื่อใกล้เต็มโควตา (PLAN-V2 เฟส E2)
 *
 * **ทำไมวัดที่ระดับ "บัญชี" ไม่ใช่ "เว็บไซต์" เหมือน `disk.usage`:** ตั้งแต่ migration 0006
 * โควตาดิสก์ย้ายไปอยู่กับ `users` แล้ว (หนึ่งบัญชี = หนึ่งบ้าน = หลายเว็บ) และบ้านของบัญชี
 * มีไฟล์ที่ไม่ได้อยู่ใต้ docroot ของเว็บใดเว็บหนึ่งด้วย (`tmp/`, `logs/`, `.ssh/`) การ `du`
 * ทั้งบ้านครั้งเดียวจึงตรงกับตัวเลขที่โควตาจริง ๆ ตั้งใจจำกัดมากกว่าการรวมเลขจากรายเว็บ
 *
 * **ด่านที่บังคับได้จริงในเฟสนี้:** ตัวเลข `disk_used_mb` ที่คลาสนี้เขียน ถูก
 * {@see \Phpcp\Domain\QuotaChecker::checkOwnerCanCreate()} ใช้กันการ**สร้างทรัพยากรใหม่**
 * ผ่าน panel เมื่อเต็ม — เป็นการบังคับใช้ทางแอปพลิเคชัน ไม่ใช่การบังคับที่ระดับ filesystem
 * จริง (XFS/ext4 project quota) ซึ่งจะบล็อกการเขียนไฟล์ได้ทุกจุดรวมถึงจากโค้ดของลูกค้าเอง
 * ทางนั้นยังไม่ได้ทำในเฟสนี้ — ดู "ที่ยังเหลือ" ของเฟส E2 ใน PLAN-V2.md ว่าทำไม
 *
 * **การแจ้งเตือนไม่สแปม:** เก็บ "ระดับล่าสุดที่แจ้งไปแล้ว" ต่อบัญชีไว้ที่
 * `disk_quota_state` (ผ่าน {@see UserRepository::recordDiskQuotaThreshold()}) แจ้งเฉพาะ
 * ตอนระดับ**สูงขึ้น**กว่าที่แจ้งไปแล้วเท่านั้น ต่างจาก `expiry_notifications` ที่แจ้งครั้งเดียว
 * ต่อค่าตายตัวแล้วจบ เพราะการใช้ดิสก์ขึ้นลงได้ตลอดเวลาไม่เหมือนวันหมดอายุ
 */
final class DiskQuotaCheck implements Capability
{
    private const DU = '/usr/bin/du';

    /** บ้านของบัญชีที่มีหลายเว็บอาจใหญ่ ต้องมีเพดานกันงานรอบถัดไปซ้อนคิว */
    private const TIMEOUT = 120;

    public static function name(): string
    {
        return 'quota.disk_check';
    }

    public function permission(): string
    {
        return 'customer.view';
    }

    public function isMutating(): bool
    {
        return true;
    }

    public function summary(): string
    {
        return 'วัดพื้นที่ดิสก์ของบัญชีโฮสติ้งและแจ้งเตือนเมื่อใกล้เต็มโควตา';
    }

    public function validate(array $args): array
    {
        return [];
    }

    public function run(array $args, Executor $executor, Context $context): array
    {
        $users = new UserRepository($context->db);
        $notifier = new Notifier($context->db);

        $results = [
            'checked' => 0,
            'measured' => 0,
            'failed' => 0,
            'notified' => 0,
            'accounts' => [],
        ];

        foreach ($users->hostingAccounts() as $row) {
            // บัญชีที่ยังไม่เคยมีเว็บยังไม่มีบ้านให้วัด (สร้างแบบ lazy ตอนสร้างเว็บแรก)
            if (($row['system_user'] ?? null) === null) {
                continue;
            }

            $results['checked']++;
            $userId = (int) $row['id'];
            $account = UserAccount::fromRow($row);

            try {
                $usedMb = $this->measure($executor, $account);
            } catch (\Throwable $e) {
                // บัญชีเดียวที่วัดไม่ได้ (โฟลเดอร์หาย, ผู้ใช้ระบบถูกลบ) ต้องไม่ทำให้ทั้งรอบล้ม
                $results['failed']++;
                $results['accounts'][] = [
                    'user_id' => $userId,
                    'username' => $row['username'],
                    'error' => $e->getMessage(),
                ];

                continue;
            }

            $context->db->update('users', ['disk_used_mb' => $usedMb, 'updated_at' => time()], ['id' => $userId]);
            $results['measured']++;

            // disk_quota_mb เป็น NULL ได้สำหรับบัญชีที่สร้างก่อนคอลัมน์นี้มีค่าเริ่มต้น
            // (ALTER TABLE ADD COLUMN ไม่มี DEFAULT) — ต้องตีความเป็น "ไม่จำกัด" เหมือนที่
            // QuotaChecker::diskQuotaExceeded() ทำ ไม่ใช่ปล่อยให้ (int) null กลายเป็น 0
            // ซึ่งจะทำให้ทุกบัญชีที่ไม่เคยตั้งโควตาโดนแจ้งเตือน "เต็ม 100%" ทันที
            $quotaMb = (int) ($row['disk_quota_mb'] ?? Quota::UNLIMITED);
            $threshold = $this->thresholdFor($usedMb, $quotaMb);
            $previous = $users->diskQuotaThreshold($userId);

            $entry = [
                'user_id' => $userId,
                'username' => $row['username'],
                'disk_used_mb' => $usedMb,
                'disk_quota_mb' => $quotaMb,
                'threshold' => $threshold,
            ];

            // แจ้งเฉพาะตอนระดับสูงขึ้นกว่ารอบก่อน — กลับมาต่ำกว่า 80% ไม่ต้องแจ้ง (ไม่มีอะไรต้องทำ)
            if ($threshold > 0 && $threshold > $previous) {
                $entry['notified'] = $notifier->send(
                    'quota',
                    sprintf('โควตาดิสก์ของ %s ถึง %d%%', $row['username'], $threshold),
                    sprintf(
                        "บัญชี: %s (%s)\nใช้ไป: %d MB จาก %d MB (%d%%)\n\n%s",
                        (string) $row['username'],
                        $row['username'],
                        $usedMb,
                        $quotaMb,
                        $threshold,
                        $threshold >= 100
                            ? 'เต็มแล้ว — บัญชีนี้สร้างทรัพยากรใหม่ผ่าน panel ไม่ได้อีกจนกว่าจะลบไฟล์หรือขยายโควตา '
                              . '(หมายเหตุ: ยังเขียนไฟล์เดิมต่อได้ตามปกติ เพราะเฟสนี้ยังไม่ได้บังคับที่ระดับ filesystem จริง)'
                            : 'ใกล้เต็ม — แจ้งล่วงหน้าก่อนถึง 100%',
                    ),
                    $threshold >= 100 ? 'danger' : 'warn',
                );
                $results['notified'] += $entry['notified'] ? 1 : 0;
            }

            $users->recordDiskQuotaThreshold($userId, $threshold);
            $results['accounts'][] = $entry;
        }

        $results['message'] = sprintf(
            'วัดพื้นที่ดิสก์ %d บัญชี · แจ้งเตือน %d บัญชี',
            $results['measured'],
            $results['notified'],
        ) . ($results['failed'] > 0 ? sprintf(' · วัดไม่ได้ %d บัญชี', $results['failed']) : '');

        return $results;
    }

    /** ระดับที่ใช้ไปแล้วเทียบกับโควตา — 0 = ยังไม่ถึง 80% หรือไม่จำกัด */
    private function thresholdFor(int $usedMb, int $quotaMb): int
    {
        if (Quota::isUnlimited($quotaMb)) {
            return 0;
        }

        // โควตา 0 MB = ไม่มีที่ว่างให้เลย ถือว่าเต็มทันทีที่มีการใช้พื้นที่ใด ๆ
        if ($quotaMb <= 0) {
            return $usedMb > 0 ? 100 : 0;
        }

        $percent = ($usedMb / $quotaMb) * 100;

        return match (true) {
            $percent >= 100 => 100,
            $percent >= 90 => 90,
            $percent >= 80 => 80,
            default => 0,
        };
    }

    /**
     * ขนาดรวมของบ้านบัญชีเป็น MB
     *
     * เดินไฟล์ด้วยสิทธิ์ของเจ้าของบัญชีตาม ARCHITECTURE §4.4 เหมือนกับที่ `DiskUsage`
     * ทำกับเว็บไซต์ — root ไม่ต้องเดินเข้าไปในต้นไม้ไฟล์ที่ผู้ใช้ควบคุมได้เอง
     */
    private function measure(Executor $executor, UserAccount $account): int
    {
        $path = $executor->path($account->home());

        if (!$executor->exists($path)) {
            throw new \RuntimeException('ไม่พบไดเรกทอรีบ้านของบัญชีนี้');
        }

        $result = $executor->asUser($account->username, static function () use ($executor, $path): array {
            // -s สรุปยอดเดียว · -k เป็นกิโลไบต์ · -x ไม่ข้าม filesystem
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
