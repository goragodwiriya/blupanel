<?php

declare(strict_types=1);

namespace Phpcp\Driver\Ssh;

use Phpcp\Agent\Capability\ServiceProbe;
use Phpcp\Agent\ExecutionFailed;
use Phpcp\Agent\Executor\Executor;
use Phpcp\Agent\ValidationError;
use Phpcp\Domain\UserAccount;
use Phpcp\Driver\ConfigTransaction;
use Phpcp\Support\BinaryPath;

/**
 * เปิด/ปิดการเข้าถึงไฟล์ผ่าน SFTP ให้บัญชีโฮสติ้ง — PLAN-V2 เฟส E4
 *
 * **หนึ่งบัญชีโฮสติ้ง = หนึ่ง SFTP login** (ดูเหตุผลเต็มใน `db/migrations/0013_sftp_access.sql`)
 * ไม่มีบัญชีย่อยต่อเว็บ เพราะทุกเว็บของผู้ใช้คนเดียวกันใช้ uid เดียวกันอยู่แล้วตั้งแต่ 0006
 *
 * **SFTP ไม่ใช่ FTP โดยเจตนา** — วิ่งบน OpenSSH ที่ติดตั้งและแข็งแรงอยู่แล้ว ไม่ต้องเปิด
 * daemon เพิ่ม ไม่ต้องเปิดพอร์ตเพิ่ม เข้ารหัสตลอดสาย และไม่มีปัญหา passive port range
 * ของ FTP ที่ทำให้ต้องเปิดพอร์ตเป็นช่วงกว้างบน firewall
 *
 * **สามด่านที่กันไม่ให้กลายเป็น shell access:**
 *   1. `ForceCommand internal-sftp` — บังคับให้ทำได้แค่ SFTP ต่อให้ผู้ใช้ขอ shell ก็ตาม
 *   2. shell ของบัญชียังเป็น `/usr/sbin/nologin` ตามเดิม (ไม่แตะ) — ด่านสำรองถ้า
 *      `Match` block หายไปด้วยเหตุใดก็ตาม
 *   3. `ChrootDirectory` ขังไว้ในบ้านตัวเอง มองไม่เห็นแม้แต่ `/etc/passwd` ของเครื่อง
 *
 * **ทำไมเขียนไฟล์แยกใน `sshd_config.d/` ไม่แตะ `sshd_config` หลัก:** ไฟล์หลักคือสิ่งที่
 * ถ้าพังแล้วเข้าเครื่องไม่ได้อีกเลย ({@see \Phpcp\Driver\SshManager} จึงยอมให้แก้แค่ 5 คีย์)
 * · ไฟล์แยกลบทิ้งก็คืนสภาพเดิมทันที และ `Match` block ที่ผิดพลาดกระทบแค่กลุ่มของตัวเอง
 *
 * **กับดักที่ต้องรู้: `Match` มี scope ไหลข้ามไฟล์** — ทุก directive ที่ตามหลัง `Match`
 * จะอยู่ใน scope นั้นไปเรื่อย ๆ รวมถึงบรรทัดใน**ไฟล์อื่นที่ include ทีหลัง**และในไฟล์หลัก
 * ถ้าไม่ปิด scope · ไฟล์ที่คลาสนี้เขียนจึงจบด้วย `Match all` เสมอเพื่อคืน scope กลับเป็น
 * global ไม่งั้นค่าตั้งที่เหลือของทั้งเครื่องจะกลายเป็นของกลุ่ม SFTP โดยไม่มีใครรู้
 */
final class SftpAccessManager
{
    /** ไฟล์ที่คลาสนี้เป็นเจ้าของทั้งไฟล์ — ไม่มีอะไรอื่นเขียนที่นี่ */
    public const CONFIG_FILE = '/etc/ssh/sshd_config.d/phpcp-sftp.conf';

    /** กลุ่มที่ `Match Group` จับ — บัญชีที่อยู่ในกลุ่มนี้เท่านั้นที่เข้า SFTP ได้ */
    public const GROUP = 'phpcp-sftp';

    /** @var list<string> systemctl อยู่ /usr/bin บน Debian/Ubuntu · /usr/sbin บางระบบ */
    public const SYSTEMCTL_PATHS = ['/usr/bin/systemctl', '/usr/sbin/systemctl'];

