<?php

declare(strict_types=1);

namespace Phpcp\Agent\Capability;

use Phpcp\Agent\Context;
use Phpcp\Agent\Executor\Executor;
use Phpcp\Agent\ValidationError;
use Phpcp\Domain\MailAddress;
use Phpcp\Domain\QuotaChecker;
use Phpcp\Domain\UserRepository;
use Phpcp\Driver\Mail\MailboxManager;
use Phpcp\Security\Password;
use Phpcp\Support\Validator;

/**
 * สร้างกล่องจดหมายใหม่ — PLAN-MAIL เฟส M1
 *
 * **รหัสผ่านถูกแฮชที่นี่ ไม่ใช่ที่ชั้นเว็บ** และแฮชด้วย `doveadm pw` ของ Dovecot เอง
 * ไม่ใช่ `password_hash()` ของ PHP — รูปแบบต้องเป็นของ Dovecot ({ARGON2ID}...)
 * ไม่งั้นล็อกอินไม่ผ่านโดยไม่มีข้อความบอกสาเหตุ
 *
 * รหัสจริงถูกส่งกลับไปแสดง **ครั้งเดียว** เหมือนรหัสของฐานข้อมูล — ระบบไม่เก็บไว้
 * ที่ไหนเลย ลืมแล้วต้องตั้งใหม่
 */
final class MailBoxCreate extends MailCapability
{
    /** ขนาดกล่องเริ่มต้น — พอสำหรับใช้งานทั่วไปและไม่กินดิสก์จนน่ากลัว */
    private const DEFAULT_QUOTA_MB = 1024;

    public static function name(): string
    {
        return 'mail.box_create';
    }

    public function summary(): string
    {
        return 'สร้างกล่องจดหมายใหม่';
    }

    public function validate(array $args): array
    {
        $address = MailAddress::parse(Validator::requireString($args, 'address', 320));
        $quota = (int) ($args['quota_mb'] ?? self::DEFAULT_QUOTA_MB);

        if ($quota < 1 || $quota > 1024 * 100) {
            throw new ValidationError('ขนาดกล่องต้องอยู่ระหว่าง 1 MB ถึง 100 GB');
        }

        return [
            'local_part' => $address->localPart,
            'domain' => $address->domain,
            'quota_mb' => $quota,
            'password' => (string) ($args['password'] ?? ''),
        ];
    }

    public function run(array $args, Executor $executor, Context $context): array
    {
        $repository = $this->repository($context);
        $domain = $this->domainOrFail($context, $args['domain']);

        if ((int) ($domain['mail_enabled'] ?? 0) !== 1) {
            throw new ValidationError(
                'โดเมน ' . $args['domain'] . ' ยังไม่ได้เปิดเมล — เปิดก่อนแล้วค่อยสร้างกล่อง',
            );
        }

        if ($repository->findMailbox((int) $domain['id'], $args['local_part']) !== null) {
            throw new ValidationError('มีกล่อง ' . $args['local_part'] . '@' . $args['domain'] . ' อยู่แล้ว');
        }

        /*
         * โควตาจำนวนกล่องของเจ้าของ — ช่อง `quota_emails` ในหน้าลูกค้ามีมาตั้งแต่ต้น
         * แต่ไม่เคยมีอะไรบังคับ เพราะยังไม่มีระบบเมลจริง · ตรวจที่นี่จุดเดียว ทั้ง
         * หน้าเว็บและ CLI จึงได้กติกาเดียวกัน
         */
        $owner = $repository->ownerOf((int) $domain['id']);

        if ($owner > 0) {
            $quota = (new QuotaChecker(new UserRepository($context->db)))->canCreate($owner, 'emails');

            if (!$quota['ok']) {
                throw new ValidationError((string) $quota['message']);
            }
        }

        // สุ่มให้ถ้าไม่ได้ระบุมา — ผู้ดูแลไม่ต้องคิดรหัสเอง (ซึ่งมักจะได้รหัสที่อ่อน)
        $plain = $args['password'] !== '' ? $args['password'] : Password::random(16);
        $manager = $this->mailboxes($context);

        $address = new MailAddress($args['local_part'], $args['domain']);
        $maildir = $address->maildir(MailboxManager::MAIL_ROOT);

        $id = $repository->createMailbox(
            (int) $domain['id'],
            $args['local_part'],
            $manager->hashPassword($executor, $plain),
            $args['quota_mb'],
        );

        // สร้างโฟลเดอร์ก่อนเขียนตาราง — ถ้าเขียนตารางก่อนแล้วสร้างโฟลเดอร์ไม่สำเร็จ
        // จะมีช่วงที่ Postfix รับเมลของกล่องที่ยังไม่มีที่เก็บ แล้วเมลตีกลับ
        $manager->createMaildir($executor, $maildir);

        $result = $this->sync($executor, $context);

        return $result + [
            'id' => $id,
            'address' => $address->full(),
            'quota_mb' => $args['quota_mb'],
            // แสดงครั้งเดียวเท่านั้น ระบบไม่เก็บรหัสจริงไว้ที่ไหนเลย
            'password' => $plain,
            /*
             * ต้องเอาไปโชว์หรือเปล่า — คนละเรื่องกับ "มีรหัสในคำตอบไหม"
             *
             * รหัสอยู่ในคำตอบเสมอ ทั้งที่ระบบสุ่มให้และที่ผู้ดูแลพิมพ์เอง · ถ้าใช้แค่
             * `password !== ''` เป็นเงื่อนไข หน้าต่างรหัสผ่านจะเด้งทุกครั้งที่บันทึก
             * รวมถึงตอนที่ผู้ดูแลเพิ่งพิมพ์รหัสนั้นมากับมือ — เขาเห็นหน้าต่างค้างอยู่
             * แล้วเข้าใจว่าฟอร์มยังไม่ปิด ทั้งที่กล่องถูกสร้างไปแล้ว
             */
            'password_generated' => $args['password'] === '',
            'message' => sprintf('สร้างกล่อง %s แล้ว', $address->full()),
        ];
    }
}
