<?php

declare(strict_types=1);

namespace Phpcp\Agent\Capability;

use Phpcp\Agent\Capability;
use Phpcp\Agent\Context;
use Phpcp\Agent\Executor\Executor;
use Phpcp\Domain\UserRepository;

/**
 * ตรวจสอบวันหมดอายุของบัญชีโฮสติ้งและส่งการแจ้งเตือน
 *
 * ใช้เป็น cron job ตรวจสอบบัญชีที่:
 * - จะหมดอายุใน 30, 7, 1 วันข้างหน้า
 * - หมดอายุแล้ว
 *
 * ทำงาน:
 * - บันทึกการแจ้งเตือนลง expiry_notifications
 * - เปลี่ยนสถานะลูกค้าเป็น 'expired' ถ้าหมดอายุแล้ว
 */
final class ExpiryCheck implements Capability
{
    public static function name(): string
    {
        return 'expiry.check';
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
        return 'ตรวจสอบวันหมดอายุของลูกค้า';
    }

    public function validate(array $args): array
    {
        return [];
    }

    public function run(array $args, Executor $executor, Context $context): array
    {
        $users = new UserRepository($context->db);

        $now = time();
        $results = [
            'checked' => 0,
            'notified' => 0,
            'expired' => 0,
            'notifications' => [],
        ];

        // แจ้งล่วงหน้าสามช่วง — บัญชีเดียวอาจเข้าเกณฑ์หลายช่วงพร้อมกัน (เหลือ 1 วัน
        // ก็ยังเข้าเกณฑ์ 7 และ 30 ด้วย) จึงต้องยุบให้เหลือช่วงที่ใกล้ที่สุดช่วงเดียว
        // ไม่งั้นลูกค้าคนเดียวจะได้อีเมลสามฉบับในวันเดียวกัน
        $due = [];
        foreach ([30, 7, 1] as $daysBefore) {
            foreach ($users->findExpiring($daysBefore) as $user) {
                $due[(int) $user['id']] = $daysBefore;
            }
        }

        $results['checked'] = count($due);

        foreach ($due as $userId => $daysBefore) {
            if ($users->recordExpiryNotification($userId, $daysBefore)) {
                $results['notifications'][] = [
                    'user_id' => $userId,
                    'days_before' => $daysBefore,
                    'action' => 'notified',
                ];
                $results['notified']++;
            }
        }

        // บัญชีที่เลยวันหมดอายุไปแล้ว — ปิดบริการ แต่ไม่แตะสิทธิ์ล็อกอิน (คนละแกนกัน)
        foreach ($users->hostingAccounts() as $user) {
            $expiry = $user['expiry_at'];

            if ($user['service_status'] !== 'active' || $expiry === null || (int) $expiry >= $now) {
                continue;
            }

            $users->setServiceStatus((int) $user['id'], 'expired');

            $results['notifications'][] = [
                'user_id' => (int) $user['id'],
                'days_before' => 0,
                'action' => 'expired',
            ];
            $results['expired']++;
        }

        // audit log บันทึกโดย Dispatcher ให้แล้วรอบ run() ทุกคำสั่ง (ARCHITECTURE §4.1)
        return $results;
    }
}
