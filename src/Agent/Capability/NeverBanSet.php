<?php

declare(strict_types=1);

namespace Phpcp\Agent\Capability;

use Phpcp\Agent\Capability;
use Phpcp\Agent\Context;
use Phpcp\Agent\Executor\Executor;
use Phpcp\Domain\SettingsRepository;
use Phpcp\Domain\SiteRepository;
use Phpcp\Driver\Security\Fail2banManager;
use Phpcp\Support\Validator;

/**
 * ตั้งรายการที่อยู่ที่ห้ามแบน — รายการเดียวของทั้งเครื่อง ใช้กับทุก jail
 *
 * **โจทย์ที่ทำให้ต้องมี:** ลูกค้าที่เป็นโรงเรียนออกเน็ตผ่าน IP เดียวกันทั้งโรงเรียน
 * นักเรียนคนเดียวที่เครื่องติดมัลแวร์แล้วสแกนอัตโนมัติจะทำให้ครูและนักเรียนทั้งหมด
 * เข้าเว็บของโรงเรียนไม่ได้ — และเข้าเว็บของลูกค้ารายอื่นบนเครื่องเดียวกันไม่ได้ด้วย
 * เพราะ fail2ban สั่ง firewall ซึ่งไม่รู้จัก vhost
 *
 * **เขียนค่าอย่างเดียวไม่พอ ต้องเขียนไฟล์ jail ใหม่ด้วย** — `ignoreip` ถูกอบเข้าไปใน
 * ไฟล์ตอนที่ jail ถูกเขียน · ถ้าบันทึกแค่ค่าแล้วจบ รายการใหม่จะมีผลกับ jail ที่ถูก
 * เขียนหลังจากนี้เท่านั้น ส่วน jail ที่เปิดอยู่แล้วยังแบนโรงเรียนต่อไปเหมือนเดิม
 * โดยที่หน้าจอบอกว่าลงทะเบียนยกเว้นแล้ว — ซึ่งเป็นความปลอดภัยหลอกแบบเดียวกับที่
 * ระบบนี้ไล่ปิดมาตลอด
 */
final class NeverBanSet implements Capability
{
    public static function name(): string
    {
        return 'security.never_ban_set';
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
        return 'ตั้งรายการที่อยู่ที่ห้ามแบน';
    }

    public function validate(array $args): array
    {
        return [
            'ips' => Fail2banManager::normalizeIgnoreList(
                Validator::optionalString($args, 'ips', '', 2000),
            ),
        ];
    }

    public function run(array $args, Executor $executor, Context $context): array
    {
        $settings = new SettingsRepository($context->db);

        $settings->save(['security.never_ban_ips' => $args['ips']]);

        $manager = (new Fail2banManager($executor))
            ->withNeverBan($args['ips'])
            ->withAlertBinary($context->config->paths->binary('phpcp-alert'));
        $rewritten = [];

        // jail หน้าเข้าสู่ระบบ — เขียนใหม่เฉพาะเมื่อเปิดอยู่ เพราะการเขียนตอนปิดอยู่
        // เท่ากับเปิดให้เองโดยที่ผู้ดูแลไม่ได้สั่ง
        if ($settings->bool('security.panel_jail.enabled')) {
            $manager->applyPanelLogin($context->config->paths->logFile('audit'), [
                // **ต้องส่งโหมดเดิมไปด้วย** — ไม่ส่งแล้วมันตกไปที่ค่าเริ่มต้น (ban)
                // การแก้รายการยกเว้นจะกลายเป็นการเปลี่ยน jail ที่ตั้งเป็น "แจ้งเตือน"
                // ให้เริ่มแบนคนจริง ๆ โดยที่ผู้ดูแลไม่ได้สั่งและไม่มีอะไรบอก
                'mode' => $settings->get('security.panel_jail.mode', Fail2banManager::MODE_BAN),
                'max_retry' => $settings->int('security.panel_jail.max_retry'),
                'find_seconds' => $settings->int('security.panel_jail.find_seconds'),
                'ban_seconds' => $settings->int('security.panel_jail.ban_seconds'),
                'ignore_ips' => $settings->get('security.panel_jail.ignore_ips'),
            ]);

            $rewritten[] = Fail2banManager::PANEL_LOGIN_JAIL;
        }

        // jail รายเว็บที่เปิดอยู่ — อ่านค่าเดิมของแต่ละเว็บมาเขียนไฟล์ใหม่ทั้งชุด
        $sites = new SiteRepository($context->db);

        foreach ($context->db->all('SELECT * FROM site_rate_limits WHERE enabled = 1') as $row) {
            $site = $sites->load((int) $row['site_id']);

            if ($site === null) {
                continue;   // เว็บถูกลบไปแล้วแต่แถวยังค้าง — ไม่ใช่ความผิดพลาดของคำสั่งนี้
            }

            $manager->apply($site, [
                'mode' => (string) ($row['mode'] ?? Fail2banManager::MODE_BAN),
                'max_requests' => (int) $row['max_requests'],
                'window_seconds' => (int) $row['window_seconds'],
                'ban_seconds' => (int) $row['ban_seconds'],
                'ignore_ips' => (string) $row['ignore_ips'],
            ]);

            $rewritten[] = $manager->jailName($site);
        }

        return [
            'ips' => $args['ips'],
            'rewritten' => $rewritten,
            'message' => $rewritten === []
                ? 'บันทึกรายการห้ามแบนแล้ว — ยังไม่มี jail เปิดอยู่ รายการนี้จะถูกใช้เมื่อเปิด'
                : sprintf('บันทึกแล้ว และเขียนไฟล์ของ %d jail ที่เปิดอยู่ใหม่ให้ทันที', count($rewritten)),
        ];
    }
}