    private const GROUPADD = '/usr/sbin/groupadd';
    private const USERMOD = '/usr/sbin/usermod';
    private const GPASSWD = '/usr/bin/gpasswd';
    private const CHPASSWD = '/usr/sbin/chpasswd';
    private const SSHD = '/usr/sbin/sshd';
    private const MKDIR = '/usr/bin/mkdir';

    public function __construct(private readonly Executor $executor)
    {
    }

    /**
     * เปิด SFTP ให้บัญชีนี้ พร้อมตั้งรหัสผ่าน — เรียกซ้ำได้ (ใช้เปลี่ยนรหัสผ่านด้วย)
     *
     * @return array{username:string,chroot:string,group:string,config_written:bool}
     */
    public function enable(UserAccount $account, string $password): array
    {
        $this->assertPassword($password);

        $activation = $this->assertSshdRunning();
        $this->ensureGroup();
        $this->ensureConfig();

        // reload อยู่นอก ensureConfig() โดยเจตนา — ที่นั่นมี early return เมื่อไฟล์ตรงอยู่แล้ว
        // ถ้าเอา reload ไปไว้ข้างใน กรณี "เขียนไฟล์สำเร็จแต่ reload ล้มรอบก่อน" จะแก้ไม่ได้เลย
        // เพราะการกดซ้ำจะข้ามทั้งบล็อกไป · reload เร็วและไม่มีผลข้างเคียง เรียกทุกครั้งคุ้มกว่า
        $this->reloadSshd($activation);

        // ใส่เข้ากลุ่มก่อนตั้งรหัสผ่าน — ถ้าล้มกลางทาง บัญชีที่มีรหัสผ่านแต่ไม่อยู่ในกลุ่ม
        // ยังเข้าอะไรไม่ได้ (shell เป็น nologin) ปลอดภัยกว่าลำดับกลับกัน
        $this->addToGroup($account);
        $this->setPassword($account, $password);

        return [
            'username' => $account->username,
            'chroot' => $account->home(),
            'group' => self::GROUP,
            'config_written' => true,
        ];
    }

    /**
     * ปิด SFTP — เอาออกจากกลุ่มแล้วล็อกรหัสผ่าน
     *
     * ไม่ลบไฟล์ config เพราะบัญชีอื่นอาจยังใช้อยู่ · ไม่ลบบัญชีระบบเพราะเว็บของผู้ใช้
     * ยังต้องรันด้วย uid นั้นต่อไป
     */
    public function disable(UserAccount $account): array
    {
        $this->removeFromGroup($account);
        $this->lockPassword($account);

        return ['username' => $account->username, 'group' => self::GROUP];
    }

    /**
     * sshd ต้องทำงานอยู่ก่อน ถึงจะเปิด SFTP ได้ — SFTP วิ่งบน sshd ไม่ใช่ daemon ของตัวเอง
     *
     * **เจอจากการทดสอบบนเซิร์ฟเวอร์จริง (2026-08-10):** เครื่องที่ไม่ได้เปิด sshd ไว้ทำให้
     * `sshd -t` ล้มด้วย *"Missing privilege separation directory: /run/sshd"* เพราะ
     * `/run/sshd` ถูกสร้างโดย systemd ตอน start service (`RuntimeDirectory=sshd`) เท่านั้น
     * · ผลคือผู้ดูแลเห็นข้อความ "การตั้งค่าที่สร้างขึ้นไม่ผ่านการตรวจสอบ" ซึ่งชี้ไปผิดทาง
     * ทั้งที่ config ที่ phpcp เขียนถูกต้องทุกบรรทัด — ต้องบอกสาเหตุจริงตั้งแต่ก่อนแตะไฟล์
     *
     * @return array{unit:string,socket:bool} unit ที่พบว่าทำงานอยู่ และมาจาก socket หรือไม่
     */
    private function assertSshdRunning(): array
    {
        // ชื่อ unit ต่างกันตาม distro: Debian/Ubuntu ใช้ `ssh`, RHEL ใช้ `sshd`
        foreach (['ssh', 'sshd'] as $unit) {
            $status = ServiceProbe::read($this->executor, $unit);

            if ($status['running'] ?? false) {
                // socket activation คือค่าเริ่มต้นของ Ubuntu 22.10+ — ตัว `.service`
                // inactive ตลอดเวลาโดยไม่ผิดปกติ · ดู ServiceProbe::withSocketActivation()
                return ['unit' => $unit, 'socket' => ($status['activation'] ?? '') === 'socket'];
            }
        }

        throw new ExecutionFailed(
            "เปิด SFTP ไม่ได้เพราะบริการ SSH ไม่ได้ทำงานอยู่ — SFTP วิ่งบน sshd ไม่ใช่บริการแยก\n\n"
            . "เปิดใช้งานก่อนด้วย:\n"
            . "    sudo systemctl enable --now ssh\n\n"
            . 'แล้วลองเปิด SFTP อีกครั้ง',
        );
    }

