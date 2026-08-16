<?php

declare(strict_types=1);

namespace Phpcp\Http\Resource;

/**
 * One DNS record
 *
 * `priority` is null for every type except MX — genuinely returns null, not
 * 0, because "no priority" and "priority equal to 0" mean different things in DNS
 */
final class DnsRecordResource extends Resource
{
    public static function one(array $row): array
    {
        $id = (int) ($row['id'] ?? 0);

        return [
            'id' => $id,
            // Deliberately a duplicate of id — domain.html itself has ?id=
            // (the domain's id) in the URL. Putting {id} in this table's
            // data-row-actions would get replaced by RouterManager.render
            // (js/ui.js) with the domain's id before TableManager even sees
            // the template — every row's delete button would hit the same
            // record · a name that never collides with any page's own
            // parameter fixes it at the source (see DomainResource::row_id, same issue)
            'row_id' => $id,
            'domain_id' => (int) ($row['domain_id'] ?? 0),
            'type' => self::string($row['type'] ?? ''),
            'name' => self::string($row['name'] ?? ''),
            'value' => self::string($row['value'] ?? ''),
            'ttl' => (int) ($row['ttl'] ?? 3600),
            'priority' => self::intOrNull($row['priority'] ?? null),
        ];
    }
}
