<?php

declare(strict_types=1);

namespace Phpcp\Driver;

use Phpcp\Agent\ExecutionFailed;
use Phpcp\Agent\Executor\Executor;
use Phpcp\Domain\UserAccount;

/**
 * สร้างและรื้อบัญชีระบบของผู้ใช้โฮสติ้งหนึ่งคน
 *
 * แยกออกจาก SiteProvisioner ตั้งแต่ migration 0006 เพราะบัญชีระบบไม่ได้ผูกกับเว็บอีกแล้ว
 * แต่ผูกกับผู้ใช้: หนึ่งผู้ใช้ = หนึ่ง uid = หนึ่งบ้าน = หลายเว็บ
 *
 * บัญชีถูกสร้างแบบ lazy คือตอนสร้างเว็บแรกเท่านั้น — ผู้ดูแลระบบที่ไม่เคยโฮสต์เว็บ
 * จึงไม่มีบัญชี Linux ให้เป็นพื้นที่ผิวโดยเปล่าประโยชน์
 */
final class AccountProvisioner
{
    public function __construct(
        private readonly WebServer\WebServerDriver $webserver,
        private readonly bool $sharedOwner = false,
    ) {
    }

    /**
     * สร้างบัญชีและบ้านให้พร้อมใช้ — เรียกซ้ำได้ ไม่มีผลข้างเคียงถ้ามีอยู่แล้ว
     *
     * @return array{uid:int,gid:int}
     */
    public function ensure(Executor $executor, UserAccount $account): array
    {
        $this->assertNameAvailable($executor, $account);

        $result = $executor->exec([
            '/usr/sbin/useradd',
            '--system',
            '--no-create-home',
            '--home-dir', $account->home(),
            '--shell', '/usr/sbin/nologin',
            '--comment', 'phpcp hosting account '.$account->username,
            $account->username,
        ], timeout: 20);

        // exit 9 = มีผู้ใช้อยู่แล้ว ถือว่าใช้ได้ (เช่นสร้างเว็บที่สองของคนเดิม
        // หรือสร้างซ้ำหลังล้มกลางทางรอบก่อน)
        if (!$result->ok() && $result->exitCode !== 9) {
            throw new ExecutionFailed(
                'สร้างบัญชีระบบไม่สำเร็จ: '.trim($result->stderr),
                $result->exitCode,
                $result->stderr,
            );
        }

        $this->createHome($executor, $account);

        return $this->lookup($executor, $account->username);
    }

    /**
     * ชื่อนี้ยังว่างอยู่ หรือเป็นบัญชีที่ panel สร้างไว้เองหรือไม่
     *
     * **ด่านที่เชื่อถือได้จริงของกฎชื่อผู้ใช้** — รายชื่อต้องห้ามที่เขียนตายตัวใน
     * `UserRepository::RESERVED_USERNAMES` ย่อมตกหล่นบัญชีที่ผู้ดูแลสร้างเองทีหลัง
     * และถ้าปล่อยผ่าน เราจะ `chown -R` ไฟล์เว็บไปให้ uid ของคนอื่นที่มีอยู่ก่อนแล้ว
     * ซึ่งแปลว่าคนนั้นอ่านและแก้ไฟล์ของลูกค้าได้ทั้งหมด
     *
     * ตัดสินจาก home directory ที่บันทึกไว้ใน /etc/passwd ไม่ใช่จากชื่อ เพราะเป็น
     * หลักฐานเดียวที่บอกได้ว่าบัญชีนี้เป็นของ panel จริง
     */
    private function assertNameAvailable(Executor $executor, UserAccount $account): void
    {
        $entry = $executor->exec(['/usr/bin/getent', 'passwd', $account->username], timeout: 10);

        if (!$entry->ok()) {
            return; // ไม่พบ = ชื่อว่าง
        }

        // รูปแบบ: name:x:uid:gid:comment:home:shell
        $fields = explode(':', trim($entry->output()));
        $home = $fields[5] ?? '';

        if ($home === $account->home()) {
            return; // บัญชีของ panel เอง สร้างไว้รอบก่อน
        }

        throw new ExecutionFailed(
            "ชื่อ {$account->username} ถูกใช้เป็นบัญชีของระบบอยู่แล้ว (บ้านอยู่ที่ {$home}) — "
            .'ต้องเปลี่ยนชื่อผู้ใช้ก่อนจึงจะสร้างเว็บได้',
        );
    }

