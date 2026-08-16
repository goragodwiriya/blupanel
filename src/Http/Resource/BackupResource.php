<?php

declare(strict_types=1);

namespace Phpcp\Http\Resource;

/**
 * One backup file — **read from the real folder, never from a table row**
 *
 * As of PLAN-BACKUP-V2 item B4, the file itself is the source of truth · so
 * what this resource exports is only ever what `stat()` can answer, with no
 * status the system happened to note down yesterday mixed in
 *
 * `bytes` is a raw byte count, never "1.2 GB" — the screen formats it itself,
 * and sorting by size must only ever work from the number
 *
 * **`checksum` and `offsite_status` no longer exist** · both used to come from
 * a table row · a checksum recorded at creation time proves nothing once the
 * file is in the customer's hands — the value that actually means something
 * is one computed from the file right then, which restore and export already do every time
 *
 * `restorable` answers the question the screen genuinely needs: can the
 * restore button be clicked · a file that can't be matched to a website
 * (the customer copied it in themselves, or it came from a website that's
 * since been deleted) still shows up in the list because it genuinely counts
 * against their quota, but it can't be restored automatically
 */
final class BackupResource extends Resource
{
    public static function one(array $row): array
    {
        $type = self::string($row['type'] ?? '');

        return [
            // The filename is this resource's key — paired with `user_id` it refers to the exact file
            'name' => self::string($row['name'] ?? ''),
            'path' => self::string($row['path'] ?? ''),
            'type' => $type,
            // A raw catalog key, translated client-side via {LNG_${type_label}} — see backups.html
            'type_label' => $type === 'database' ? 'Database' : 'Website files',
            'domain' => self::string($row['domain'] ?? ''),
            'site_id' => (int) ($row['site_id'] ?? 0),
            'user_id' => (int) ($row['user_id'] ?? 0),
            'username' => self::string($row['username'] ?? ''),
            'size_bytes' => (int) ($row['bytes'] ?? 0),
            'modified_at' => (int) ($row['modified_at'] ?? 0),
            'restorable' => (bool) ($row['restorable'] ?? false),
            // The pill's color comes from the server, so the template can write `pill-${type_tone}` directly
            'type_tone' => $type === 'database' ? 'info' : 'ok',
        ];
    }
}
