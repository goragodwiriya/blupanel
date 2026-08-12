<?php

declare(strict_types=1);

namespace Phpcp\Agent\Capability;

use Phpcp\Agent\Context;
use Phpcp\Agent\Executor\Executor;
use Phpcp\Agent\ValidationError;
use Phpcp\Domain\SettingsRepository;
use Phpcp\Driver\Mail\DkimManager;
use Phpcp\Driver\Template;
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

        $dkim = ['selector' => '', 'record' => '', 'created' => false];
        $records = [];
        $manager = new DkimManager(new Template($context->config->paths->templates()));

        if ($args['enabled']) {
            if ($manager->isInstalled($executor)) {
                $manager->apply($executor);
                $dkim = $manager->ensureKey($executor, (string) $domain['domain']);
            }

            // เรกคอร์ดที่ต้องมีจริงถึงจะส่งถึงปลายทาง — เพิ่มให้เองถ้าโดเมนใช้ DNS
            // ของเครื่องนี้ · ถ้าใช้ DNS ที่อื่น ค่าเหล่านี้คือสิ่งที่ต้องไปใส่เอง
            $records = $this->ensureDnsRecords($context, $domain, $dkim);
        } else {
            $manager->removeKey($executor, (string) $domain['domain']);
        }

        $result = $this->sync($executor, $context);

        return $result + [
            'domain' => (string) $domain['domain'],
            'enabled' => $args['enabled'],
            'dkim' => $dkim,
            'dns_records' => $records,
            'message' => $args['enabled']
                ? sprintf('เปิดเมลของ %s แล้ว — ต้องชี้เรกคอร์ด MX มาที่เครื่องนี้ด้วย', $domain['domain'])
                : sprintf('ปิดเมลของ %s แล้ว — กล่องยังอยู่ในระบบแต่ไม่รับเมลอีก', $domain['domain']),
        ];
    }

    /**
     * เรกคอร์ด DNS ที่ทำให้เมลของโดเมนนี้ส่งถึงปลายทางจริง
     *
     * สี่ตัวนี้ทำงานร่วมกัน ขาดตัวใดตัวหนึ่งเมลก็เข้าถังขยะ:
     *   MX     บอกว่าเมลของโดเมนนี้ส่งมาที่เครื่องไหน
     *   SPF    บอกว่าเครื่องไหนส่งเมลในนามโดเมนนี้ได้
     *   DKIM   กุญแจสาธารณะสำหรับตรวจลายเซ็น
     *   DMARC  บอกปลายทางว่าให้ทำยังไงเมื่อสองตัวบนไม่ผ่าน
     *
     * เขียนลงตารางของ panel เท่านั้น — จะไปถึง BIND9 จริงก็ต่อเมื่อเปิด `dns.enabled`
     * ไว้ · โดเมนที่ใช้ DNS ที่อื่นยังได้ค่าไปวางเองผ่านหน้าความพร้อมของเมล
     *
     * @param array<string,mixed> $domain
     * @param array{selector:string,record:string,created:bool} $dkim
     * @return list<array{type:string,name:string,value:string}>
     */
    private function ensureDnsRecords(Context $context, array $domain, array $dkim): array
    {
        $host = trim((new SettingsRepository($context->db))->get('mail.hostname'));
        $name = (string) $domain['domain'];

        $wanted = [
            ['type' => 'MX', 'name' => '@', 'value' => $host !== '' ? $host . '.' : $name . '.'],
            ['type' => 'TXT', 'name' => '@', 'value' => 'v=spf1 a mx ~all'],
            ['type' => 'TXT', 'name' => '_dmarc', 'value' => 'v=DMARC1; p=none; rua=mailto:postmaster@' . $name],
        ];

        if ($dkim['record'] !== '') {
            $wanted[] = [
                'type' => 'TXT',
                'name' => $dkim['selector'] . '._domainkey',
                'value' => $dkim['record'],
            ];
        }

        foreach ($wanted as $record) {
            $exists = $context->db->value(
                'SELECT count(*) FROM dns_records WHERE domain_id = :d AND type = :t AND name = :n',
                ['d' => (int) $domain['id'], 't' => $record['type'], 'n' => $record['name']],
                0,
            );

            // ไม่เขียนทับของเดิม — ผู้ดูแลอาจตั้ง SPF ที่รวมผู้ให้บริการอื่นไว้แล้ว
            // การเขียนทับจะทำให้เมลที่ส่งผ่านที่นั่นตรวจไม่ผ่านทันที
            if ((int) $exists > 0) {
                continue;
            }

            $context->db->insert('dns_records', [
                'domain_id' => (int) $domain['id'],
                'type' => $record['type'],
                'name' => $record['name'],
                'value' => $record['value'],
                'ttl' => 3600,
                // ตารางนี้ไม่มีคอลัมน์เวลา — เรกคอร์ด DNS ไม่ได้สนใจว่าใครเพิ่มเมื่อไหร่
                'priority' => $record['type'] === 'MX' ? 10 : null,
            ]);
        }

        return $wanted;
    }
}