    /**
     * โครงสร้างบ้านของผู้ใช้
     *
     * `0711` ที่ชั้นแม่ (`/srv/phpcp/users`) สำคัญกว่าที่เห็น: ผู้ใช้เดินเข้าบ้านตัวเองได้
     * แต่สั่ง `ls` ดูรายชื่อบ้านทั้งหมดไม่ได้ — ลูกค้าจึงไม่รู้ด้วยซ้ำว่าบนเครื่องนี้
     * มีลูกค้ารายอื่นอยู่กี่รายและชื่ออะไร
     */
    private function createHome(Executor $executor, UserAccount $account): void
    {
        $executor->makeDirectory($executor->path(\Phpcp\Kernel\Paths::usersDir()), 0711);
        $executor->makeDirectory($executor->path($account->home()), 0750);

        foreach ([$account->domainsDir(), $account->logDir()] as $dir) {
            $executor->makeDirectory($executor->path($dir), 0750);
        }

        // tmp และ .ssh ต้องไม่ให้กลุ่มของเว็บเซิร์ฟเวอร์อ่าน — session ของ PHP อยู่ใน tmp
        // และการอ่าน session ของคนอื่นคือการสวมสิทธิ์ผู้ใช้เว็บนั้นได้ทันที
        $executor->makeDirectory($executor->path($account->tmpDir()), 0700);
        $executor->makeDirectory($executor->path($account->sshDir()), 0700);

        $this->setOwnership($executor, $account);
    }

    /**
     * เจ้าของคือผู้ใช้ กลุ่มคือกลุ่มของเว็บเซิร์ฟเวอร์
     *
     * เหตุผลเดียวกับที่ SiteProvisioner อธิบายไว้: ถ้าตั้งกลุ่มเป็นของผู้ใช้เอง
     * เว็บเซิร์ฟเวอร์จะเดินผ่านไดเรกทอรี 0750 ไม่ได้เลย ไฟล์สแตติกทุกไฟล์จะตอบ 403
     * รวมถึงไฟล์ตรวจสอบของ Let's Encrypt ซึ่งทำให้ต่ออายุใบรับรองไม่ได้ด้วย
     */
    private function setOwnership(Executor $executor, UserAccount $account): void
    {
        if ($this->sharedOwner) {
            return; // filesystem เก็บเจ้าของไม่ได้ — SiteProvisioner พิสูจน์ให้แล้ว
        }

        $executor->exec([
            '/usr/bin/chown',
            '-R',
            $account->username.':'.$this->webserver->runAsGroup(),
            $executor->path($account->home()),
        ], timeout: 60);

        // tmp กับ .ssh ต้องเป็นของผู้ใช้ล้วน ไม่ใช่กลุ่มของเว็บเซิร์ฟเวอร์
        //
        // **หมายเหตุสำหรับ SFTP (เฟส E4):** เคยเปลี่ยนบ้านชั้นบนสุดเป็น root เพื่อให้ตรง
        // เงื่อนไข `ChrootDirectory` ของ OpenSSH แล้วพบว่าทำให้ www-data เดินผ่านไปถึง
        // docroot ไม่ได้ (เว็บตอบ 403 ทั้งเว็บ) · ทางแก้ที่ถูกคือ chroot ที่ไดเรกทอรี**แม่**
        // ซึ่งเป็น root:root 0711 อยู่แล้ว — บ้านของผู้ใช้จึงไม่ต้องเปลี่ยนอะไรเลย
        // ดูคำอธิบายเต็มใน SftpAccessManager::configContent()
        foreach ([$account->tmpDir(), $account->sshDir()] as $private) {
            $executor->exec([
                '/usr/bin/chown',
                '-R',
                $account->username.':'.$account->username,
                $executor->path($private),
            ], timeout: 30);
        }
    }

    /**
     * ลบบัญชีและบ้านทั้งหมด — เรียกได้ก็ต่อเมื่อผู้ใช้ไม่เหลือเว็บแล้วเท่านั้น
     *
     * ไม่ใช้ `userdel --remove` เพราะมันลบทุกอย่างใต้ home รวมถึงสิ่งที่ผู้ดูแล
     * อาจ mount ไว้ · ผู้เรียกเป็นคนตัดสินใจเรื่องไฟล์เอง
     */
    public function remove(Executor $executor, UserAccount $account): void
    {
        // ล้มก็ไม่เป็นไร — เว็บถูกลบไปแล้ว บัญชีที่ค้างไม่ได้ทำอันตราย
        // และการโยน error ที่นี่จะทำให้ผู้ใช้เข้าใจว่าลบไม่สำเร็จทั้งที่ลบไปแล้ว
        $executor->exec(['/usr/sbin/userdel', $account->username], timeout: 20);
    }

    /** @return array{uid:int,gid:int} */
    public function lookup(Executor $executor, string $user): array
    {
        $uid = $executor->exec(['/usr/bin/id', '-u', $user], timeout: 10);
        $gid = $executor->exec(['/usr/bin/id', '-g', $user], timeout: 10);

        if (!$uid->ok() || !$gid->ok()) {
            throw new ExecutionFailed("อ่าน uid ของผู้ใช้ {$user} ไม่ได้");
        }

        return ['uid' => (int) $uid->output(), 'gid' => (int) $gid->output()];
    }
}
