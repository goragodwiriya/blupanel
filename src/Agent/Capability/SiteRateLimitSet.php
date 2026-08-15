<?php

declare(strict_types=1);

namespace Phpcp\Agent\Capability;

use Phpcp\Agent\Context;
use Phpcp\Agent\Executor\Executor;
use Phpcp\Domain\SettingsRepository;
use Phpcp\Driver\Security\Fail2banManager;
use Phpcp\Support\Validator;

/**
 * ตั้งค่าการจำกัดอัตราคำขอของเว็บไซต์หนึ่ง — PLAN-V2 เฟส E5
 *
 * บังคับใช้ผ่าน fail2ban ที่อ่าน access log ของเว็บนั้นแล้วสั่ง firewall แบน IP
 * (เหตุผลที่ไม่ใช้โมดูลของเว็บเซิร์ฟเวอร์อยู่ใน `Driver\Security\Fail2banManager`)
 *
 * **ฐานข้อมูลถูกเขียนหลังไฟล์สำเร็จเสมอ** — ถ้าบันทึกก่อนแล้ว fail2ban ล้มเหลว
 * หน้าจอจะบอกว่าเปิดการป้องกันไว้แล้วทั้งที่ไม่มีอะไรบังคับใช้เลย ซึ่งอันตราย
 * กว่าไม่มีฟีเจอร์นี้ เพราะผู้ดูแลเข้าใจว่าเว็บมีการป้องกันอยู่
 */
final class SiteRateLimitSet extends SiteCapability
{
    public static function name(): string
    {
        return 'site.rate_limit_set';
    }

    public function permission(): string
    {
        return 'site.edit';
    }

    public function isMutating(): bool
    {
        return true;
    }

    public function summary(): string
    {
        return 'ตั้งค่าการจำกัดอัตราคำขอของเว็บไซต์';
    }

    public function validate(array $args): array
    {
        // `mode` (off|notify|ban) แทน enabled แบบสองสถานะ · ยังรับของเดิมเพื่อความเข้ากันได้
        $mode = Validator::optionalString($args, 'mode', '', 16);

        if ($mode === '') {
            $mode = Validator::requireBool($args, 'enabled')
                ? Fail2banManager::MODE_BAN
                : Fail2banManager::MODE_OFF;
        }

        if (!in_array($mode, Fail2banManager::modes(), true)) {
            throw new \Phpcp\Agent\ValidationError('โหมดของ jail ไม่ถูกต้อง — ต้องเป็น off, notify หรือ ban');
        }

        // ค่าตัวเลขตรวจเฉพาะตอนเปิด — คนที่กดปิดไม่ควรถูกบังคับให้กรอกค่าที่กำลังจะไม่ถูกใช้
        if ($mode === Fail2banManager::MODE_OFF) {
            return ['site_id' => Validator::requireInt($args, 'site_id', 1), 'mode' => $mode, 'enabled' => false];
        }

        return [
            'site_id' => Validator::requireInt($args, 'site_id', 1),
            'mode' => $mode,
            'enabled' => true,
            // ขอบเขตตรงกับ CHECK ใน migration 0016 — ตรวจสองชั้นเพราะฐานข้อมูลเป็นด่านสุดท้าย
            // ที่ไม่มีข้อความอธิบายให้ผู้ใช้ ส่วนด่านนี้บอกได้ว่าผิดตรงไหนและช่วงที่ยอมรับคืออะไร
            'max_requests' => Validator::requireInt($args, 'max_requests', 10, 100_000),
            'window_seconds' => Validator::requireInt($args, 'window_seconds', 10, 3600),
            'ban_seconds' => Validator::requireInt($args, 'ban_seconds', 60, 86_400),
            'ignore_ips' => Fail2banManager::normalizeIgnoreList(
                Validator::optionalString($args, 'ignore_ips', '', 2000),
            ),
        ];
    }

    public function run(array $args, Executor $executor, Context $context): array
    {
        $this->assertSiteAccess($context, $args['site_id']);

        $site = $this->loadSite($context, $args['site_id']);
        // รายการห้ามแบนระดับเครื่องต้องถูกฉีดเข้าทุก jail ที่เขียน ไม่งั้นลูกค้าที่เป็น
        // โรงเรียน (คนทั้งโรงเรียนออกเน็ตผ่าน IP เดียว) จะถูกแบนยกองค์กรจากเว็บเดียว
        $manager = (new Fail2banManager($executor))
            ->withNeverBan((new SettingsRepository($context->db))->get('security.never_ban_ips'))
            ->withAlertBinary($context->config->paths->binary('phpcp-alert'));
        $now = time();

        if ($args['mode'] === Fail2banManager::MODE_OFF) {
            $manager->remove($site);

            $context->db->run(
                'UPDATE site_rate_limits SET enabled = 0, updated_at = :t WHERE site_id = :id',
                ['t' => $now, 'id' => $args['site_id']],
            );

            return [
                'site_id' => $args['site_id'],
                'enabled' => false,
                'message' => sprintf('ปิดการจำกัดอัตราคำขอของ %s แล้ว', $site->domain),
            ];
        }

        $settings = [
            'mode' => $args['mode'],
            'max_requests' => $args['max_requests'],
            'window_seconds' => $args['window_seconds'],
            'ban_seconds' => $args['ban_seconds'],
            'ignore_ips' => $args['ignore_ips'],
        ];

        // ไฟล์ก่อน ฐานข้อมูลทีหลัง — ถ้าบรรทัดนี้โยน exception จะไม่มีแถวที่บอกว่า "เปิดอยู่"
        $manager->apply($site, $settings);

        $context->db->run(
            'INSERT INTO site_rate_limits
                (site_id, enabled, mode, max_requests, window_seconds, ban_seconds, ignore_ips, created_at, updated_at)
             VALUES (:id, 1, :mode, :max, :window, :ban, :ignore, :t, :t)
             ON CONFLICT(site_id) DO UPDATE SET
                enabled = 1, mode = :mode, max_requests = :max, window_seconds = :window,
                ban_seconds = :ban, ignore_ips = :ignore, updated_at = :t',
            [
                'id' => $args['site_id'],
                'mode' => $settings['mode'],
                'max' => $settings['max_requests'],
                'window' => $settings['window_seconds'],
                'ban' => $settings['ban_seconds'],
                'ignore' => $settings['ignore_ips'],
                't' => $now,
            ],
        );

        return [
            'site_id' => $args['site_id'],
            'enabled' => true,
            'jail' => $manager->jailName($site),
            'status' => $manager->status($site),
            'message' => sprintf(
                'เปิดการจำกัดอัตราของ %s แล้ว — เกิน %d คำขอใน %d วินาที จะถูกแบน %d นาที',
                $site->domain,
                $settings['max_requests'],
                $settings['window_seconds'],
                (int) round($settings['ban_seconds'] / 60),
            ),
        ];
    }
}
