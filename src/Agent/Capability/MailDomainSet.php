<?php

declare(strict_types=1);

namespace Phpcp\Agent\Capability;

use Phpcp\Agent\Context;
use Phpcp\Agent\Executor\Executor;
use Phpcp\Agent\ValidationError;
use Phpcp\Support\Validator;

/**
 * เปิดหรือปิดเมลของโดเมนหนึ่ง — PLAN-MAIL เฟส M1
 *
 * **เปิดเป็นรายโดเมน ไม่ใช่เปิดทั้งเครื่อง** โดเมนที่ไม่ได้ใช้เมลต้องไม่โผล่ใน
 * `virtual_mailbox_domains` เลย เพราะโดเมนที่อยู่ในนั้นแปลว่าเครื่องนี้ประกาศตัวว่า
 * เป็นปลายทางสุดท้ายของเมลโดเมนนั้น — เมลที่ส่งมาจะไม่ถูกส่งต่อไปที่อื่นอีก
 * ถ้าเผลอเปิดให้โดเมนที่จริง ๆ ใช้ Gmail อยู่ เมลของลูกค้าจะหายเข้าเครื่องนี้ทั้งหมด
 *
 * ปิดเมลไม่ลบกล่อง — แถวยังอยู่ในฐานข้อมูลเผื่อเปิดกลับ แต่หายจากไฟล์ตั้งค่าจริง
 */
final class MailDomainSet extends MailCapability
{
    public static function name(): string
    {
        return 'mail.domain_set';
    }

    public function summary(): string
    {
        return 'เปิดหรือปิดเมลของโดเมน';
    }

    public function validate(array $args): array
    {
        return [
            'domain' => Validator::domain(Validator::requireString($args, 'domain', 253)),
            'enabled' => (bool) ($args['enabled'] ?? false),
        ];
    }

    public function run(array $args, Executor $executor, Context $context): array
    {
        $repository = $this->repository($context);
        $domain = $this->domainOrFail($context, $args['domain']);

        if (!$this->mailboxes($context)->isInstalled($executor)) {
            throw new ValidationError(
                'ไม่พบ Dovecot บนเครื่องนี้ — ติดตั้งด้วย apt install dovecot-imapd dovecot-pop3d dovecot-lmtpd ก่อน',
            );
        }

        $repository->setDomainMail((int) $domain['id'], $args['enabled']);

        $result = $this->sync($executor, $context);

        return $result + [
            'domain' => (string) $domain['domain'],
            'enabled' => $args['enabled'],
            'message' => $args['enabled']
                ? sprintf('เปิดเมลของ %s แล้ว — ต้องชี้เรกคอร์ด MX มาที่เครื่องนี้ด้วย', $domain['domain'])
                : sprintf('ปิดเมลของ %s แล้ว — กล่องยังอยู่ในระบบแต่ไม่รับเมลอีก', $domain['domain']),
        ];
    }
}