    /**
     * `/run/sshd` ต้องมีอยู่ก่อน `sshd -t` ถึงจะผ่าน
     *
     * เป็น privilege separation directory ที่ปกติ systemd สร้างให้ผ่าน `RuntimeDirectory=sshd`
     * ตอน start `ssh.service` · **บนเครื่องที่ใช้ socket activation ตัว service นั้นไม่เคยรัน
     * เป็นตัวยาว ๆ เลย** ไดเรกทอรีจึงหายไปได้ทุกเมื่อ แล้ว `sshd -t` ล้มด้วย
     * *"Missing privilege separation directory"* ทั้งที่ไฟล์ตั้งค่าถูกต้องทุกบรรทัด
     *
     * สร้างเองแบบ idempotent ก่อนตรวจ — systemd จะดูแลต่อเองเมื่อ service ทำงานจริง
     * (0755 root:root คือสิทธิ์เดียวกับที่ systemd ตั้งให้ ไม่ได้ผ่อนอะไรลง)
     */
    private function ensureRuntimeDir(): void
    {
        $this->executor->exec([self::MKDIR, '-m', '0755', '-p', '/run/sshd'], timeout: 10);
    }

    /** กลุ่มต้องมีอยู่ก่อน `Match Group` ถึงจะมีความหมาย — สร้างแบบ lazy ครั้งแรกที่ใช้ */
    private function ensureGroup(): void
    {
        $result = $this->executor->exec([self::GROUPADD, '--system', '-f', self::GROUP], timeout: 15);

        // -f = มีอยู่แล้วถือว่าสำเร็จ · exit 9 คือ "มีอยู่แล้ว" ของ groupadd รุ่นเก่า
        if (!$result->ok() && $result->exitCode !== 9) {
            throw new ExecutionFailed('สร้างกลุ่ม ' . self::GROUP . ' ไม่สำเร็จ: ' . trim($result->stderr));
        }
    }

    /**
     * เขียนไฟล์ config แล้วตรวจด้วย `sshd -t` ก่อนยอมให้อยู่จริง
     *
     * ผ่าน {@see ConfigTransaction} เพื่อให้คืนไฟล์เดิมอัตโนมัติเมื่อ `sshd -t` ไม่ผ่าน —
     * ไฟล์ sshd ที่ผิดค้างอยู่แปลว่า sshd รีสตาร์ตไม่ขึ้นครั้งถัดไป แล้วไม่มีใครเข้าเครื่องได้
     */
    private function ensureConfig(): void
    {
        if ($this->executor->exists($this->executor->path(self::CONFIG_FILE))
            && $this->executor->readFile($this->executor->path(self::CONFIG_FILE)) === $this->configContent()) {
            return;   // ตรงกับที่ต้องการอยู่แล้ว ไม่ต้องแตะ sshd เลย
        }

        $this->assertIncludeSupported();

        $tx = new ConfigTransaction($this->executor);
        $tx->write(self::CONFIG_FILE, $this->configContent(), 0644);

        $tx->commit(function (): array {
            $this->ensureRuntimeDir();

            $test = $this->executor->exec([self::SSHD, '-t'], timeout: 20);

            return [$test->ok(), $this->explainSshdTest(trim($test->output() . $test->stderr))];
        });

    }

