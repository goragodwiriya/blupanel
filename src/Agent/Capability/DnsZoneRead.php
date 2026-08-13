<?php

declare(strict_types=1);

namespace Phpcp\Agent\Capability;

use Phpcp\Agent\Context;
use Phpcp\Agent\Executor\Executor;
use Phpcp\Domain\DnsRecord;
use Phpcp\Driver\Dns\BindZoneManager;
use Phpcp\Support\Validator;

/**
 * อ่าน zone file **ตัวจริงบนดิสก์** ของโดเมนหนึ่ง พร้อมบอกว่ามันตรงกับระบบหรือยัง
 *
 * ## ทำไมต้องอ่านไฟล์จริง ทั้งที่ประกอบใหม่จากฐานข้อมูลก็ได้
 *
 * สองอย่างนี้ต่างกันได้ และ **เวลาที่มันต่างกันคือเวลาที่ผู้ดูแลต้องการคำตอบมากที่สุด** —
 * "ทำไมค่าที่เห็นในหน้าจอไม่ตรงกับที่ DNS ตอบจริง" · การแสดงค่าที่ประกอบใหม่ในนาทีนั้น
 * คือการยืนยันความเข้าใจผิดของเขาด้วยหน้าจอที่ดูน่าเชื่อถือ
 *
 * สาเหตุที่ทำให้ต่างกันมีจริงหลายทาง: `dns.enabled` ปิดอยู่ (ไฟล์ไม่เคยถูกเขียนเลย) ·
 * การเขียนรอบที่แล้วล้มกลางคัน · มีคนแก้ไฟล์ด้วยมือผ่าน SSH · หรือ zone ถูกเขียนไว้
 * ก่อนที่จะมีการแก้เรกคอร์ดครั้งล่าสุดแล้ว sync ล้มเงียบ
 *
 * ## วิธีวัดว่า "ต่างกัน"
 *
 * เทียบ**ความหมาย** ไม่ใช่ตัวอักษร — แปลงไฟล์บนดิสก์กลับเป็นเรกคอร์ดแล้วเทียบกับ
 * ฐานข้อมูล · การเทียบข้อความตรง ๆ จะรายงานว่าต่างกันทุกครั้งเพราะ serial ของ SOA
 * เปลี่ยนทุกการเขียน ซึ่งเป็นสัญญาณเตือนที่ดังตลอดเวลาจนไม่มีใครฟัง
 */
final class DnsZoneRead extends DomainCapability
{
    public static function name(): string
    {
        return 'dns.zone_read';
    }

    public function permission(): string
    {
        return 'domain.view';
    }

    public function isMutating(): bool
    {
        return false;
    }

    public function summary(): string
    {
        return 'อ่าน zone file ตัวจริงบนดิสก์';
    }

    public function validate(array $args): array
    {
        return ['domain_id' => Validator::requireInt($args, 'domain_id', 1)];
    }

    public function run(array $args, Executor $executor, Context $context): array
    {
        $domain = $this->loadDomain($context, $args['domain_id']);
        $name = (string) $domain['domain'];
        $path = BindZoneManager::zonePath($context->config, $name);
        $resolved = $executor->path($path);

        if (!$executor->exists($resolved)) {
            return [
                'domain' => $name,
                'path' => $path,
                'exists' => false,
                'content' => '',
                'drift' => false,
                'drift_reason' => '',
            ];
        }

        try {
            $content = $executor->readFile($resolved);
        } catch (\Throwable $e) {
            return [
                'domain' => $name,
                'path' => $path,
                'exists' => true,
                'content' => '',
                'drift' => true,
                'drift_reason' => 'อ่านไฟล์ไม่ได้: ' . $e->getMessage(),
            ];
        }

        [$drift, $reason] = $this->compare($context, (int) $domain['id'], $name, $content);

        return [
            'domain' => $name,
            'path' => $path,
            'exists' => true,
            'content' => $content,
            'drift' => $drift,
            'drift_reason' => $reason,
        ];
    }

    /**
     * ไฟล์บนดิสก์ตรงกับเรกคอร์ดในระบบไหม
     *
     * ไฟล์ที่แปลงกลับไม่ได้ (มีเรกคอร์ดชนิดที่ระบบยังไม่รองรับ หรือมีคนแก้ด้วยมือจนผิด
     * รูปแบบ) **นับเป็นต่างกัน พร้อมบอกเหตุผลตามจริง** — ไม่ใช่กลืนข้อผิดพลาดแล้วรายงาน
     * ว่าตรงกัน ซึ่งจะกลายเป็นการบอกว่า "ทุกอย่างปกติ" ในไฟล์ที่ระบบอ่านไม่ออกด้วยซ้ำ
     *
     * @return array{0:bool,1:string}
     */
    private function compare(Context $context, int $domainId, string $name, string $content): array
    {
        $rows = $context->db->all(
            'SELECT * FROM dns_records WHERE domain_id = :id ORDER BY type, name',
            ['id' => $domainId],
        );

        try {
            $onDisk = DnsRecord::parseZoneFile($name, $content);
        } catch (\Throwable $e) {
            return [true, 'ไฟล์บนดิสก์มีสิ่งที่ระบบแปลงกลับไม่ได้ — ' . $e->getMessage()];
        }

        $expected = self::fingerprint(array_map(
            static fn (array $row): array => [
                'type' => (string) $row['type'],
                'name' => (string) $row['name'],
                'value' => (string) $row['value'],
                'ttl' => (int) $row['ttl'],
                'priority' => $row['priority'] === null ? null : (int) $row['priority'],
            ],
            $rows,
        ));

        $actual = self::fingerprint($onDisk);

        if ($expected === $actual) {
            return [false, ''];
        }

        return [true, sprintf(
            'ไฟล์บนดิสก์มี %d รายการ ระบบมี %d รายการ — กดซิงก์เพื่อเขียนไฟล์ใหม่จากค่าในระบบ',
            count($onDisk),
            count($rows),
        )];
    }

    /**
     * ลายนิ้วมือของชุดเรกคอร์ดที่ไม่ขึ้นกับลำดับ
     *
     * @param list<array<string,mixed>> $records
     * @return list<string>
     */
    private static function fingerprint(array $records): array
    {
        $keys = array_map(
            static fn (array $r): string => sprintf(
                '%s|%s|%s|%d|%s',
                $r['type'],
                $r['name'],
                $r['value'],
                $r['ttl'],
                $r['priority'] === null ? '-' : $r['priority'],
            ),
            $records,
        );

        sort($keys);

        return $keys;
    }
}
