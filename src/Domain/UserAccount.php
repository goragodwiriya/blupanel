<?php

declare(strict_types=1);

namespace Phpcp\Domain;

use Phpcp\Kernel\Paths;

/**
 * บัญชีระบบของผู้ใช้หนึ่งคน พร้อมเส้นทางทั้งหมดที่อนุมานจากชื่อ
 *
 * ตั้งแต่ migration 0006 หน่วยของการแยกสิทธิ์คือ **ผู้ใช้** ไม่ใช่เว็บไซต์:
 * หนึ่งผู้ใช้ = หนึ่ง uid = หนึ่งบ้าน = หลายเว็บ = FPM pool เท่าจำนวนเวอร์ชัน PHP ที่ใช้จริง
 *
 * เดิมหนึ่งเว็บ = หนึ่ง uid ทำให้ลูกค้าที่มี 5 เว็บได้บัญชี Linux 5 บัญชีที่ไม่เกี่ยวข้องกันเลย
 * ทั้งที่เป็นคนเดียวกัน — SFTP ต้องมี 5 บัญชี โควตาดิสก์นับแยกไม่ตรงกับที่ขายจริง
 * และมี pool 5 ตัวกินหน่วยความจำโดยไม่ได้แยกอะไรที่ควรแยก
 *
 * **สิ่งที่แลกไป:** เว็บของผู้ใช้*คนเดียวกัน*อ่านไฟล์กันได้และแชร์คิว process กัน
 * รับได้เพราะเป็นทรัพย์สินของคนเดียวกัน และเป็นโมเดลเดียวกับ cPanel/Plesk/DirectAdmin ·
 * **การแยกระหว่างผู้ใช้ต่างคนยังแน่นเท่าเดิมทุกประการ** ซึ่งเป็นขอบเขตที่สำคัญจริง
 */
final readonly class UserAccount
{
    public function __construct(
        public int $userId,
        public string $username,
    ) {
    }

    /**
     * @param array<string,mixed> $row แถวจากตาราง users
     */
    public static function fromRow(array $row): self
    {
        // system_user เป็น null ได้เมื่อผู้ใช้ยังไม่เคยมีเว็บ (บัญชีระบบสร้างแบบ lazy)
        // กรณีนั้นใช้ชื่อผู้ใช้ซึ่งจะกลายเป็นชื่อบัญชีตอน provision จริง
        $name = (string) ($row['system_user'] ?? '');

        return new self(
            userId: (int) $row['id'],
            username: self::assertSystemUser($name !== '' ? $name : (string) ($row['username'] ?? '')),
        );
    }

    /**
     * ชื่อบัญชีระบบต้องปลอดภัยพอจะเป็นชื่อผู้ใช้ Linux, ชื่อโฟลเดอร์ และชื่อ pool พร้อมกัน
     *
     * ตรวจซ้ำที่นี่แม้ `UserRepository::assertUsername()` จะตรวจตอนสร้างแล้ว เพราะค่านี้
     * ไปโผล่ในเส้นทางไฟล์และไฟล์ config ที่รันด้วยสิทธิ์ root — ค่าที่หลุดเข้ามาทางอื่น
     * (เช่นแถวที่ถูกแก้ด้วยมือในฐานข้อมูล) ต้องถูกจับที่นี่ก่อนถึงปลายทาง
     */
    public static function assertSystemUser(string $user): string
    {
        if (preg_match('/^[a-z][a-z0-9_-]{2,31}$/', $user) !== 1) {
            throw new \InvalidArgumentException(
                "ชื่อบัญชีระบบไม่ถูกต้อง: {$user} — ต้องเป็น a-z 0-9 _ - ยาว 3-32 ตัว ขึ้นต้นด้วยตัวอักษร",
            );
        }

        return $user;
    }

    /** บ้านของผู้ใช้ — ทุกอย่างของผู้ใช้คนนี้อยู่ใต้เส้นทางนี้ */
    public function home(): string
    {
        return Paths::usersDir().'/'.$this->username;
    }

    /** โฟลเดอร์แม่ของเว็บทุกแห่ง */
    public function domainsDir(): string
    {
        return $this->home().'/domains';
    }

    /** บ้านของเว็บหนึ่งแห่ง */
    public function siteRoot(string $domain): string
    {
        return $this->domainsDir().'/'.$domain;
    }

    /** ที่พักไฟล์ชั่วคราวของ pool — ใช้ร่วมกันทุกเว็บของผู้ใช้คนนี้ */
    public function tmpDir(): string
    {
        return $this->home().'/tmp';
    }

    /**
     * log ของ PHP-FPM
     *
     * อยู่ระดับผู้ใช้ไม่ใช่ระดับเว็บ เพราะ pool เดียวรับหลายเว็บ — การแยกเป็นรายเว็บ
     * จะได้ไฟล์ที่ pool เขียนลงไม่ได้จริงหรือได้ log ที่ไม่ครบ
     * (log ของเว็บเซิร์ฟเวอร์ยังแยกรายเว็บเหมือนเดิม เพราะ vhost แยกกันจริง)
     */
    public function logDir(): string
    {
        return $this->home().'/logs';
    }

    public function sshDir(): string
    {
        return $this->home().'/.ssh';
    }

    public function authorizedKeys(): string
    {
        return $this->sshDir().'/authorized_keys';
    }

    /** ชื่อ pool ในไฟล์ config ของ FPM */
    public function poolName(string $phpVersion): string
    {
        return $this->username.'-'.$phpVersion;
    }

    /** socket ของ pool — หนึ่งตัวต่อเวอร์ชัน PHP ที่ผู้ใช้คนนี้ใช้จริง */
    public function fpmSocket(string $phpVersion): string
    {
        return '/run/php/phpcp-'.$this->username.'-'.$phpVersion.'.sock';
    }

    public function fpmPoolFile(string $phpVersion): string
    {
        return '/etc/php/'.$phpVersion.'/fpm/pool.d/phpcp-'.$this->username.'.conf';
    }

    public function phpErrorLog(string $phpVersion): string
    {
        return $this->logDir().'/php-'.$phpVersion.'-error.log';
    }

    public function phpSlowLog(string $phpVersion): string
    {
        return $this->logDir().'/php-'.$phpVersion.'-slow.log';
    }
}
