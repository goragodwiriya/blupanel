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

        return ['ip' => $ip];
    }

    public function run(array $args, Executor $executor, Context $context): array
    {
        $manager = new Fail2banManager($executor);

        $manager->unbanFrom(Fail2banManager::PANEL_LOGIN_JAIL, $args['ip']);

        return [
            'ip' => $args['ip'],
            'status' => $manager->statusOf(Fail2banManager::PANEL_LOGIN_JAIL),
            'message' => sprintf('ปลดแบน %s แล้ว', $args['ip']),
        ];
    }
}
