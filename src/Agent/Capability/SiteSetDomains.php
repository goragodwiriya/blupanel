<?php

declare(strict_types=1);

namespace Phpcp\Agent\Capability;

use Phpcp\Agent\Context;
use Phpcp\Agent\Executor\Executor;
use Phpcp\Agent\ValidationError;
use Phpcp\Driver\ConfigTransaction;
use Phpcp\Support\Validator;

/**
 * ตั้งรายการโดเมนย่อยและ alias ของเว็บไซต์ใหม่ทั้งชุด
 *
 * รับเป็น "รายการทั้งหมดที่ต้องการให้เป็น" ไม่ใช่ "เพิ่มทีละอัน" เพราะ vhost
 * ต้องถูกเขียนใหม่ทั้งไฟล์อยู่แล้ว การรับทั้งชุดทำให้สถานะในฐานข้อมูลกับในไฟล์
 * ตรงกันเสมอ และไม่มีทางเกิดกรณีเพิ่มสำเร็จแต่ vhost ไม่อัปเดต
 *
 * โดเมนที่เพิ่มต้องยังไม่ถูกใช้โดยเว็บไซต์อื่น — ตรวจก่อนแตะไฟล์ใด ๆ
 */
final class SiteSetDomains extends SiteCapability
{
    private const MAX_DOMAINS = 50;

    public static function name(): string
    {
        return 'site.set_domains';
    }

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
        return 'ตั้งรายการโดเมนย่อยและ alias ของเว็บไซต์';
    }

    public function validate(array $args): array
    {
        $siteId = Validator::requireInt($args, 'site_id', 1);

        $domains = [];
        foreach (Validator::requireStringList($args, 'domains', self::MAX_DOMAINS, 253) as $entry) {
            $domains[] = Validator::domain($entry);
        }

        return [
            'site_id' => $siteId,
            'domains' => array_values(array_unique($domains)),
            'type' => Validator::requireEnum(
                ['type' => $args['type'] ?? 'alias'],
                'type',
                ['alias', 'subdomain'],
            ),
        ];
    }

    public function run(array $args, Executor $executor, Context $context): array
    {
        $repository = $this->repository($context);
        $provisioner = $this->provisioner($context);

        $site = $this->loadSite($context, $args['site_id']);

        foreach ($args['domains'] as $domain) {
            if ($domain === $site->domain) {
                throw new ValidationError('โดเมนหลักอยู่ในรายการอยู่แล้ว ไม่ต้องเพิ่มซ้ำ');
            }

            // โดเมนต้องว่างจริง หรือเป็นของเว็บไซต์นี้เองอยู่แล้วในชนิดที่กำลังตั้ง
            $owner = $context->db->first(
                'SELECT site_id, type FROM domains WHERE domain = :d',
                ['d' => $domain],
            );

            if ($owner === null) {
                continue;
            }

            if ((int) $owner['site_id'] !== $site->id) {
                throw new ValidationError("โดเมน {$domain} ถูกใช้งานโดยเว็บไซต์อื่นอยู่แล้ว");
            }

            if ($owner['type'] !== $args['type'] && $owner['type'] !== 'primary') {
                throw new ValidationError(
                    "โดเมน {$domain} เป็น{$owner['type']} อยู่แล้ว — ลบออกก่อนถ้าต้องการเปลี่ยนชนิด",
                );
            }
        }

        // รวมโดเมนชนิดอื่นที่ไม่ได้แตะในรอบนี้เข้า vhost ด้วย
        // ไม่งั้นบันทึก alias จะทำให้ subdomain หายจาก ServerAlias
        $kept = $context->db->all(
            "SELECT domain FROM domains
             WHERE site_id = :id AND type IN ('alias','subdomain') AND type != :type
             ORDER BY domain",
            ['id' => $site->id, 'type' => $args['type']],
        );
        $allAliases = array_values(array_unique([
            ...array_map(static fn (array $row): string => (string) $row['domain'], $kept),
            ...$args['domains'],
        ]));

        $updated = new \Phpcp\Domain\Site(
            id: $site->id,
            name: $site->name,
            domain: $site->domain,
            owner: $site->owner,
            phpVersion: $site->phpVersion,
            sslMode: $site->sslMode,
            status: $site->status,
            aliases: $allAliases,
            memoryLimitMb: $site->memoryLimitMb,
            uploadLimitMb: $site->uploadLimitMb,
            maxChildren: $site->maxChildren,
            docrootOverride: $site->docrootOverride,
        );

        $transaction = new ConfigTransaction($executor);
        $transaction->write(
            $provisioner->webserver()->vhostPath($updated),
            $provisioner->webserver()->renderVhost($updated, $executor),
            0644,
        );

        $transaction->commit(static fn (): array => $provisioner->webserver()->testConfig($executor));
        $provisioner->webserver()->reload($executor);

        $this->syncDomainRows($context, $site->id, $args['domains'], $args['type']);

        return [
            'site_id' => $site->id,
            'domain' => $site->domain,
            'domains' => $allAliases,
            'count' => count($allAliases),
            'message' => sprintf(
                'ปรับรายการโดเมนของ %s เป็น %d รายการแล้ว',
                $site->domain,
                count($allAliases),
            ),
        ];
    }

    /** @param list<string> $domains */
    private function syncDomainRows(Context $context, int $siteId, array $domains, string $type): void
    {
        $context->db->transaction(static function ($db) use ($siteId, $domains, $type): void {
            // ลบเฉพาะชนิดที่กำลังตั้งใหม่ — ไม่แตะโดเมนหลัก, redirect และชนิดอื่น
            $db->run(
                'DELETE FROM domains WHERE site_id = :id AND type = :type',
                ['id' => $siteId, 'type' => $type],
            );

            $now = time();
            foreach ($domains as $domain) {
                $db->insert('domains', [
                    'site_id' => $siteId,
                    'domain' => $domain,
                    'type' => $type,
                    'created_at' => $now,
                ]);
            }
        });
    }
}
