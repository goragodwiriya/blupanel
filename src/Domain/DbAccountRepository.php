<?php

declare(strict_types=1);

namespace Phpcp\Domain;

use Phpcp\Kernel\Db;

/**
 * A user's own MariaDB account — the thing that lets phpMyAdmin be reached without typing a password
 *
 * This class stores and reads **ciphertext only** — there isn't a single decryption
 * method here. Decryption lives in exactly one place at the agent layer
 * (`DbAccountCredentials`), which is the layer that already holds the key — if
 * `decrypt()` were added here, the web tier could call it immediately with nothing
 * standing in the way, and the secret would end up flowing into a process the user
 * can reach directly, instead of staying confined to a process that runs as root.
 */
final class DbAccountRepository
{
    public function __construct(private readonly Db $db)
    {
    }

    /** @return array<string,mixed>|null */
    public function find(int $userId): ?array
    {
        return $this->db->first('SELECT * FROM db_accounts WHERE user_id = :u', ['u' => $userId]);
    }

    /** @return array<string,mixed>|null */
    public function findByMysqlUser(string $mysqlUser): ?array
    {
        return $this->db->first('SELECT * FROM db_accounts WHERE mysql_user = :m', ['m' => $mysqlUser]);
    }

    public function store(int $userId, string $mysqlUser, string $host, string $passwordEnc): void
    {
        $now = time();

        if ($this->find($userId) === null) {
            $this->db->insert('db_accounts', [
                'user_id' => $userId,
                'mysql_user' => $mysqlUser,
                'host' => $host,
                'password_enc' => $passwordEnc,
                'created_at' => $now,
            ]);

            return;
        }

        $this->db->update('db_accounts', [
            'mysql_user' => $mysqlUser,
            'host' => $host,
            'password_enc' => $passwordEnc,
            'rotated_at' => $now,
        ], ['user_id' => $userId]);
    }

    public function delete(int $userId): void
    {
        $this->db->run('DELETE FROM db_accounts WHERE user_id = :u', ['u' => $userId]);
    }

    /**
     * This user's database name prefix
     *
     * Lets two different customers both name a database `shop` at the same time
     * without colliding (MariaDB has a single machine-wide namespace), and makes it
     * immediately obvious which database belongs to whom without opening the grant
     * table — the same approach cPanel has always used.
     */
    public static function prefixFor(string $username): string
    {
        return $username.'_';
    }

    /**
     * The database name this user actually ends up creating
     *
     * Accepts both an already-prefixed name and a bare one — a user who types
     * `customer_a_shop` and one who types `shop` must get the same result, not
     * turn into `customer_a_customer_a_shop`.
     */
    public static function qualify(string $username, string $name): string
    {
        $prefix = self::prefixFor($username);

        return str_starts_with($name, $prefix) ? $name : $prefix.$name;
    }
}
