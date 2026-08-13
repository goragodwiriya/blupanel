<?php

declare(strict_types=1);

namespace Phpcp\Agent\Capability;

use Phpcp\Agent\Context;
use Phpcp\Agent\ExecutionFailed;
use Phpcp\Agent\Executor\Executor;
use Phpcp\Domain\DnsRecord;
use Phpcp\Driver\Dns\BindZoneManager;
use Phpcp\Driver\WebServer\CustomConfig;
use Phpcp\Support\Validator;

/**
 * แก้เรกคอร์ดทั้ง zone พร้อมกันด้วยการเขียนเป็นข้อความแบบ zone file
 *
 * ## ทำไมไม่ให้แก้ไฟล์บนดิสก์ตรง ๆ
 *
 * zone file ถูกเขียนใหม่ทั้งไฟล์จากฐานข้อมูลทุกครั้งที่มีคนแตะเรกคอร์ดสักรายการ —
 * การแก้ไฟล์ตรง ๆ จึงได้ผลทันที ดูถูกต้องทุกอย่าง แล้ววันหนึ่งหายไปเงียบ ๆ ตอนที่มีคน
 * กดเพิ่มเรกคอร์ดอื่น · เป็นกับดักเดียวกับที่ทั้งโปรเจกต์นี้หลบมาตลอด
 *
 * ที่นี่จึง**แปลงข้อความกลับเป็นเรกคอร์ดในฐานข้อมูล** แล้วให้ระบบเขียนไฟล์เองตามปกติ ·
 * ในสายตาผู้ใช้คือ "แก้ไฟล์แล้วมีผล" แต่ฐานข้อมูลยังเป็นแหล่งความจริงเดียว หน้าตาราง
 * กับไฟล์จึงตรงกันเสมอ และไม่มีอะไรหายทีหลัง
 *
 * ## ทำไมต้องเป็นการ "แทนที่ทั้งชุด" ไม่ใช่ "เพิ่มเข้าไป"
 *
 * งานจริงที่ทำให้ต้องมีหน้านี้คือการย้ายเมลไปผู้ให้บริการอื่น (เช่นใส่ MX ห้าตัวของ
 * Google) ซึ่ง **ต้องลบ MX เดิมออกให้หมดก่อน** ไม่งั้นเมลจะวิ่งไปสองทางพร้อมกัน ·
 * การไล่กดลบทีละรายการแล้วค่อยเพิ่มทีละรายการเป็นช่วงเวลาที่ zone อยู่ในสภาพครึ่ง ๆ
 * กลาง ๆ จริง ๆ บนอินเทอร์เน็ต · แทนที่ทั้งชุดในคำสั่งเดียวจึงปลอดภัยกว่า ไม่ใช่แค่สะดวกกว่า
 *
 * ## ลำดับการทำงานและการคืนค่า
 *
 * แปลงข้อความทั้งหมดให้ผ่านก่อนแตะฐานข้อมูล → สลับเรกคอร์ดในทรานแซกชันเดียว →
 * เขียนไฟล์ zone (ซึ่งมี `named-checkzone` อยู่ข้างในและคืนไฟล์เดิมให้เองเมื่อไม่ผ่าน) →
 * **ถ้าขั้นสุดท้ายล้ม ต้องคืนเรกคอร์ดเดิมกลับฐานข้อมูลด้วย** ไม่งั้นระบบจะเหลือค่าใหม่
 * ที่ BIND ไม่ยอมรับค้างอยู่ แล้วการ sync ครั้งถัดไปของใครก็ตามจะล้มตามไปโดยไม่มีใครรู้ว่าเพราะอะไร
 */
final class DnsZoneImport extends DomainCapability
{
    public static function name(): string
    {
        return 'dns.zone_import';
    }

    /** สิทธิ์เดียวกับการเพิ่ม/ลบเรกคอร์ดทีละรายการ — เป็นงานเดียวกัน ทำทีเดียวหลายรายการ */
    public function permission(): string
    {
        return 'domain.manage';
    }

    public function isMutating(): bool
    {
        return true;
    }

    public function summary(): string
    {
        return 'แทนที่เรกคอร์ดทั้ง zone จากข้อความ zone file';
    }

    public function validate(array $args): array
    {
        return [
            'domain_id' => Validator::requireInt($args, 'domain_id', 1),
            'content' => CustomConfig::assertContent((string) ($args['content'] ?? '')),
        ];
    }

    public function run(array $args, Executor $executor, Context $context): array
    {
        $domain = $this->loadDomain($context, $args['domain_id']);
        $domainId = (int) $domain['id'];
        $name = (string) $domain['domain'];

        // แปลงให้ผ่านทั้งหมดก่อนแตะฐานข้อมูล — ข้อผิดพลาดบรรทัดที่ 40 ต้องไม่ทิ้ง
        // เรกคอร์ด 39 รายการแรกไว้ในสภาพที่ผู้ใช้ไม่ได้สั่ง
        $records = DnsRecord::parseZoneFile($name, $args['content']);

        $previous = $context->db->all(
            'SELECT type, name, value, ttl, priority FROM dns_records WHERE domain_id = :id',
            ['id' => $domainId],
        );

        $context->db->transaction(function () use ($context, $domainId, $records): void {
            $this->replace($context, $domainId, $records);
        });

        try {
            $sync = (new BindZoneManager($executor, $context->config, $context->db))->writeZone($domain);
        } catch (\Throwable $e) {
            // คืนเรกคอร์ดเดิม — ดูเหตุผลที่หัวคลาสว่าทำไมการปล่อยค่าใหม่ค้างไว้อันตรายกว่า
            $context->db->transaction(function () use ($context, $domainId, $previous): void {
                $this->replace($context, $domainId, $previous);
            });

            throw new ExecutionFailed(
                "เรกคอร์ดชุดใหม่ไม่ผ่านการตรวจของ BIND9 จึงคืนค่าเดิมทั้งหมดแล้ว\n\n" . $e->getMessage(),
            );
        }

        return [
            'domain' => $name,
            'record_count' => count($records),
            'previous_count' => count($previous),
            'pushed' => (bool) ($sync['pushed'] ?? false),
            'message' => sprintf(
                'แทนที่เรกคอร์ดของ %s แล้ว — เดิม %d รายการ ตอนนี้ %d รายการ%s',
                $name,
                count($previous),
                count($records),
                ($sync['pushed'] ?? false) ? '' : ' (ยังไม่ได้ส่งไปยัง BIND9: ' . ($sync['message'] ?? '') . ')',
            ),
        ];
    }

    /**
     * เขียนทับเรกคอร์ดทั้งหมดของโดเมนหนึ่ง
     *
     * @param list<array<string,mixed>> $records
     */
    private function replace(Context $context, int $domainId, array $records): void
    {
        $context->db->run('DELETE FROM dns_records WHERE domain_id = :id', ['id' => $domainId]);

        foreach ($records as $record) {
            $context->db->insert('dns_records', [
                'domain_id' => $domainId,
                'type' => $record['type'],
                'name' => $record['name'],
                'value' => $record['value'],
                'ttl' => (int) $record['ttl'],
                'priority' => $record['priority'] === null ? null : (int) $record['priority'],
                'created_at' => time(),
            ]);
        }
    }
}
