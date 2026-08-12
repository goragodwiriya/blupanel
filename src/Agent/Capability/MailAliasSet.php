<?php

declare(strict_types=1);

namespace Phpcp\Agent\Capability;

use Phpcp\Agent\Context;
use Phpcp\Agent\Executor\Executor;
use Phpcp\Agent\ValidationError;
use Phpcp\Domain\MailAddress;
use Phpcp\Support\Validator;

/**
 * ที่อยู่ส่งต่อ — alias, forwarder และ catch-all (PLAN-MAIL เฟส M2)
 *
 * สามอย่างนี้เป็นกลไกเดียวกันในสายตาของ Postfix ต่างกันแค่ค่าที่ใส่:
 *
 *   alias      `sales@example.com` → `somchai@example.com`  (กล่องในเครื่องเดียวกัน)
 *   forwarder  `sales@example.com` → `someone@gmail.com`    (ออกไปข้างนอก)
 *   catch-all  ไม่ระบุชื่อ → รับทุกชื่อที่ไม่ตรงกับกล่องหรือ alias อื่นของโดเมนนั้น
 *
 * **catch-all เป็นดาบสองคม** — รับเมลที่สะกดชื่อผิดได้ก็จริง แต่ก็รับสแปมที่ยิงสุ่ม
 * ชื่อทั้งหมดด้วย ซึ่งเป็นวิธีที่สแปมเมอร์ใช้หาที่อยู่จริงของโดเมน
 */
final class MailAliasSet extends MailCapability
{
    public static function name(): string
    {
        return 'mail.alias_set';
    }

    public function summary(): string
    {
        return 'ตั้งที่อยู่ส่งต่อหรือ catch-all';
    }

    public function validate(array $args): array
    {
        $domain = Validator::domain(Validator::requireString($args, 'domain', 253));
        $source = trim((string) ($args['source'] ?? ''));

        // ว่าง = catch-all ของโดเมนนี้ · มีค่า = ต้องเป็นชื่อกล่องที่ถูกต้อง
        if ($source !== '') {
            $source = MailAddress::assertLocalPart($source);
        }

        $destinations = [];

        foreach (preg_split('/[\s,]+/', (string) ($args['destination'] ?? '')) ?: [] as $one) {
            $one = trim($one);

            if ($one === '') {
                continue;
            }

            // ปลายทางเป็นที่อยู่เต็มเสมอ จะในเครื่องหรือข้างนอกก็ได้
            $destinations[] = MailAddress::parse($one)->full();
        }

        if ($destinations === []) {
            throw new ValidationError('ต้องระบุปลายทางอย่างน้อยหนึ่งที่อยู่');
        }

        if (count($destinations) > 20) {
            throw new ValidationError('ปลายทางได้ไม่เกิน 20 ที่อยู่ต่อหนึ่งรายการ');
        }

        return ['domain' => $domain, 'source' => $source, 'destination' => implode(',', $destinations)];
    }

    public function run(array $args, Executor $executor, Context $context): array
    {
        $repository = $this->repository($context);
        $domain = $this->domainOrFail($context, $args['domain']);

        if ((int) ($domain['mail_enabled'] ?? 0) !== 1) {
            throw new ValidationError('โดเมน ' . $args['domain'] . ' ยังไม่ได้เปิดเมล');
        }

        // ชื่อที่มีกล่องอยู่แล้วต้องไม่ถูกเบียดด้วย alias — Postfix อ่าน virtual_alias_maps
        // ก่อน virtual_mailbox_maps เสมอ ผลคือเมลจะไม่มีวันถึงกล่องนั้นอีกเลย
        if ($args['source'] !== '' && $repository->findMailbox((int) $domain['id'], $args['source']) !== null) {
            throw new ValidationError(
                'มีกล่อง ' . $args['source'] . '@' . $args['domain'] . ' อยู่แล้ว — ตั้งเป็นที่อยู่ส่งต่อไม่ได้'
                . ' เพราะเมลจะถูกส่งต่อไปที่อื่นแทนที่จะเข้ากล่อง',
            );
        }

        $id = $repository->setAlias((int) $domain['id'], $args['source'], $args['destination']);

        $result = $this->sync($executor, $context);
        $label = ($args['source'] === '' ? '@' : $args['source'] . '@') . $args['domain'];

        return $result + [
            'id' => $id,
            'source' => $label,
            'destination' => $args['destination'],
            'message' => sprintf('ตั้งให้ %s ส่งต่อไปที่ %s แล้ว', $label, $args['destination']),
        ];
    }
}
