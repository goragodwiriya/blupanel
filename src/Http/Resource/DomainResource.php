<?php

declare(strict_types=1);

namespace Phpcp\Http\Resource;

/**
 * One domain tied to a website
 *
 * `type` says whether it can be deleted: `primary` can't be deleted on its
 * own (the whole website must be deleted instead), while
 * `subdomain`/`alias`/`redirect` can — `removable` is sent directly so the
 * screen never has to know this rule itself and risk showing a delete button that errors when clicked
 */
final class DomainResource extends Resource
{
    public static function one(array $row): array
    {
        $type = self::string($row['type'] ?? '');

        $id = (int) ($row['id'] ?? 0);

        $domain = [
            'id' => $id,
            // Deliberately a duplicate of id — used on pages whose own URL
            // already has a ?id= (e.g. site.html is /site?id={website}).
            // Putting {id} in data-row-actions there would get replaced by
            // RouterManager.render (js/ui.js) with the "page's" id before
            // TableManager even sees the template — every row's button would
            // go to that same id instead of the row's own · a name that never
            // collides with any page's own parameter fixes it at the source
            'row_id' => $id,
            'site_id' => (int) ($row['site_id'] ?? 0),
            'domain' => self::string($row['domain'] ?? ''),
            'type' => $type,
            'removable' => in_array($type, ['subdomain', 'alias', 'redirect'], true),
            'redirect_target' => self::string($row['redirect_target'] ?? ''),
            'redirect_code' => self::intOrNull($row['redirect_code'] ?? null),
            'created_at' => self::intOrNull($row['created_at'] ?? null),
        ];

        // Comes from a JOIN when fetching the system-wide domain list — absent when fetching a single website's own
        foreach ([ 'primary_domain' => 'site_domain', 'site_status' => 'site_status'] as $column => $key) {
            if (array_key_exists($column, $row)) {
                $domain[$key] = self::string($row[$column]);
            }
        }

        return $domain;
    }
}
