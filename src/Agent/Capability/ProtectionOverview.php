<?php

declare(strict_types=1);

namespace Phpcp\Agent\Capability;

use Phpcp\Agent\Capability;
use Phpcp\Agent\Context;
use Phpcp\Agent\Executor\Executor;
use Phpcp\Domain\SettingsRepository;
use Phpcp\Domain\SiteRepository;
use Phpcp\Driver\Security\Fail2banManager;

/**
 * ภาพรวมการป้องกันทั้งเครื่องในคำตอบเดียว — ทุก jail ที่ panel ดูแล พร้อม IP ที่ถูกแบน
 *
 * **ทำไมต้องรวมเป็นหน้าเดียว** — ก่อนหน้านี้รายการ IP ที่ถูกแบนกระจายอยู่ในหน้าของ
 * แต่ละเว็บ ผู้ดูแลที่ได้รับแจ้งว่า "ลูกค้าเข้าเว็บไม่ได้" จึงต้องไล่เปิดทีละหน้าเพื่อหา
 * ว่าที่อยู่นั้นติดอยู่ใน jail ไหน · คำถามจริงคือ "เครื่องนี้กำลังแบนใครอยู่บ้าง"
 * ซึ่งเป็นคำถามระดับเครื่อง ไม่ใช่ระดับเว็บ
 *
 * **อ่านจาก fail2ban ทุกครั้ง ไม่ใช่จากค่าที่ panel จำไว้** — สองอย่างนี้ไม่ตรงกันได้:
 * ผู้ดูแลสั่ง `fail2ban-client` เองได้ตลอด และ jail อาจไม่ถูกโหลดเพราะ config ที่อื่นพัง
 * · `drifted` คือคำตอบของกรณีที่ค่าบอกว่าเปิดแต่ของจริงไม่ทำงาน ซึ่งแย่กว่าปิดไว้
 * เพราะผู้ดูแลเชื่อว่ามีการป้องกันแล้วจึงไม่ไปหาทางอื่น
 */
final class ProtectionOverview implements Capability
{
    public static function name(): string
    {
        return 'security.protection';
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
        return 'ภาพรวมการป้องกันและรายการ IP ที่ถูกแบน';
    }

    public function validate(array $args): array
    {
        return [];
    }

    public function run(array $args, Executor $executor, Context $context): array
    {
        $settings = new SettingsRepository($context->db);
        $manager = new Fail2banManager($executor);

        $master = $settings->bool('security.fail2ban.enabled');
        $running = $manager->isRunning();

        $jails = [];
        $bans = [];

        // --- jail หน้าเข้าสู่ระบบของ panel ---------------------------------------
        $panelMode = $settings->get('security.panel_jail.mode', Fail2banManager::MODE_OFF);
        $panelStatus = $manager->statusOf(Fail2banManager::PANEL_LOGIN_JAIL);

        $jails[] = $this->row(
            Fail2banManager::PANEL_LOGIN_JAIL,
            'หน้าเข้าสู่ระบบของ panel',
            $settings->bool('security.panel_jail.enabled') ? $panelMode : Fail2banManager::MODE_OFF,
            $panelStatus,
            '/server/security',
        );

        $panelBlocks = $settings->bool('security.panel_jail.enabled') && $panelMode === Fail2banManager::MODE_BAN;

        foreach ($this->bansOf($manager, Fail2banManager::PANEL_LOGIN_JAIL, $panelStatus) as $ban) {
            $bans[] = $ban + [
                'jail_label' => 'หน้าเข้าสู่ระบบของ panel',
                'blocks' => $panelBlocks,
                'state_label' => $panelBlocks ? 'ถูกกันจริง' : 'ตรวจพบ ไม่ได้กัน',
            ];
        }

        // --- jail รายเว็บ ---------------------------------------------------------
        $sites = new SiteRepository($context->db);

        foreach ($context->db->all('SELECT * FROM site_rate_limits WHERE enabled = 1') as $rateLimit) {
            $site = $sites->load((int) $rateLimit['site_id']);

            if ($site === null) {
                continue;
            }

            $name = $manager->jailName($site);
            $status = $manager->statusOf($name);

            $jails[] = $this->row(
                $name,
                'จำกัดอัตราคำขอ — ' . $site->domain,
                (string) ($rateLimit['mode'] ?? Fail2banManager::MODE_BAN),
                $status,
                '/site?id=' . $site->id,
            );

            $blocks = (string) ($rateLimit['mode'] ?? Fail2banManager::MODE_BAN) === Fail2banManager::MODE_BAN;

            foreach ($this->bansOf($manager, $name, $status) as $ban) {
                $bans[] = $ban + [
                    'jail_label' => $site->domain,
                    'blocks' => $blocks,
                    'state_label' => $blocks ? 'ถูกกันจริง' : 'ตรวจพบ ไม่ได้กัน',
                ];
            }
        }

        return [
            'fail2ban_enabled' => $master,
            'fail2ban_running' => $running,
            // ปิดสวิตช์ไว้แต่บริการยังรันอยู่เป็นเรื่องปกติ (jail ของ SSH จากดิสโทร) —
            // แต่ "เปิดสวิตช์ไว้แล้วบริการไม่รัน" คือการป้องกันที่ไม่มีอยู่จริง
            'drifted' => $master && !$running,
            'never_ban_ips' => $settings->get('security.never_ban_ips'),
            'jails' => $jails,
            'bans' => $bans,
            'banned_total' => count($bans),
            'memory_mb' => $manager->memoryUsageMb(),
        ];
    }

