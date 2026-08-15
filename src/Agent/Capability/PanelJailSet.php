<?php

declare(strict_types=1);

namespace Phpcp\Agent\Capability;

use Phpcp\Agent\Capability;
use Phpcp\Agent\Context;
use Phpcp\Agent\Executor\Executor;
use Phpcp\Agent\ValidationError;
use Phpcp\Domain\SettingsRepository;
use Phpcp\Driver\Security\Fail2banManager;
use Phpcp\Middleware\RateLimit;
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
        /*
         * `mode` แทน `enabled` แบบเปิด/ปิด — สามสถานะ off | notify | ban
         *
         * ยังรับ `enabled` แบบเดิมด้วยเพื่อไม่ให้ผู้เรียกเก่าพัง: true = ban เพราะ
         * นั่นคือความหมายเดียวที่ "เปิด" เคยมี
         */
        $mode = Validator::optionalString($args, 'mode', '', 16);

        if ($mode === '') {
            $mode = Validator::requireBool($args, 'enabled')
                ? Fail2banManager::MODE_BAN
                : Fail2banManager::MODE_OFF;
        }

        if (!in_array($mode, Fail2banManager::modes(), true)) {
            throw new ValidationError('โหมดของ jail ไม่ถูกต้อง — ต้องเป็น off, notify หรือ ban');
        }

        // ปิดแล้วไม่ต้องกรอกค่าที่กำลังจะไม่ถูกใช้
        if ($mode === Fail2banManager::MODE_OFF) {
            return ['mode' => Fail2banManager::MODE_OFF, 'enabled' => false];
        }

        $findSeconds = Validator::requireInt($args, 'find_seconds', 60, 86_400);

        /*
         * ขั้นต่ำ 3 ครั้ง — ต่ำกว่านั้นคนที่พิมพ์รหัสผิดสองครั้งแล้วเปิด Caps Lock
         * ค้างไว้จะโดนตัดขาดจากหน้าจัดการของตัวเอง ซึ่งเป็นการล็อกเจ้าของออกจากบ้าน
         * เพื่อกันขโมยที่ยังไม่มา
         */
        $maxRetry = Validator::requireInt($args, 'max_retry', 3, 100);

        /*
         * **เพดานบนมาจากตัวจำกัดอัตราของหน้าล็อกอิน ไม่ใช่ตัวเลขที่ตั้งใจเลือก**
         *
         * คำขอที่โดน 429 ถูกตัดที่ middleware ก่อนถึง controller จึงไม่มีบรรทัดใน
         * audit log · IP เดียวจึงสร้างบรรทัด "denied" ได้มากที่สุดเท่าที่โควตายอมให้
         * (กดรัวได้ 5 ครั้ง แล้วเติมกลับนาทีละครั้ง) — วัดบนเครื่องจริงแล้วว่ายิงรัว
         * 10 ครั้งได้บรรทัดเดียว ที่เหลือเป็น 429 ล้วน
         *
         * ตั้ง maxretry เกินเพดานนั้น = jail ที่เปิดอยู่แต่ไม่มีวันทำงาน ซึ่งแย่กว่า
         * ปิดไว้ เพราะหน้าจอจะบอกว่ากันอยู่ · ปฏิเสธไปเลยพร้อมบอกเพดานที่แท้จริง
         */
        $ceiling = RateLimit::maxLoginFailuresWithin($findSeconds);

        if ($maxRetry > $ceiling) {
            throw new ValidationError(sprintf(
                'ใน %d วินาที ระบบปล่อยให้ล็อกอินผิดได้มากที่สุด %d ครั้ง (ตัวจำกัดอัตราตัด'
                . 'ที่เหลือทิ้งก่อนถึงการตรวจรหัสผ่าน) — ตั้งเกณฑ์ %d ครั้งจึงไม่มีวันถึง '
                . 'ให้ลดเกณฑ์ลงเหลือไม่เกิน %d หรือขยายช่วงเวลาให้ยาวขึ้น',
                $findSeconds,
                $ceiling,
                $maxRetry,
                $ceiling,
            ));
        }

        return [
            'mode' => $mode,
            'enabled' => true,
            'max_retry' => $maxRetry,
            'find_seconds' => $findSeconds,
            'ban_seconds' => Validator::requireInt($args, 'ban_seconds', 60, 604_800),
            'ignore_ips' => Fail2banManager::normalizeIgnoreList(
                Validator::optionalString($args, 'ignore_ips', '', 2000),
            ),
        ];
    }

    public function run(array $args, Executor $executor, Context $context): array
    {
        $settings = new SettingsRepository($context->db);
        $manager = (new Fail2banManager($executor))
            ->withNeverBan($settings->get('security.never_ban_ips'))
            ->withAlertBinary($context->config->paths->binary('phpcp-alert'));

        if ($args['mode'] === Fail2banManager::MODE_OFF) {
            $manager->removePanelLogin();
            $settings->save([
                'security.panel_jail.enabled' => '0',
                'security.panel_jail.mode' => Fail2banManager::MODE_OFF,
            ]);

            return [
                'mode' => Fail2banManager::MODE_OFF,
                'enabled' => false,
                'active' => false,
                'message' => 'ปิดการกันเดารหัสผ่านหน้าเข้าสู่ระบบแล้ว',
            ];
        }

        /*
         * สวิตช์ใหญ่ปิดอยู่ = ผู้ดูแลเลือกไว้ว่าเครื่องนี้ไม่ใช้ fail2ban (เช่นเพราะ RAM น้อย)
         * · เขียน jail ทับความตั้งใจนั้นไม่ได้ ต้องบอกให้ชัดว่าติดที่ไหน
         */
        if (!$settings->bool('security.fail2ban.enabled')) {
            throw new ValidationError(
                'เครื่องนี้ปิดการใช้ fail2ban ไว้ — เปิดสวิตช์ "ใช้ fail2ban" ในหน้าความปลอดภัยก่อน',
            );
        }

        // สำเนา audit log แบบข้อความคือแหล่งเดียวที่ fail2ban อ่านได้ — ตาราง audit_log
        // เป็น SQLite ซึ่งมันอ่านไม่เป็น (ดู AuditLog::mirror)
        $auditLog = $context->config->paths->logFile('audit');

        $settingsToWrite = [
            'mode' => $args['mode'],
            'max_retry' => $args['max_retry'],
            'find_seconds' => $args['find_seconds'],
            'ban_seconds' => $args['ban_seconds'],
            'ignore_ips' => $args['ignore_ips'],
        ];

        // ไฟล์ก่อน ค่าทีหลัง — บรรทัดนี้โยน exception แล้วจะไม่มีค่าที่บอกว่า "เปิดอยู่"
        $manager->applyPanelLogin($auditLog, $settingsToWrite);

        $settings->save([
            'security.panel_jail.enabled' => '1',
            'security.panel_jail.mode' => $args['mode'],
            'security.panel_jail.max_retry' => (string) $args['max_retry'],
            'security.panel_jail.find_seconds' => (string) $args['find_seconds'],
            'security.panel_jail.ban_seconds' => (string) $args['ban_seconds'],
            'security.panel_jail.ignore_ips' => $args['ignore_ips'],
        ]);

        return [
            'mode' => $args['mode'],
            'enabled' => true,
            'jail' => Fail2banManager::PANEL_LOGIN_JAIL,
            'log_path' => $auditLog,
            'status' => $manager->statusOf(Fail2banManager::PANEL_LOGIN_JAIL),
            'message' => $args['mode'] === Fail2banManager::MODE_NOTIFY
                ? sprintf(
                    'เปิดโหมดแจ้งเตือนแล้ว — ล็อกอินผิด %d ครั้งใน %d นาที จะส่งข้อความหาคุณ **โดยไม่แบน**',
                    $args['max_retry'],
                    (int) round($args['find_seconds'] / 60),
                )
                : sprintf(
                    'เปิดโหมดแบนแล้ว — ล็อกอินผิด %d ครั้งใน %d นาที จะถูกแบน %d นาที',
                    $args['max_retry'],
                    (int) round($args['find_seconds'] / 60),
                    (int) round($args['ban_seconds'] / 60),
                ),
        ];
    }
}
