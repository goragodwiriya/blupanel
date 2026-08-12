<?php

declare(strict_types=1);

namespace Phpcp\Agent\Capability;

use Phpcp\Agent\Context;
use Phpcp\Agent\Executor\Executor;
use Phpcp\Agent\ValidationError;
use Phpcp\Domain\MailAddress;
use Phpcp\Driver\Mail\MailboxManager;
use Phpcp\Support\Validator;

/**
 * ลบกล่องจดหมายพร้อมเมลทั้งหมดในนั้น — PLAN-MAIL เฟส M1
 *
 * **ลำดับสำคัญ:** ลบแถว → เขียนตารางใหม่ (กล่องหายจาก Postfix แล้ว) → ค่อยลบไฟล์
 * ถ้าลบไฟล์ก่อน จะมีช่วงที่ Postfix ยังรับเมลของกล่องนั้นแต่ไม่มีที่เก็บ ซึ่งทำให้
 * เมลที่กำลังส่งมาถึงพอดีตีกลับพร้อมข้อความที่ชวนงงว่า "กล่องมีอยู่แต่เขียนไม่ได้"
 */
final class MailBoxDelete extends MailCapability
{
    public static function name(): string
    {
        return 'mail.box_delete';
    }

    public function summary(): string
    {
        return 'ลบกล่องจดหมายพร้อมเมลทั้งหมด';
    }

    public function validate(array $args): array
    {
        $address = MailAddress::parse(Validator::requireString($args, 'address', 320));

        return ['local_part' => $address->localPart, 'domain' => $address->domain];
    }

    public function run(array $args, Executor $executor, Context $context): array
    {
        $repository = $this->repository($context);
        $domain = $this->domainOrFail($context, $args['domain']);
        $mailbox = $repository->findMailbox((int) $domain['id'], $args['local_part']);

        if ($mailbox === null) {
            throw new ValidationError('ไม่พบกล่อง ' . $args['local_part'] . '@' . $args['domain']);
        }

        $address = new MailAddress($args['local_part'], $args['domain']);

        $repository->deleteMailbox((int) $mailbox['id']);

        $result = $this->sync($executor, $context);

        $this->mailboxes($context)->removeMaildir(
            $executor,
            $address->maildir(MailboxManager::MAIL_ROOT),
        );

        return $result + [
            'address' => $address->full(),
            'message' => sprintf('ลบกล่อง %s พร้อมเมลทั้งหมดแล้ว', $address->full()),
        ];
    }
}
