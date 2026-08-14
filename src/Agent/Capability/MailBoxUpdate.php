<?php

declare(strict_types=1);

namespace Phpcp\Agent\Capability;

use Phpcp\Agent\Context;
use Phpcp\Agent\Executor\Executor;
use Phpcp\Agent\ValidationError;
use Phpcp\Domain\MailAddress;
use Phpcp\Security\Password;
use Phpcp\Support\Validator;

/**
 * แก้กล่องที่มีอยู่ — ตั้งรหัสใหม่ หรือเปลี่ยนโควตา (PLAN-MAIL เฟส M2)
 *
 * รวมสองอย่างไว้ที่เดียวเพราะทั้งคู่คือ "แก้กล่องเดิม" และฟอร์มเดียวกันบนหน้าเว็บ
 * ส่งมาพร้อมกันได้ · แยกเป็นสอง capability จะทำให้หน้าเว็บต้องยิงสองคำขอแล้วต้อง
 * ตัดสินใจเองว่าจะทำยังไงถ้าอันแรกสำเร็จแต่อันที่สองล้ม
 *
 * **รหัสผ่านที่ส่งมาว่าง = ไม่เปลี่ยน** ไม่ใช่ "ตั้งเป็นค่าว่าง" — ฟอร์มแก้ไขเว้นช่อง
 * รหัสไว้เสมอเพราะระบบไม่เคยส่งรหัสเดิมกลับไปแสดง (เก็บเป็นแฮชอย่างเดียว)
 */
final class MailBoxUpdate extends MailCapability
{
    public static function name(): string
    {
        return 'mail.box_update';
    }

    public function summary(): string
    {
        return 'ตั้งรหัสผ่านใหม่หรือเปลี่ยนโควตาของกล่อง';
    }

    public function validate(array $args): array
    {
        $address = MailAddress::parse(Validator::requireString($args, 'address', 320));
        $quota = isset($args['quota_mb']) ? (int) $args['quota_mb'] : 0;

        if ($quota !== 0 && ($quota < 1 || $quota > 1024 * 100)) {
            throw new ValidationError('ขนาดกล่องต้องอยู่ระหว่าง 1 MB ถึง 100 GB');
        }

        return [
            'local_part' => $address->localPart,
            'domain' => $address->domain,
            'quota_mb' => $quota,
            'password' => (string) ($args['password'] ?? ''),
            'reset_password' => (bool) ($args['reset_password'] ?? false),
        ];
    }

    public function run(array $args, Executor $executor, Context $context): array
    {
        $repository = $this->repository($context);
        $domain = $this->domainOrFail($context, $args['domain']);
        $mailbox = $repository->findMailbox((int) $domain['id'], $args['local_part']);

        if ($mailbox === null) {
            throw new ValidationError('ไม่พบกล่อง ' . $args['local_part'] . '@' . $args['domain']);
        }

        $manager = $this->mailboxes($context);
        $plain = '';

        if ($args['password'] !== '' || $args['reset_password']) {
            $plain = $args['password'] !== '' ? $args['password'] : Password::random(16);
            $repository->setPassword((int) $mailbox['id'], $manager->hashPassword($executor, $plain));
        }

        if ($args['quota_mb'] > 0) {
            $repository->setQuota((int) $mailbox['id'], $args['quota_mb']);
        }

        $result = $this->sync($executor, $context);
        $address = (new MailAddress($args['local_part'], $args['domain']))->full();

        return $result + [
            'address' => $address,
            // แสดงครั้งเดียวเท่านั้น เหมือนตอนสร้างกล่อง
            'password' => $plain,
            // โชว์เฉพาะรหัสที่ระบบสุ่มให้ — เหตุผลเดียวกับตอนสร้างกล่อง
            'password_generated' => $plain !== '' && $args['password'] === '',
            'message' => $plain !== ''
                ? sprintf('ตั้งรหัสผ่านใหม่ให้ %s แล้ว', $address)
                : sprintf('บันทึกกล่อง %s แล้ว', $address),
        ];
    }
}