    /**
     * @param array{active:bool,banned:int,total_banned:int,failed:int} $status
     * @return array<string,mixed>
     */
    private function row(string $jail, string $label, string $mode, array $status, string $manageUrl): array
    {
        return [
            'jail' => $jail,
            'label' => $label,
            'mode' => $mode,
            'mode_label' => match ($mode) {
                Fail2banManager::MODE_BAN => 'แบนอัตโนมัติ',
                Fail2banManager::MODE_NOTIFY => 'แจ้งเตือนอย่างเดียว',
                default => 'ปิดอยู่',
            },
            'mode_tone' => match ($mode) {
                Fail2banManager::MODE_BAN => 'danger',
                Fail2banManager::MODE_NOTIFY => 'warn',
                default => 'muted',
            },
            'active' => $status['active'],
            'banned' => $status['banned'],
            'failed' => $status['failed'],
            /*
             * **โหมดแจ้งเตือนก็ยังนับ "banned" ใน fail2ban** — วัดบนเครื่องจริงแล้ว:
             * สั่ง banip ตอนอยู่โหมด notify แล้ว `Currently banned: 1` แต่ firewall
             * ว่างเปล่าเพราะ action ไม่มีคำสั่งแตะมันเลย
             *
             * แสดงเลขนั้นว่า "ถูกแบน" จึงเป็นการโกหก — คนอ่านจะเข้าใจว่ามีคนถูกตัด
             * ออกจากเครื่องแล้วทั้งที่ไม่มีใครถูกตัดเลย · หน้าจอต้องเรียกตามสิ่งที่
             * เกิดขึ้นจริงในโหมดนั้น
             */
            'blocks' => $mode === Fail2banManager::MODE_BAN,
            'count_label' => $mode === Fail2banManager::MODE_BAN ? 'ถูกแบนอยู่' : 'ตรวจพบ',
            // ตั้งโหมดไว้แต่ fail2ban ไม่รู้จัก jail = สิ่งที่หน้าจอโฆษณาไม่มีอยู่จริง
            'drifted' => $mode !== Fail2banManager::MODE_OFF && !$status['active'],
            'manage_url' => $manageUrl,
        ];
    }

    /**
     * @param array{active:bool,banned:int,total_banned:int,failed:int} $status
     * @return list<array<string,mixed>>
     */
    private function bansOf(Fail2banManager $manager, string $jail, array $status): array
    {
        if (!$status['active'] || $status['banned'] === 0) {
            return [];
        }

        return array_map(
            static fn (string $ip): array => ['ip' => $ip, 'jail' => $jail],
            $manager->bannedIpsOf($jail),
        );
    }
}
