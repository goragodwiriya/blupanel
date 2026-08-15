<?php

declare(strict_types=1);

namespace Phpcp\Agent\Capability;

use Phpcp\Agent\Capability;
use Phpcp\Agent\Context;
use Phpcp\Agent\Executor\Executor;
use Phpcp\Agent\ValidationError;
use Phpcp\Driver\Security\Fail2banManager;
use Phpcp\Support\Validator;

/**
 * ปลดแบน IP หนึ่งออกจาก jail ของหน้าเข้าสู่ระบบ
 *
 * **ทางออกฉุกเฉินของฟีเจอร์ที่แบนได้ทั้งเครื่อง** — การแบนของ fail2ban สั่ง firewall
 * ซึ่งตัดทุกพอร์ต ผู้ดูแลที่พิมพ์รหัสผิดเกินเกณฑ์จึงเข้าหน้าจัดการไม่ได้เลย ·
 * ถ้าเขายังมีเครื่องอื่นหรือเน็ตอื่นอยู่ ต้องปลดจากหน้าจอได้ ไม่ต้องหา SSH ให้ได้ก่อน
 */
final class PanelJailUnban implements Capability
{
    public static function name(): string
    {
        return 'security.panel_jail_unban';
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
        return 'ปลดแบน IP จากการกันเดารหัสผ่าน';
    }

    public function validate(array $args): array
    {
        $ip = Validator::requireString($args, 'ip', 45);

        // ค่านี้กลายเป็นอาร์กิวเมนต์ของ fail2ban-client — ต้องเป็น IP จริงเท่านั้น
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            throw new ValidationError('รูปแบบ IP ไม่ถูกต้อง');
        }

        /*
         * ชื่อ jail มาจากหน้ารวมซึ่งมีหลาย jail · ว่าง = jail หน้าเข้าสู่ระบบตามเดิม
         *
         * **ต้องขึ้นต้นด้วยคำนำหน้าของ panel เท่านั้น** — ค่านี้กลายเป็นอาร์กิวเมนต์ของ
         * `fail2ban-client` · ถ้ารับชื่ออะไรก็ได้ ผู้ที่มีสิทธิ์ security.manage จะปลดแบน
         * jail ที่ผู้ดูแลเขียนเอง (เช่น sshd ของดิสโทร) ได้จากหน้าเว็บ ซึ่งอยู่นอก
         * ขอบเขตของ panel และเป็นการยกเลิกการป้องกันที่ panel ไม่ได้เป็นคนตั้ง
         */
        $jail = Validator::optionalString($args, 'jail', '', 64);

        if ($jail === '') {
            $jail = Fail2banManager::PANEL_LOGIN_JAIL;
        }

        if (!Fail2banManager::isOwnJail($jail)) {
            throw new ValidationError('ปลดแบนได้เฉพาะ jail ที่ panel เป็นผู้ดูแลเท่านั้น');
        }

        return ['ip' => $ip, 'jail' => $jail];
    }

    public function run(array $args, Executor $executor, Context $context): array
    {
        $manager = new Fail2banManager($executor);

        $manager->unbanFrom($args['jail'], $args['ip']);

        return [
            'ip' => $args['ip'],
            'jail' => $args['jail'],
            'status' => $manager->statusOf($args['jail']),
            'message' => sprintf('ปลดแบน %s แล้ว', $args['ip']),
        ];
    }
}
