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
 * สวิตช์ใหญ่ — เครื่องนี้ให้ panel ใช้ fail2ban หรือไม่
 *
 * **มีไว้เพราะ fail2ban ไม่ได้ฟรี** · วัดบนเครื่องจริงได้ 55MB และโตตามจำนวน jail
 * (หนึ่งเธรด + หนึ่ง log tail + แผนที่ในหน่วยความจำต่อ jail) · บนเครื่อง 1–2GB ที่ยัง
 * ต้องรัน MariaDB, Apache, Dovecot และ rspamd นั่นคือส่วนที่ตัดออกได้จริง โดยเฉพาะ
 * เมื่อการป้องกันที่มีค่าที่สุดคือ {@see \Phpcp\Driver\WebServer\ProbeBlocklist}
 * ซึ่งทำงานที่เว็บเซิร์ฟเวอร์และไม่กินหน่วยความจำเพิ่มเลย
 *
 * **ปิดแล้วไม่หยุดบริการ fail2ban ให้เอง** — jail ของ SSH มาจากแพ็กเกจของดิสโทร
 * ไม่ใช่ของ panel · หยุดบริการแปลว่าการกันเดารหัส SSH หายไปด้วย ซึ่งเป็นผลข้างเคียง
 * ที่ผู้ดูแลไม่ได้ขอและอาจไม่รู้ตัว · คำสั่งหยุดบริการจึงถูกส่งกลับไปให้เขาสั่งเอง
 * พร้อมคำเตือน ไม่ใช่ทำแทน
 */
final class Fail2banSet implements Capability
{
    public static function name(): string
    {
        return 'security.fail2ban_set';
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
        return 'เปิด/ปิดการใช้ fail2ban ของ panel';
    }

    public function validate(array $args): array
    {
        return ['enabled' => Validator::requireBool($args, 'enabled')];
    }

    public function run(array $args, Executor $executor, Context $context): array
    {
        $settings = new SettingsRepository($context->db);

        if ($args['enabled']) {
            $settings->save(['security.fail2ban.enabled' => '1']);

            return [
                'enabled' => true,
                'removed' => [],
                'message' => 'เปิดการใช้ fail2ban แล้ว — เลือกโหมดของแต่ละรายการด้านล่าง',
            ];
        }

        /*
         * ปิด = ต้องไม่เหลือ jail ของ panel ค้างบนเครื่องเลย
         *
         * บันทึกค่าอย่างเดียวไม่พอ — jail ที่ยังอยู่ก็ยังทำงานและยังกินหน่วยความจำ
         * ต่อไป โดยที่หน้าจอบอกว่าปิดแล้ว
         */
        $manager = new Fail2banManager($executor);
        $removed = [];

        if ($settings->bool('security.panel_jail.enabled')) {
            $manager->removePanelLogin();
            $removed[] = Fail2banManager::PANEL_LOGIN_JAIL;
        }

        $sites = new SiteRepository($context->db);

        foreach ($context->db->all('SELECT site_id FROM site_rate_limits WHERE enabled = 1') as $row) {
            $site = $sites->load((int) $row['site_id']);

            if ($site === null) {
                continue;
            }

            $manager->remove($site);
            $removed[] = $manager->jailName($site);
        }

        // สถานะของแต่ละรายการถูกปิดตามไปด้วย ไม่งั้นเปิดสวิตช์ใหญ่กลับมาแล้วหน้าจอ
        // จะบอกว่ารายการเหล่านั้นยังเปิดอยู่ทั้งที่ไฟล์ถูกลบไปแล้ว
        $context->db->run('UPDATE site_rate_limits SET enabled = 0, updated_at = :t', ['t' => time()]);

        $settings->save([
            'security.fail2ban.enabled' => '0',
            'security.panel_jail.enabled' => '0',
            'security.panel_jail.mode' => Fail2banManager::MODE_OFF,
        ]);

        return [
            'enabled' => false,
            'removed' => $removed,
            /*
             * บอกวิธีคืนหน่วยความจำจริง ๆ พร้อมสิ่งที่จะเสียไป — คนที่ปิดเพราะ RAM
             * ไม่พอต้องได้คำสั่งที่ใช้ได้เลย ไม่ใช่รู้แค่ว่า "panel ไม่ใช้แล้ว"
             */
            'stop_command' => 'sudo systemctl disable --now fail2ban',
            'message' => sprintf(
                'ปิดการใช้ fail2ban ของ panel แล้ว — ลบ jail ไป %d รายการ · '
                . 'บริการ fail2ban ยัง**ทำงานอยู่** เพราะ jail ของ SSH มาจากดิสโทร ไม่ใช่ของ panel · '
                . 'ถ้าต้องการคืนหน่วยความจำ (~55MB) ให้สั่ง `sudo systemctl disable --now fail2ban` เอง '
                . 'แต่การกันเดารหัสผ่าน SSH จะหายไปด้วย',
                count($removed),
            ),
        ];
    }
}
