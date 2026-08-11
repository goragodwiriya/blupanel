<?php

declare(strict_types=1);

namespace Phpcp\Domain;

use Phpcp\Kernel\Db;

/**
 * บัญชี MariaDB ประจำผู้ใช้ — ตัวที่ทำให้เข้า phpMyAdmin ได้โดยไม่ต้องพิมพ์รหัส
 *
 * ที่นี่เก็บและอ่าน **ciphertext เท่านั้น** ไม่มีเมธอดถอดรหัสเลยแม้แต่ตัวเดียว
 * การถอดรหัสอยู่ที่ชั้น agent จุดเดียว (`DbAccountCredentials`) ซึ่งเป็นชั้นที่ถือคีย์อยู่แล้ว
 * — ถ้าใส่ `decrypt()` ไว้ที่นี่ ชั้นเว็บจะเรียกได้ทันทีโดยไม่มีอะไรขวาง แล้วความลับ
 * จะไหลไปอยู่ในกระบวนการที่ผู้ใช้เข้าถึงได้โดยตรงแทนที่จะอยู่แต่ในกระบวนการที่รันด้วย root
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
     * คำนำหน้าชื่อฐานข้อมูลของผู้ใช้คนนี้
     *
     * ทำให้ลูกค้าคนละรายตั้งชื่อ `shop` ได้พร้อมกันโดยไม่ชนกัน (MariaDB มี namespace เดียว
     * ทั้งเครื่อง) และทำให้เห็นได้ทันทีว่าฐานข้อมูลไหนเป็นของใครโดยไม่ต้องเปิดตาราง grant
     * — แบบเดียวกับที่ cPanel ทำมาตลอด
     */
    public static function prefixFor(string $username): string
    {
        return $username.'_';
    }

    /**
     * ชื่อฐานข้อมูลที่ผู้ใช้คนนี้ตั้งได้จริง
     *
     * รับได้ทั้งชื่อที่ใส่คำนำหน้ามาแล้วและชื่อเปล่า — ผู้ใช้ที่พิมพ์ `customer_a_shop`
     * กับที่พิมพ์ `shop` ต้องได้ผลเหมือนกัน ไม่ใช่กลายเป็น `customer_a_customer_a_shop`
     */
    public static function qualify(string $username, string $name): string
    {
        $prefix = self::prefixFor($username);

        return str_starts_with($name, $prefix) ? $name : $prefix.$name;
    }
}