    /**
     * sshd ต้องอ่าน config ใหม่ ไม่งั้นไฟล์ที่เพิ่งเขียนไม่มีผลอะไรเลย
     *
     * **เจอจากการทดสอบบนเซิร์ฟเวอร์จริง (2026-08-10):** เดิมเขียนคอมเมนต์ไว้ว่า "การเชื่อมต่อ
     * ใหม่อ่าน config ใหม่เองอยู่แล้ว" ซึ่ง**ผิด** — sshd อ่าน `sshd_config` ตอน start เท่านั้น
     * แล้ว fork ตัวลูกจากภาพในหน่วยความจำนั้นมารับแต่ละการเชื่อมต่อ · ผลคือ `Match Group`
     * ที่เพิ่งเขียนถูกมองข้ามทั้งหมด: ผู้ใช้ได้ shell `nologin` แทน `internal-sftp` แล้ว
     * sftp client เห็นข้อความ "This account is currently not available" เป็น protocol
     * ที่พังจึงรายงาน *"Received message too long"* ซึ่งชี้ไปคนละทางกับต้นตอโดยสิ้นเชิง
     *
     * ใช้ `reload` (SIGHUP) ไม่ใช่ `restart` — อ่าน config ใหม่โดยไม่ตัดการเชื่อมต่อที่ทำงานอยู่
     * ซึ่งสำคัญมากเพราะผู้ดูแลอาจกำลัง ssh อยู่ผ่านเซสชันเดียวกันนั้น
     *
     * **ยกเว้นเครื่องที่ใช้ socket activation** (ค่าเริ่มต้นของ Ubuntu 22.10+) ที่นั่นไม่มี
     * sshd ตัวยาว ๆ ให้ reload เลย — `systemd` ปลุก `ssh@<n>.service` ใหม่ต่อการเชื่อมต่อ
     * หนึ่งครั้ง แต่ละตัวจึงอ่าน `sshd_config` สด ๆ อยู่แล้วโดยธรรมชาติ · การสั่ง
     * `systemctl reload ssh.service` บนเครื่องแบบนั้นล้มเสมอ ("Job type reload is not
     * applicable") ซึ่งจะกลายเป็นข้อความผิดพลาดที่ผู้ดูแลแก้ตามแล้วไม่มีอะไรดีขึ้น
     *
     * @param array{unit:string,socket:bool} $activation ผลจาก assertSshdRunning()
     */
    private function reloadSshd(array $activation): void
    {
        if ($activation['socket']) {
            return;   // การเชื่อมต่อถัดไปได้ config ใหม่อยู่แล้ว ไม่มีอะไรต้องปลุก
        }

        $systemctl = BinaryPath::resolve($this->executor, self::SYSTEMCTL_PATHS, 'systemd');

        foreach (['ssh', 'sshd'] as $unit) {
            $result = $this->executor->exec([$systemctl, 'reload', $unit], timeout: 20);

            if ($result->ok()) {
                return;
            }
        }

        throw new ExecutionFailed(
            "เขียนไฟล์ตั้งค่าแล้วแต่สั่ง sshd ให้อ่านค่าใหม่ไม่สำเร็จ\n\n"
            . "ไฟล์บนดิสก์ถูกต้องแล้ว — สั่งเองด้วย `sudo systemctl reload ssh` "
            . 'แล้ว SFTP จะใช้งานได้ทันทีโดยไม่ต้องตั้งค่าใหม่',
        );
    }

    /**
     * แปลผลของ `sshd -t` ให้บอกทางแก้ ไม่ใช่แค่ส่งข้อความดิบต่อ
     *
     * ข้อความ "Missing privilege separation directory" ชี้ไปที่สภาพแวดล้อม (sshd ไม่ได้รัน)
     * ไม่ใช่ที่ไฟล์ config ที่เพิ่งเขียน — ถ้าไม่แปล ผู้ดูแลจะไล่หาที่ผิดในไฟล์ที่ถูกต้องอยู่แล้ว
     */
    private function explainSshdTest(string $raw): string
    {
        if (str_contains($raw, 'Missing privilege separation directory')) {
            return $raw . "\n\nข้อความนี้แปลว่า /run/sshd ไม่มีอยู่ ซึ่งเกิดเมื่อบริการ SSH "
                . "ไม่ได้ทำงาน (systemd สร้างไดเรกทอรีนี้ตอน start เท่านั้น)\n"
                . 'ไม่ใช่ปัญหาของไฟล์ตั้งค่าที่ระบบเพิ่งเขียน — เปิดบริการด้วย `sudo systemctl enable --now ssh` แล้วลองใหม่';
        }

        return 'sshd -t: ' . $raw;
    }

