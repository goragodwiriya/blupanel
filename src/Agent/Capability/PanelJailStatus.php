<?php

declare(strict_types=1);

namespace Phpcp\Agent\Capability;

use Phpcp\Agent\Capability;
use Phpcp\Agent\Context;
use Phpcp\Agent\Executor\Executor;
use Phpcp\Domain\SettingsRepository;
use Phpcp\Driver\Security\Fail2banManager;

/**
 * สถานะการกันเดารหัสผ่านของหน้าเข้าสู่ระบบ
 *
 * **ตอบจาก fail2ban เป็นหลัก ไม่ใช่จากค่าที่ panel จำไว้** — สองอย่างนี้ไม่ตรงกันได้จริง:
 * ผู้ดูแลอาจลบไฟล์ jail ทิ้งเอง หรือ fail2ban อาจไม่โหลด jail นั้นเพราะ config ที่อื่นพัง
 * · คำถามที่หน้าจอต้องตอบคือ "ตอนนี้กันอยู่จริงไหม" ไม่ใช่ "เคยกดเปิดไว้ไหม"
 *
 * ค่าที่ตั้งไว้ยังส่งไปด้วยเพื่อให้ฟอร์มเติมค่าเดิมได้ และเพื่อให้เห็นตอนที่สองอย่างไม่ตรงกัน
 */
final class PanelJailStatus implements Capability
{
    public static function name(): string
    {
        return 'security.panel_jail';
    }

    public function permission(): string
    {
        return 'security.view';
    }

    public function isMutating(): bool
    {
        return false;
    }

    public function summary(): string
    {
        return 'ดูสถานะการกันเดารหัสผ่านหน้าเข้าสู่ระบบ';
    }

    public function validate(array $args): array
    {
        return [];
    }

    public function run(array $args, Executor $executor, Context $context): array
    {
        $settings = new SettingsRepository($context->db);
        $manager = new Fail2banManager($executor);

        $status = $manager->statusOf(Fail2banManager::PANEL_LOGIN_JAIL);
        $enabled = $settings->bool('security.panel_jail.enabled');

        return [
            'enabled' => $enabled,
            'jail' => Fail2banManager::PANEL_LOGIN_JAIL,
            'max_retry' => $settings->int('security.panel_jail.max_retry'),
            'find_seconds' => $settings->int('security.panel_jail.find_seconds'),
            'ban_seconds' => $settings->int('security.panel_jail.ban_seconds'),
            'ignore_ips' => $settings->get('security.panel_jail.ignore_ips'),
            // รายการระดับเครื่อง — ฟอร์มของมันอยู่หน้าเดียวกัน จึงส่งมาด้วยกัน
            'never_ban_ips' => $settings->get('security.never_ban_ips'),
            'active' => $status['active'],
            'banned' => $status['banned'],
            'total_banned' => $status['total_banned'],
            'failed' => $status['failed'],
            'banned_ips' => $status['active'] ? $manager->bannedIpsOf(Fail2banManager::PANEL_LOGIN_JAIL) : [],
            // ตั้งไว้ว่าเปิดแต่ fail2ban ไม่รู้จัก jail นี้ = การป้องกันที่หน้าจอโฆษณาไว้
            // ไม่มีอยู่จริง · ต้องบอกออกไปให้ชัด ไม่ใช่แสดงว่า "เปิดอยู่" เฉย ๆ
            'drifted' => $enabled && !$status['active'],
        ];
    }
}
