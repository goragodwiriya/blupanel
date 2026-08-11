<?php

declare(strict_types=1);

namespace Phpcp\Agent\Capability;

use Phpcp\Agent\Context;
use Phpcp\Agent\Executor\Executor;
use Phpcp\Agent\ValidationError;
use Phpcp\Driver\Firewall\UfwDriver;
use Phpcp\Driver\RollbackGuard;
use Phpcp\Driver\SshManager;

/**
 * ส่วนที่ capability ของ firewall ใช้ร่วมกัน
 *
 * เรื่องสำคัญที่สุดของหน้านี้คือ "ห้ามปิดประตูขังตัวเอง" — กฎที่เปิดพอร์ตของ panel
 * และพอร์ต SSH คือทางเดียวที่ผู้ดูแลจะกลับเข้ามาแก้ไขได้ถ้าตั้งค่าผิด
 * ตรรกะป้องกันสองพอร์ตนี้จึงรวมไว้ที่เดียว ไม่กระจายไปตาม capability
 */
abstract class FirewallCapability
{
    protected function driver(): UfwDriver
    {
        return new UfwDriver();
    }

    protected function guard(Context $context): RollbackGuard
    {
        return new RollbackGuard($context->db);
    }

    /** พอร์ตที่ panel ให้บริการอยู่ — ลบกฎที่เปิดพอร์ตนี้ไม่ได้เด็ดขาด */
    protected function panelPort(Context $context): string
    {
        return (string) $context->config->int('panel.port', 8443);
    }

    /**
     * พอร์ต SSH ที่ใช้อยู่จริง อ่านจาก sshd_config ไม่ใช่ค่าคงที่ 22
     * เพราะผู้ดูแลอาจเปลี่ยนไปแล้วผ่านหน้า SSH ของ panel นี้เอง
     */
    protected function sshPort(Executor $executor): string
    {
        try {
            $values = (new SshManager())->read($executor);

            return (string) ($values['Port']['value'] ?? '22');
        } catch (\Throwable) {
            return '22';
        }
    }

    /**
     * พอร์ตนี้เป็นเส้นชีวิตหรือไม่ — รองรับทั้งพอร์ตเดี่ยวและช่วงที่คร่อมพอร์ตนั้น
     *
     * ตรวจช่วงด้วยเพราะกฎอย่าง `deny 8000:9000/tcp` ปิดพอร์ต 8443 ไปด้วย
     * ทั้งที่ไม่ได้เอ่ยถึงตัวเลขนั้นตรง ๆ
     */
    protected function covers(string $spec, string $port): bool
    {
        if ($spec === $port) {
            return true;
        }

        if (!str_contains($spec, ':')) {
            return false;
        }

        [$from, $to] = array_map('intval', explode(':', $spec, 2));
        $target = (int) $port;

        return $target >= $from && $target <= $to;
    }

    /** ห้ามปิดทางเข้าของตัวเอง — ARCHITECTURE §5.4 */
    protected function assertNotLifeline(string $port, Context $context, Executor $executor, string $what): void
    {
        $panel = $this->panelPort($context);

        if ($this->covers($port, $panel)) {
            throw new ValidationError(
                "{$what}พอร์ต {$panel} ไม่ได้ เพราะเป็นพอร์ตที่หน้าเว็บนี้ให้บริการอยู่ — "
                . 'ทำแล้วจะเข้ามาแก้กลับไม่ได้อีก',
            );
        }

        $ssh = $this->sshPort($executor);

        if ($this->covers($port, $ssh)) {
            throw new ValidationError(
                "{$what}พอร์ต {$ssh} ไม่ได้ เพราะเป็นพอร์ตของ SSH ซึ่งเป็นทางกลับเข้าเครื่อง "
                . 'เมื่อหน้าเว็บใช้การไม่ได้ — ถ้าจำเป็นต้องทำจริง ให้แก้ที่เครื่องโดยตรง',
            );
        }
    }

    protected function window(array $args): int
    {
        return isset($args['window'])
            ? max(30, min(900, (int) $args['window']))
            : RollbackGuard::DEFAULT_WINDOW;
    }

    /** มีรายการรอยืนยันค้างอยู่ = ห้ามเปลี่ยนอะไรอีกจนกว่าจะเคลียร์ */
    protected function assertNoPending(Context $context): void
    {
        if ($this->guard($context)->pending() !== null) {
            throw new ValidationError(
                'มีการเปลี่ยนแปลงที่รอการยืนยันอยู่ — ยืนยันหรือรอให้คืนค่าก่อนจึงจะแก้ใหม่ได้',
            );
        }
    }

    protected function assertInstalled(Executor $executor): void
    {
        if (!$this->driver()->isInstalled($executor)) {
            throw new ValidationError('ไม่พบ ufw บนเครื่องนี้ — ติดตั้งด้วย apt install ufw ก่อน');
        }
    }
}