    /**
     * `sshd_config` หลักต้องมีบรรทัด `Include` ถึงจะอ่านไฟล์ที่เราเขียน
     *
     * Ubuntu 22.04+ / Debian 12+ มีให้เป็นค่าเริ่มต้น แต่เครื่องที่ตั้งค่าเองอาจไม่มี —
     * ถ้าเขียนไฟล์ทิ้งไว้เฉย ๆ โดยไม่มีใครอ่าน ผู้ดูแลจะเห็นว่า "เปิด SFTP สำเร็จ" แต่
     * ล็อกอินไม่ได้จริงและหาสาเหตุไม่เจอ — ต้องบอกให้ชัดตั้งแต่ตอนนี้แทนที่จะล้มเงียบ
     */
    private function assertIncludeSupported(): void
    {
        $main = $this->executor->path(\Phpcp\Driver\SshManager::CONFIG);

        if (!$this->executor->exists($main)) {
            throw new ExecutionFailed('ไม่พบ ' . \Phpcp\Driver\SshManager::CONFIG . ' — เครื่องนี้อาจไม่ได้ติดตั้ง OpenSSH server');
        }

        $content = $this->executor->readFile($main);

        // จับ `Include /etc/ssh/sshd_config.d/*.conf` โดยไม่สนช่องว่างและตัวพิมพ์
        if (preg_match('/^\s*Include\s+.*sshd_config\.d/mi', $content) !== 1) {
            throw new ExecutionFailed(
                "sshd_config ของเครื่องนี้ไม่มีบรรทัด Include ของ /etc/ssh/sshd_config.d/\n\n"
                . "เพิ่มบรรทัดนี้ไว้**บนสุด**ของ /etc/ssh/sshd_config แล้วลองใหม่:\n"
                . "    Include /etc/ssh/sshd_config.d/*.conf\n\n"
                . 'ต้องอยู่บนสุดเพราะ OpenSSH ใช้ค่าแรกที่เจอสำหรับแต่ละคีย์',
            );
        }
    }

    /**
     * เนื้อไฟล์ config — คงที่เสมอ ไม่ขึ้นกับผู้ใช้คนใดคนหนึ่ง
     *
     * ใช้ `%u` ให้ OpenSSH แทนชื่อผู้ใช้เอง จึงไม่ต้องเขียนไฟล์ใหม่ทุกครั้งที่เพิ่มบัญชี
     * (และไม่มีชื่อผู้ใช้ของลูกค้าโผล่ในไฟล์ config ของระบบ)
     */
    private function configContent(): string
    {
        $usersDir = rtrim(\Phpcp\Kernel\Paths::usersDir(), '/');

        return <<<CONF
            # จัดการโดย phpcp โดยอัตโนมัติ — ห้ามแก้ไขด้วยมือ (PLAN-V2 เฟส E4)
            # เปิด/ปิด SFTP ต่อบัญชีทำผ่านหน้าผู้ใช้ของ panel

            Match Group {$this->groupName()}
                # chroot ที่ไดเรกทอรี**แม่** ไม่ใช่บ้านของผู้ใช้ — เจตนา ไม่ใช่ความมักง่าย
                #
                # OpenSSH บังคับว่า ChrootDirectory ต้องเป็นของ root และ group/other เขียนไม่ได้
                # แต่บ้านของผู้ใช้ต้องเป็นของผู้ใช้ (เขียนได้) และกลุ่มต้องเป็น www-data
                # เพื่อให้เว็บเซิร์ฟเวอร์เดินผ่านไปถึง docroot ได้ — สองข้อนี้ขัดกันโดยตรง
                #
                # การฝืนเปลี่ยนเจ้าของบ้านเป็น root ทำให้ต้องเลือกอย่างใดอย่างหนึ่ง:
                # เว็บตอบ 403 ทั้งเว็บ (www-data เดินผ่านไม่ได้) หรือเปิด other เป็น r-x
                # (ลูกค้ารายอื่นเห็นโครงสร้างบ้านกันได้ = ลดชั้นความปลอดภัยที่ §7.1 ข้อ 2 ห้าม)
                #
                # ไดเรกทอรีแม่เป็น root:root 0711 อยู่แล้วตั้งแต่ AccountProvisioner::createHome()
                # จึงตรงเงื่อนไขของ OpenSSH พอดีโดยไม่ต้องแก้อะไรเลย · 0711 ยังแปลว่าผู้ใช้
                # `ls /` แล้วเห็นว่างเปล่า — มองไม่เห็นด้วยซ้ำว่ามีลูกค้ารายอื่นอยู่บนเครื่อง
                ChrootDirectory {$usersDir}
                # บังคับ SFTP เท่านั้น ต่อให้ขอ shell ก็ไม่ได้ · internal-sftp จำเป็นสำหรับ chroot
                # เพราะ sftp-server แบบไฟล์ภายนอกอยู่นอก chroot จึงเรียกไม่ถึง
                #
                # -d /%u พาเข้าบ้านตัวเองทันทีหลังล็อกอิน (เส้นทางสัมพัทธ์กับ chroot)
                # ผู้ใช้จึงไม่ต้องรู้ว่าตัวเองอยู่ใต้ไดเรกทอรีรวม และ cd ออกไปไหนไม่ได้อยู่ดี
                ForceCommand internal-sftp -d /%u
                # ตัดทุกอย่างที่ไม่ใช่การรับส่งไฟล์ — กัน SFTP กลายเป็นทางออกสู่เครือข่ายภายใน
                AllowTcpForwarding no
                AllowAgentForwarding no
                AllowStreamLocalForwarding no
                PermitTunnel no
                X11Forwarding no
                # ลูกค้าส่วนใหญ่ใช้รหัสผ่านกับโปรแกรม FTP client — เปิดเฉพาะกลุ่มนี้
                # ไม่กระทบค่า PasswordAuthentication ระดับเครื่องที่ผู้ดูแลตั้งไว้
                PasswordAuthentication yes

            # คืน scope กลับเป็น global — **ห้ามลบบรรทัดนี้**
            # ทุก directive ที่ตามหลัง Match จะอยู่ใน scope นั้นไปเรื่อย ๆ ข้ามไฟล์ด้วย
            # ถ้าไม่ปิด ค่าตั้งที่เหลือของทั้งเครื่องจะกลายเป็นของกลุ่ม SFTP โดยไม่มีใครรู้
            Match all

            CONF;
    }

