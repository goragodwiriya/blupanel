<?php

declare(strict_types=1);

namespace Phpcp\Agent\Capability;

use Phpcp\Agent\Capability;
use Phpcp\Agent\Context;
use Phpcp\Agent\Executor\Executor;
use Phpcp\Agent\PermissionDenied;
use Phpcp\Agent\ValidationError;
use Phpcp\Driver\Db\MariaDbManager;
use Phpcp\Kernel\Db;
use Phpcp\Security\Permissions;

/**
 * The shared base for capabilities that manage MariaDB databases
 *
 * Keeps two separate facts clearly apart:
 *   - the databases_ table in panel.db is "databases the panel knows about and manages"
 *   - MariaDB's SHOW DATABASES is "databases that genuinely exist on the machine"
 * These two can diverge (someone created one by hand through the CLI), so the
 * screen has to show both, rather than trusting one side and hiding the other's truth.
 */
abstract class DbCapability implements Capability
{
    /** How long the database-size cache lives — information_schema is expensive, doesn't need to be fresh on every click */
    private const SIZES_CACHE_TTL = 60;

    protected function manager(): MariaDbManager
    {
        return new MariaDbManager();
    }

    /** The cache file for sizes() results, under the panel's data directory */
    protected function sizesCachePath(Context $context): string
    {
        return $context->config->paths->data.'/cache/db-sizes.json';
    }

    /**
     * Database sizes with a short-lived cache — cuts down repeated information_schema scans
     *
     * @return array<string,int>
     */
    protected function cachedSizes(Executor $executor, Context $context): array
    {
        $path = $executor->path($this->sizesCachePath($context));

        if ($executor->exists($path)) {
            try {
                $payload = json_decode($executor->readFile($path), true);
                if (is_array($payload)
                    && isset($payload['at'], $payload['sizes'])
                    && is_array($payload['sizes'])
                    && (time() - (int) $payload['at']) < self::SIZES_CACHE_TTL
                ) {
                    $sizes = [];
                    foreach ($payload['sizes'] as $name => $bytes) {
                        $sizes[(string) $name] = (int) $bytes;
                    }

                    return $sizes;
                }
            } catch (\Throwable) {
                // The cache couldn't be read — fetch fresh from MariaDB
            }
        }

        $sizes = $this->manager()->sizes($executor);
        $json = json_encode(['at' => time(), 'sizes' => $sizes], JSON_UNESCAPED_UNICODE);
        if ($json !== false) {
            try {
                $executor->writeFile($path, $json, 0640);
            } catch (\Throwable) {
                // Failing to write the cache never fails the listing
            }
        }

        return $sizes;
    }

    /** Clears the size cache after creating/dropping a database, so the screen's numbers match the machine */
    protected function invalidateSizesCache(Executor $executor, Context $context): void
    {
        $path = $executor->path($this->sizesCachePath($context));
        if ($executor->exists($path)) {
            try {
                $executor->removePath($path);
            } catch (\Throwable) {
                // Couldn't delete it — let it expire on its own via the TTL
            }
        }
    }

    /**
     * Checks that the caller genuinely has permission over this database
     *
     * A customer can only touch their own databases — guards against IDOR.
     * Always checked in the agent too, never relying on the web tier's check
     * alone. Checked against `owner_user_id` directly, not through `sites` —
     * a database left unlinked to any website (the create form allows that)
     * still has a real owner and must still be manageable by them.
     */
    protected function assertOwnership(Context $context, string $database): void
    {
        $actor = $context->actor;

        if ($actor->userId === 0
            || in_array($actor->role, [Permissions::SUPERADMIN, Permissions::SYSADMIN], true)) {
            return;
        }

        $owned = (int) $context->db->value(
            'SELECT count(*) FROM databases_ WHERE db_name = :name AND owner_user_id = :user',
            ['name' => $database, 'user' => $actor->userId],
            0,
        );

        if ($owned === 0) {
            throw new PermissionDenied('You do not have permission to manage this database');
        }
    }

    /** A website a database can be bound to — a site owner can only bind their own site */
    protected function assertSiteAccess(Context $context, int $siteId): void
    {
        if ($siteId === 0) {
            return;
        }

        $actor = $context->actor;

        if ($actor->userId === 0
            || in_array($actor->role, [Permissions::SUPERADMIN, Permissions::SYSADMIN], true)) {
            return;
        }

        $owned = (int) $context->db->value(
            'SELECT count(*) FROM sites WHERE id = :id AND owner_user_id = :user',
            ['id' => $siteId, 'user' => $actor->userId],
            0,
        );

        if ($owned === 0) {
            throw new PermissionDenied('You do not have permission over the specified website');
        }
    }

    /** @return array<string,mixed> */
    protected function findDatabase(Db $db, string $name): array
    {
        $row = $db->first('SELECT * FROM databases_ WHERE db_name = :n', ['n' => $name]);

        if ($row === null) {
            throw new ValidationError("Database {$name} not found in the system");
        }

        return $row;
    }

    /** Generates a random database password — never uses characters that need escaping in SQL or a connection string */
    protected static function randomPassword(int $length = 24): string
    {
        $alphabet = 'ABCDEFGHJKMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';
        $max = strlen($alphabet) - 1;
        $out = '';

        for ($i = 0; $i < $length; $i++) {
            $out .= $alphabet[random_int(0, $max)];
        }

        return $out;
    }
}
