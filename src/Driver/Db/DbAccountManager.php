<?php

declare(strict_types=1);

namespace Phpcp\Driver\Db;

use Phpcp\Agent\Executor\Executor;
use Phpcp\Domain\DbAccountRepository;
use Phpcp\Domain\UserAccount;
use Phpcp\Security\Secret;

/**
 * บัญชี MariaDB ประจำผู้ใช้ — ตัวกลางระหว่างผู้ใช้ MariaDB จริงกับตาราง db_accounts
 *
 * แยกออกมาจาก capability เพราะมีผู้เรียกสองกลุ่มที่คิดคนละแบบ:
 *   - `db.account.*` ทำงานกับบัญชีของ **actor เอง** (ผู้ใช้กดเปิด phpMyAdmin)
 *   - `db.create` ทำงานกับบัญชีของ **เจ้าของเว็บ** ซึ่งอาจไม่ใช่คนที่กดสร้าง
 *     (ผู้ดูแลสร้างฐานข้อมูลให้ลูกค้า)
 *
 * ถ้าเขียนตรรกะนี้ไว้ในคลาสฐานของ capability กลุ่มแรก ผู้เรียกกลุ่มที่สองจะต้องสืบทอด
 * ตามไปด้วยแล้วได้เมธอด `currentAccount()` ติดมาโดยไม่ควรมี — ซึ่งเป็นจุดที่คนอ่านโค้ด
 * ทีหลังจะเผลอใช้ผิดตัวได้ง่ายที่สุด
 */
final class DbAccountManager
{
    /** ยาวได้เพราะไม่มีใครต้องพิมพ์รหัสนี้เลยสักครั้ง — panel กรอกให้ทั้งหมด */
    private const PASSWORD_LENGTH = 32;

    public function __construct(
        private readonly MariaDbManager $manager,
        private readonly DbAccountRepository $accounts,
        private readonly Secret $secret,
    ) {
    }

    /**
     * บัญชีของผู้ใช้คนนี้ต้องมีอยู่จริงและรหัสในฐานข้อมูลต้องตรงกับของจริงบนเครื่อง
     *
     * เรียกซ้ำได้ · คืนรหัสผ่านก็ต่อเมื่อเพิ่งตั้งใหม่ (null = ของเดิมยังใช้ได้อยู่)
     * ผู้เรียกที่ไม่ต้องการรหัสจึงไม่ต้องรับความลับนั้นไว้ในตัวแปรโดยไม่จำเป็น
     */
    public function ensure(Executor $executor, UserAccount $account): ?string
    {
        $stored = $this->accounts->find($account->userId);

        if ($stored !== null
            && $this->manager->userExists($executor, (string) $stored['mysql_user'], (string) $stored['host'])
        ) {
            return null;
        }

        return $this->rotate($executor, $account);
    }

    /**
     * ตั้งรหัสใหม่ให้บัญชี สร้างผู้ใช้ให้ถ้ายังไม่มี
     *
     * ไม่ใช้ `CREATE USER IF NOT EXISTS` เพราะต้องรองรับกรณีที่แถวใน panel.db หายไป
     * แต่ผู้ใช้ MariaDB ยังอยู่ (เช่นกู้คืนฐานข้อมูลเก่ามา) — ผลลัพธ์ที่ต้องการคือ
     * "รหัสที่เก็บไว้ตรงกับรหัสจริง" ไม่ว่าจะเริ่มจากสภาพไหน
     */
    public function rotate(Executor $executor, UserAccount $account): string
    {
        $password = $this->randomPassword();
        $host = 'localhost';

        if ($this->manager->userExists($executor, $account->username, $host)) {
            $this->manager->setPassword($executor, $account->username, $host, $password);
        } else {
            $this->manager->createUser($executor, $account->username, $host, $password);
        }

        $this->accounts->store(
            $account->userId,
            $account->username,
            $host,
            $this->secret->encrypt($password),
        );

        return $password;
    }

    /**
     * ปรับสิทธิ์ระดับเซิร์ฟเวอร์ให้ตรงกับบทบาทปัจจุบันของผู้ใช้
     *
     * **ต้องเรียกทุกครั้งที่ออกบัตรให้ ไม่ใช่แค่ตอนสร้างบัญชี** — บทบาทเปลี่ยนได้ทีหลัง
     * ถ้าให้สิทธิ์ตอนสร้างอย่างเดียว ผู้ดูแลที่ถูกลดเป็นลูกค้าจะยังถือสิทธิ์เห็นทุก
     * ฐานข้อมูลอยู่ตลอดไป ซึ่งเป็นบั๊กชนิดเดียวกับที่เคยเกิดกับโควตา (สถานะที่อนุมาน
     * จากบทบาทแต่ไม่ได้ตามไปอัปเดตเมื่อบทบาทเปลี่ยน)
     *
     * ผู้ดูแลได้สิทธิ์ทั้งเครื่องเพื่อไม่ต้องไปตั้งรหัสให้บัญชี root ของ MariaDB
     * ซึ่งใช้ `unix_socket` และแตะได้เฉพาะ root ของระบบปฏิบัติการ
     */
    public function syncPrivileges(Executor $executor, UserAccount $account, bool $isAdmin): void
    {
        $stored = $this->accounts->find($account->userId);

        if ($stored === null) {
            return;
        }

        $this->manager->setGlobalPrivileges(
            $executor,
            (string) $stored['mysql_user'],
            (string) $stored['host'],
            $isAdmin,
        );
    }

    /**
     * รหัสผ่านที่ใช้ล็อกอินจริงของบัญชีนี้ — สร้างให้ถ้ายังไม่มี
     *
     * @return array{user:string,host:string,password:string,provisioned:bool}
     */
    public function credentials(Executor $executor, UserAccount $account): array
    {
        $fresh = $this->ensure($executor, $account);

        if ($fresh !== null) {
            return [
                'user' => $account->username,
                'host' => 'localhost',
                'password' => $fresh,
                'provisioned' => true,
            ];
        }

        $stored = $this->accounts->find($account->userId);

        return [
            'user' => (string) $stored['mysql_user'],
            'host' => (string) $stored['host'],
            'password' => $this->secret->decrypt((string) $stored['password_enc']),
            'provisioned' => false,
        ];
    }

    /**
     * ให้บัญชีนี้เข้าถึงฐานข้อมูลที่เพิ่งสร้าง
     *
     * ต้องทำทุกครั้งที่สร้างฐานข้อมูลใหม่ ไม่ใช่ตอนเปิด phpMyAdmin — ไม่งั้นผู้ใช้จะเปิด
     * phpMyAdmin แล้วไม่เห็นฐานข้อมูลที่เพิ่งสร้างเมื่อสักครู่ ซึ่งดูเหมือนระบบพัง
     */
    public function grantDatabase(Executor $executor, UserAccount $account, string $database): void
    {
        $this->manager->grant($executor, $database, $account->username, 'localhost', 'full');
    }

    private function randomPassword(): string
    {
        $alphabet = 'ABCDEFGHJKMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';
        $max = strlen($alphabet) - 1;
        $out = '';

        for ($i = 0; $i < self::PASSWORD_LENGTH; $i++) {
            $out .= $alphabet[random_int(0, $max)];
        }

        return $out;
    }
}