    private function groupName(): string
    {
        return self::GROUP;
    }

    private function addToGroup(UserAccount $account): void
    {
        $result = $this->executor->exec(
            [self::USERMOD, '-a', '-G', self::GROUP, $account->username],
            timeout: 15,
        );

        if (!$result->ok()) {
            throw new ExecutionFailed('เพิ่มบัญชีเข้ากลุ่ม SFTP ไม่สำเร็จ: ' . trim($result->stderr));
        }
    }

    private function removeFromGroup(UserAccount $account): void
    {
        // ล้มได้ถ้าไม่ได้อยู่ในกลุ่มอยู่แล้ว — ถือว่าเป็นผลลัพธ์ที่ต้องการ (ปิดอยู่แล้ว)
        $this->executor->exec([self::GPASSWD, '-d', $account->username, self::GROUP], timeout: 15);
    }

    /**
     * ตั้งรหัสผ่านผ่าน stdin ของ `chpasswd` ไม่ใช่อาร์กิวเมนต์
     *
     * รหัสผ่านที่ส่งทางอาร์กิวเมนต์อ่านได้จาก `/proc/<pid>/cmdline` โดยผู้ใช้อื่นบนเครื่อง
     * เดียวกัน — เหตุผลเดียวกับที่ `SshDestination` ไม่รองรับ password auth
     */
    private function setPassword(UserAccount $account, string $password): void
    {
        $result = $this->executor->exec(
            [self::CHPASSWD],
            timeout: 15,
            stdin: $account->username . ':' . $password . "\n",
        );

        if (!$result->ok()) {
            throw new ExecutionFailed('ตั้งรหัสผ่าน SFTP ไม่สำเร็จ: ' . trim($result->stderr));
        }
    }

    /** `!` นำหน้าแฮชทำให้รหัสผ่านใช้ไม่ได้ โดยไม่ลบแฮชทิ้ง (เปิดกลับได้ด้วยรหัสใหม่) */
    private function lockPassword(UserAccount $account): void
    {
        $this->executor->exec([self::USERMOD, '--lock', $account->username], timeout: 15);
    }

    /**
     * กฎรหัสผ่านของ SFTP — เข้มกว่าค่าปริยายเพราะเป็นบัญชีที่เข้าถึงไฟล์ได้โดยตรง
     * และเปิดให้ล็อกอินด้วยรหัสผ่านจากอินเทอร์เน็ต (ต่างจากบัญชี panel ที่มี rate limit
     * และ 2FA ของตัวเอง — sshd ไม่มีทั้งสองอย่างนั้นให้)
     */
    private function assertPassword(string $password): void
    {
        if (mb_strlen($password) < 12) {
            throw new ValidationError('รหัสผ่าน SFTP ต้องยาวอย่างน้อย 12 ตัวอักษร');
        }

        // อักขระควบคุมและ : ทำลายรูปแบบ `user:password` ที่ chpasswd อ่านจาก stdin
        if (preg_match('/[\x00-\x1F\x7F:]/', $password) === 1) {
            throw new ValidationError('รหัสผ่าน SFTP ต้องไม่มีเครื่องหมาย : หรืออักขระควบคุม');
        }
    }
}
