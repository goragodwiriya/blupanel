<?php

declare(strict_types=1);

namespace Phpcp\Agent\Capability;

use Phpcp\Agent\Capability;
use Phpcp\Agent\Context;
use Phpcp\Agent\Executor\Executor;
use Phpcp\Domain\SettingsRepository;
use Phpcp\Driver\Security\Fail2banManager;
use Phpcp\Support\Validator;

/**
 * เปิด/ปิดการกันเดารหัสผ่านของหน้าเข้าสู่ระบบ — บังคับใช้ด้วย fail2ban
 *
 * **ทำไมต้องมีทั้งที่แอปล็อกบัญชีอยู่แล้ว** — การล็อกบัญชีกันได้ทีละบัญชี คนที่ไล่เดา
 * รหัสของ `admin`, `root`, `administrator` สลับกันไปจึงไม่เคยชนเพดานของบัญชีไหนเลย
 * และทุกครั้งที่ลองยังกิน worker ของ PHP-FPM ที่มีอยู่สี่ตัว · การแบนที่ firewall
 * ตัดตั้งแต่ก่อนถึง PHP
 *
 * **เขียนไฟล์ก่อน บันทึกค่าทีหลังเสมอ** — สลับลำดับเมื่อไร หน้าจอจะรายงานว่าเปิด
 * ป้องกันไว้แล้วทั้งที่ fail2ban ปฏิเสธไฟล์ไป ซึ่งอันตรายกว่าไม่มีฟีเจอร์นี้
 */
final class PanelJailSet implements Capability
{
    public static function name(): string
    {
        return 'security.panel_jail_set';
    }

    public function permission(): string
    {
        return 'security.manage';
    }

    public function isMutating(): bool
    {
        return true;
    }

    public function summary(): string
    {
        return 'ตั้งค่าการกันเดารหัสผ่านหน้าเข้าสู่ระบบ';
    }

    public function validate(array $args): array
    {
        $enabled = Validator::requireBool($args, 'enabled');

        // ปิดแล้วไม่ต้องกรอกค่าที่กำลังจะไม่ถูกใช้
        if (!$enabled) {
            return ['enabled' => false];
        }

        return [
            'enabled' => true,
            /*
             * ขั้นต่ำ 3 ครั้ง — ต่ำกว่านั้นคนที่พิมพ์รหัสผิดสองครั้งแล้วเปิด Caps Lock
             * ค้างไว้จะโดนตัดขาดจากหน้าจัดการของตัวเอง ซึ่งเป็นการล็อกเจ้าของออกจากบ้าน
             * เพื่อกันขโมยที่ยังไม่มา · เพดาน 100 ไว้ให้เครื่องที่มีคนใช้หลายคนจริง ๆ
             */
            'max_retry' => Validator::requireInt($args, 'max_retry', 3, 100),
            'find_seconds' => Validator::requireInt($args, 'find_seconds', 60, 86_400),
            'ban_seconds' => Validator::requireInt($args, 'ban_seconds', 60, 604_800),
            'ignore_ips' => Fail2banManager::normalizeIgnoreList(
                Validator::optionalString($args, 'ignore_ips', '', 2000),
            ),
        ];
    }

    public function run(array $args, Executor $executor, Context $context): array
    {
        $settings = new SettingsRepository($context->db);
        $manager = new Fail2banManager($executor);

        if (!$args['enabled']) {
            $manager->removePanelLogin();
            $settings->save(['security.panel_jail.enabled' => '0']);

            return [
                'enabled' => false,
                'active' => false,
                'message' => 'ปิดการกันเดารหัสผ่านหน้าเข้าสู่ระบบแล้ว',
            ];
        }

        // สำเนา audit log แบบข้อความคือแหล่งเดียวที่ fail2ban อ่านได้ — ตาราง audit_log
        // เป็น SQLite ซึ่งมันอ่านไม่เป็น (ดู AuditLog::mirror)
        $auditLog = $context->config->paths->logFile('audit');

        $settingsToWrite = [
            'max_retry' => $args['max_retry'],
            'find_seconds' => $args['find_seconds'],
            'ban_seconds' => $args['ban_seconds'],
            'ignore_ips' => $args['ignore_ips'],
        ];

        // ไฟล์ก่อน ค่าทีหลัง — บรรทัดนี้โยน exception แล้วจะไม่มีค่าที่บอกว่า "เปิดอยู่"
        $manager->applyPanelLogin($auditLog, $settingsToWrite);

        $settings->save([
            'security.panel_jail.enabled' => '1',
            'security.panel_jail.max_retry' => (string) $args['max_retry'],
            'security.panel_jail.find_seconds' => (string) $args['find_seconds'],
            'security.panel_jail.ban_seconds' => (string) $args['ban_seconds'],
            'security.panel_jail.ignore_ips' => $args['ignore_ips'],
        ]);

        return [
            'enabled' => true,
            'jail' => Fail2banManager::PANEL_LOGIN_JAIL,
            'log_path' => $auditLog,
            'status' => $manager->statusOf(Fail2banManager::PANEL_LOGIN_JAIL),
            'message' => sprintf(
                'เปิดแล้ว — ล็อกอินผิด %d ครั้งใน %d นาที จะถูกแบน %d นาที',
                $args['max_retry'],
                (int) round($args['find_seconds'] / 60),
                (int) round($args['ban_seconds'] / 60),
            ),
        ];
    }
}
