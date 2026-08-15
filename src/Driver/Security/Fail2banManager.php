<?php

declare(strict_types=1);

namespace Phpcp\Driver\Security;

use Phpcp\Agent\ExecutionFailed;
use Phpcp\Agent\Executor\Executor;
use Phpcp\Agent\ValidationError;
use Phpcp\Domain\Site;
use Phpcp\Driver\ConfigTransaction;
use Phpcp\Support\BinaryPath;

/**
 * จำกัดอัตราคำขอต่อเว็บไซต์ด้วย fail2ban — PLAN-V2 เฟส E5
 *
 * **ทำไมไม่ใช้โมดูลของเว็บเซิร์ฟเวอร์** (เหตุผลเต็มใน `db/migrations/0016_site_rate_limit.sql`):
 * Apache ไม่มีเครื่องมือจำกัดจำนวนคำขอในตัว — `mod_ratelimit` จำกัดแบนด์วิดท์เท่านั้น
 * ส่วน `mod_evasive` นับแยกต่อ child process จนเกณฑ์จริงกลายเป็นค่าที่ตั้งคูณจำนวน child
 * · fail2ban ทำงานอยู่บนเครื่องแล้ว ใช้โค้ดชุดเดียวได้ทั้ง Apache และ nginx
 *
 * ไฟล์ที่เขียน (หนึ่งชุดต่อเว็บ ชื่ออิงจาก `system_user` ซึ่งถูกตรวจรูปแบบมาแล้ว):
 *
 *   /etc/fail2ban/filter.d/phpcp-<user>.conf   ตัวจับบรรทัดใน access log
 *   /etc/fail2ban/jail.d/phpcp-<user>.conf     เกณฑ์และระยะเวลาแบน
 *
 * **แยกไฟล์ต่อเว็บ ไม่ใช่ไฟล์รวม** เพราะการลบเว็บหนึ่งต้องไม่แตะ config ของเว็บอื่นเลย
 * — ไฟล์รวมที่ถูกเขียนใหม่ทุกครั้งจะพังทั้งเครื่องถ้าการเขียนครั้งเดียวผิดพลาด
 */
final class Fail2banManager
{
    /** ค้นตามลำดับ — Debian/Ubuntu วางที่ /usr/bin ส่วนบางดิสทริบิวชันวางที่ /usr/local/bin */
    private const CLIENT_PATHS = ['/usr/bin/fail2ban-client', '/usr/local/bin/fail2ban-client'];

    private const FILTER_DIR = '/etc/fail2ban/filter.d';
    private const JAIL_DIR = '/etc/fail2ban/jail.d';

    /** นำหน้าทุกไฟล์ที่ panel เป็นเจ้าของ — กันไม่ให้แตะ jail ที่ผู้ดูแลเขียนเอง */
    private const PREFIX = 'phpcp-';

    /**
     * jail ของหน้าเข้าสู่ระบบของ panel เอง — หนึ่งตัวต่อเครื่อง ไม่ผูกกับเว็บไซต์ใด
     *
     * ต่างจาก jail รายเว็บตรงที่อ่าน **audit log** ไม่ใช่ access log · การล็อกอินผิด
     * ถูกบันทึกลงตาราง `audit_log` ซึ่ง fail2ban อ่านไม่ได้ แต่ {@see \Phpcp\Security\AuditLog}
     * เขียนสำเนาเป็น JSON บรรทัดละเหตุการณ์ไว้ด้วย — นั่นคือไฟล์ที่ jail นี้เฝ้า
     */
    public const PANEL_LOGIN_JAIL = self::PREFIX . 'panel-login';

    /**
     * โหมดของ jail — สิ่งที่จะเกิดขึ้นเมื่อมีคนเข้าเกณฑ์
     *
     *   off     ไม่มี jail อยู่บนเครื่องเลย ไม่ตรวจ ไม่กินทรัพยากร
     *   notify  ตรวจแล้วส่งข้อความ **ไม่แตะ firewall** — ผู้ดูแลตัดสินใจเอง
     *   ban     ตรวจแล้วสั่ง firewall แบนทันที
     *
     * `notify` มีไว้เพราะการแบนอัตโนมัติไม่เหมาะกับทุกเครื่อง · ลูกค้าที่คนทั้งองค์กร
     * ออกเน็ตผ่าน IP เดียวกันทำให้การแบนหนึ่งครั้งกลายเป็นการตัดคนทั้งองค์กร ·
     * โหมดนี้ให้ข้อมูลเท่ากันโดยไม่ตัดสินใจแทนคน
     */
    public const MODE_OFF = 'off';
    public const MODE_NOTIFY = 'notify';
    public const MODE_BAN = 'ban';

    /**
     * jail นี้เป็นของ panel หรือไม่ — ตัดสินจากคำนำหน้าที่ panel เป็นคนตั้งเท่านั้น
     *
     * ใช้กันไม่ให้คำสั่งจากหน้าเว็บไปแตะ jail ที่ผู้ดูแลเขียนเอง (`sshd` ของดิสโทร
     * เป็นตัวอย่างที่สำคัญที่สุด) · panel ไม่ได้เป็นคนตั้ง จึงไม่ควรเป็นคนยกเลิก
     */
    public static function isOwnJail(string $jail): bool
    {
        return $jail !== self::PREFIX
            && str_starts_with($jail, self::PREFIX)
            && preg_match('/^[a-z0-9._-]+$/i', $jail) === 1;
    }

    /** @return list<string> */
    public static function modes(): array
    {
        return [self::MODE_OFF, self::MODE_NOTIFY, self::MODE_BAN];
    }

    /** ไฟล์ action ที่ส่งข้อความอย่างเดียว — เขียนคู่กับ jail ทุกครั้งที่ใช้โหมด notify */
    private const NOTIFY_ACTION = self::PREFIX . 'notify';
    private const ACTION_DIR = '/etc/fail2ban/action.d';

    /** ยกเว้นเสมอ ไม่ว่าผู้ใช้จะกรอกอะไร — เหตุผลอยู่ที่ {@see jailContent()} */
    private const LOCAL_IPS = '127.0.0.1/8 ::1';

    /**
     * ที่อยู่ที่ห้ามแบนไม่ว่า jail ไหน — รายการเดียวของทั้งเครื่อง
     *
     * **มีไว้เพราะ IP หนึ่งอันไม่ได้แทนคนคนเดียวเสมอไป** · ลูกค้าที่เป็นโรงเรียนออกเน็ต
     * ผ่าน IP เดียวกันทั้งโรงเรียน — นักเรียนคนเดียวที่เครื่องติดมัลแวร์แล้วสแกน
     * อัตโนมัติจะทำให้ทั้งโรงเรียนเข้าเว็บตัวเองไม่ได้ และเข้าเว็บของลูกค้ารายอื่น
     * บนเครื่องเดียวกันไม่ได้ด้วย เพราะ fail2ban สั่ง firewall ซึ่งไม่รู้จัก vhost
     *
     * **ทำไมต้องเป็นรายการกลาง ไม่ใช่กรอกซ้ำในแต่ละ jail** — ก่อนหน้านี้ที่ยกเว้น
     * มีสองที่แยกกัน (ตาราง `site_rate_limits` รายเว็บ กับค่าตั้งของ jail หน้าล็อกอิน)
     * ลงทะเบียนโรงเรียนหนึ่งแห่งจึงต้องไล่ใส่ทุกที่ และ **jail ที่สร้างใหม่ในอนาคต
     * จะไม่รู้จักรายการนั้นเลย** · ที่นี่ถูกฉีดเข้าไปในทุกไฟล์ที่คลาสนี้เขียน
     * ลงครั้งเดียวจึงปลอดภัยตลอดไปทุก jail รวมถึงที่ยังไม่ได้เขียน
     */
    private string $neverBan = '';

    /**
     * คำสั่งที่ action โหมดแจ้งเตือนเรียก — ผู้เรียกส่งเส้นทางจริงมาให้
     *
     * ค่าเริ่มต้นเป็นเส้นทางมาตรฐานของการติดตั้งปกติ แต่ผู้เรียกที่มี `Paths` อยู่ในมือ
     * ควรส่งค่าที่ถูกต้องของเครื่องนั้นมาเสมอ ({@see \Phpcp\Kernel\Paths::binary()})
     */
    private string $alertBinary = '/usr/share/phpcp/bin/phpcp-alert';

    public function __construct(private readonly Executor $executor)
    {
    }

    /**
     * ตั้งรายการห้ามแบนระดับเครื่อง — ค่ามาจาก `security.never_ban_ips`
     *
     * แยกจาก constructor เพราะผู้เรียกบางรายไม่มีฐานข้อมูลในมือ (เช่นตอนเรนเดอร์
     * เนื้อไฟล์เพื่อเทียบ drift) · ไม่ตั้งก็ยังทำงานได้ แค่ไม่มีรายการยกเว้นเพิ่ม
     */
    public function withNeverBan(string $ips): self
    {
        $this->neverBan = trim($ips);

        return $this;
    }

    /** เส้นทางจริงของ `phpcp-alert` บนเครื่องนี้ — ใช้โดย action โหมดแจ้งเตือน */
    public function withAlertBinary(string $path): self
    {
        if (trim($path) !== '') {
            $this->alertBinary = trim($path);
        }

        return $this;
    }

    /**
     * บรรทัด `action` ของ jail ตามโหมดที่เลือก
     *
     * `%(action_)s` คือ action มาตรฐานของ fail2ban ที่สั่ง firewall · ส่วนโหมดแจ้งเตือน
     * ใช้ action ของเราเองที่ไม่มีคำสั่งแตะ firewall เลยแม้แต่บรรทัดเดียว
     *
     * **เปอร์เซ็นต์เดียว ไม่ใช่สองตัว** — ค่านี้ถูกส่งเข้า `sprintf` เป็น *อาร์กิวเมนต์*
     * ไม่ใช่ส่วนหนึ่งของรูปแบบ จึงไม่มีการลดรูป `%%` เป็น `%` ให้ · เขียนสองตัวแล้ว
     * ไฟล์จะได้ `%%(action_)s` ซึ่ง configparser ของ fail2ban อ่านเป็นข้อความตรงตัว
     * ไม่ใช่ชื่อ action ทำให้ jail นั้นใช้ไม่ได้ (ตอนอยู่ในรูปแบบของ sprintf ต้องเขียน
     * สองตัว — นี่คือความต่างที่ทำให้พลาดง่ายตอนย้ายบรรทัดนี้ออกมา)
     */
    private function actionLine(string $mode): string
    {
        return $mode === self::MODE_NOTIFY
            ? 'action   = ' . self::NOTIFY_ACTION
            : 'action   = %(action_)s';
    }

    /**
     * เนื้อไฟล์ action ที่ "แจ้งเตือนอย่างเดียว"
     *
     * ไม่มี `actionstart`/`actionstop`/`actionunban` เพราะไม่มีอะไรต้องเตรียมหรือคืน —
     * ไม่ได้สร้าง chain ไม่ได้แก้ firewall · `actionban` อย่างเดียวคือทั้งหมดที่ทำ
     *
     * เรียก `phpcp-alert` ซึ่งเป็นโปรแกรมที่ตั้งใจให้แคบอยู่แล้ว: ส่งข้อความแล้วจบ
     * ไม่แตะระบบ · fail2ban รันเป็น root จึงเรียกได้ตรง ๆ
     */
    private function notifyActionContent(): string
    {
        return sprintf(<<<'CONF'
            # สร้างโดย phpcp — ห้ามแก้ด้วยมือ ไฟล์นี้ถูกเขียนทับทุกครั้งที่บันทึกค่าจากหน้าเว็บ
            #
            # action ที่ **ไม่แตะ firewall เลย** — มีไว้ให้ jail โหมด "แจ้งเตือนอย่างเดียว"
            # ผู้ดูแลอ่านข้อความแล้วตัดสินใจเองว่าจะแบนหรือไม่
            [Definition]
            actionstart =
            actionstop =
            actioncheck =
            actionban = %s jail-hit "<name>" "<ip>" "<failures>"
            actionunban =

            [Init]
            name = default
            CONF, $this->alertBinary) . "\n";
    }

    /**
     * เขียนไฟล์ action ของโหมดแจ้งเตือน — เขียนเสมอ ไม่ใช่เฉพาะตอนใช้โหมดนั้น
     *
     * **ทำไมเขียนเสมอ:** ไฟล์ jail กับไฟล์ action ถูก commit พร้อมกัน · ถ้าเขียนเฉพาะ
     * ตอนโหมด notify แล้วผู้ดูแลสลับ jail หนึ่งกลับไป ban ไฟล์ action อาจถูกทิ้งไว้
     * หรือถูกลบทั้งที่ jail อื่นยังใช้อยู่ · ไฟล์ที่ไม่มีใครอ้างถึงไม่ทำอะไรเลย
     * ส่วนไฟล์ที่หายตอนมีคนอ้างถึงทำให้ fail2ban ทั้งตัวสตาร์ตไม่ขึ้น
     */
    private function writeNotifyAction(ConfigTransaction $tx, string $mode): void
    {
        $tx->write(self::ACTION_DIR . '/' . self::NOTIFY_ACTION . '.conf', $this->notifyActionContent(), 0644);
    }

    /**
     * รวมรายการยกเว้นทั้งหมดของ jail หนึ่ง
     *
     * เรียงจาก "ห้ามแบนเด็ดขาด" ไปหา "ผู้ดูแลระบุเอง" — localhost มาก่อนเสมอ
     */
    private function ignoreList(string $extra): string
    {
        return trim(preg_replace(
            '/\s+/',
            ' ',
            self::LOCAL_IPS . ' ' . $this->neverBan . ' ' . trim($extra),
        ) ?? self::LOCAL_IPS);
    }

    /**
     * เปิดหรือปรับค่าการจำกัดอัตราของเว็บหนึ่ง
     *
     * @param array{max_requests:int,window_seconds:int,ban_seconds:int,ignore_ips:string} $settings
     */
    public function apply(Site $site, array $settings): void
    {
        $this->assertRunning();

        $name = $this->jailName($site);

        $tx = new ConfigTransaction($this->executor);
        $this->writeNotifyAction($tx, (string) ($settings['mode'] ?? self::MODE_BAN));
        $tx->write($this->filterPath($name), $this->filterContent(), 0644);
        $tx->write($this->jailPath($name), $this->jailContent($name, $site, $settings), 0644);

        // ตรวจว่า fail2ban อ่านไฟล์ที่เพิ่งเขียนได้จริงก่อนยอมให้อยู่ — regex ที่ผิด
        // ทำให้ fail2ban ทั้งตัวสตาร์ตไม่ขึ้นครั้งถัดไป ซึ่งแปลว่า jail ของ SSH
        // ที่กันคนเดารหัสผ่านอยู่ก็หายไปด้วย
        //
        // **ต้องเป็น `-t` ซึ่งเป็นตัวเลือกของโปรแกรม ไม่ใช่ `--test get <jail> ...`**
        // — เคยเขียนแบบหลังแล้วล้มเหลวทุกครั้ง (`NOK: ('phpcp-xxx',)`) เพราะ `get`
        // ถาม**เซิร์ฟเวอร์ที่กำลังรันอยู่** ว่า jail นั้นมีค่าอะไร ซึ่งยังไม่รู้จัก jail
        // ที่เพิ่งเขียนลงดิสก์เพราะยังไม่ได้ reload · ส่วน `-t` อ่านไฟล์ config
        // ทั้งชุดจากดิสก์ตรง ๆ ซึ่งเป็นสิ่งที่ต้องการพอดี
        $tx->commit(function (): array {
            $test = $this->executor->exec([$this->client(), '-t'], timeout: 30);

            return [
                $test->ok(),
                // `-t` ตรวจ config **ทั้งเครื่อง** — ความล้มเหลวอาจมาจาก jail อื่น
                // ที่พังอยู่ก่อนแล้ว ไม่ใช่ไฟล์ที่เพิ่งเขียน · ต้องบอกให้ผู้ดูแลรู้
                // ไม่งั้นจะไล่หาที่ผิดในไฟล์ที่ถูกต้องอยู่แล้ว
                "การตรวจ config ของ fail2ban ไม่ผ่าน (ตรวจทั้งเครื่อง ไม่ใช่เฉพาะไฟล์นี้)\n\n"
                . trim($test->stderr ?: $test->output()),
            ];
        });

        $this->reload();
    }

    /** ปิดการจำกัดอัตราของเว็บหนึ่ง — ลบไฟล์ทั้งคู่แล้วให้ fail2ban ลืม jail นั้น */
    public function remove(Site $site): void
    {
        $name = $this->jailName($site);

        if (!$this->executor->exists($this->executor->path($this->jailPath($name)))) {
            return;   // ไม่เคยเปิดไว้ — ไม่ใช่ความผิดพลาด
        }

        $this->assertRunning();

        $tx = new ConfigTransaction($this->executor);
        $tx->delete($this->jailPath($name));
        $tx->delete($this->filterPath($name));
        $tx->commitWithoutValidation();

        $this->reload();
    }

    /**
     * เปิดหรือปรับค่าการกันเดารหัสผ่านของหน้าเข้าสู่ระบบของ panel
     *
     * **ทำไมต้องมีทั้งที่มีการล็อกบัญชีในแอปอยู่แล้ว** — การล็อกบัญชีกันได้แค่บัญชีเดียว
     * ต่อครั้ง คนที่ไล่เดารหัสผ่านของ `admin`, `root`, `administrator` สลับกันไปจึงไม่เคย
     * ถูกกันเลย และทุกครั้งที่ลองยังกิน worker ของ PHP-FPM ที่มีอยู่แค่สี่ตัว · การแบน
     * ที่ firewall ตัดตั้งแต่ก่อนถึง PHP
     *
     * @param string $auditLogPath สำเนา audit log แบบข้อความ ({@see \Phpcp\Kernel\Paths::logFile()})
     * @param array{max_retry:int,find_seconds:int,ban_seconds:int,ignore_ips:string} $settings
     */
    public function applyPanelLogin(string $auditLogPath, array $settings): void
    {
        $this->assertRunning();

        $tx = new ConfigTransaction($this->executor);
        $this->writeNotifyAction($tx, (string) ($settings['mode'] ?? self::MODE_BAN));
        $tx->write($this->filterPath(self::PANEL_LOGIN_JAIL), $this->panelLoginFilter(), 0644);
        $tx->write($this->jailPath(self::PANEL_LOGIN_JAIL), $this->panelLoginJail($auditLogPath, $settings), 0644);

        $tx->commit(function (): array {
            $test = $this->executor->exec([$this->client(), '-t'], timeout: 30);

            return [
                $test->ok(),
                "การตรวจ config ของ fail2ban ไม่ผ่าน (ตรวจทั้งเครื่อง ไม่ใช่เฉพาะไฟล์นี้)\n\n"
                . trim($test->stderr ?: $test->output()),
            ];
        });

        $this->reload();
    }

    /** ปิดการกันเดารหัสผ่านของหน้าเข้าสู่ระบบ */
    public function removePanelLogin(): void
    {
        if (!$this->executor->exists($this->executor->path($this->jailPath(self::PANEL_LOGIN_JAIL)))) {
            return;
        }

        $this->assertRunning();

        $tx = new ConfigTransaction($this->executor);
        $tx->delete($this->jailPath(self::PANEL_LOGIN_JAIL));
        $tx->delete($this->filterPath(self::PANEL_LOGIN_JAIL));
        $tx->commitWithoutValidation();

        $this->reload();
    }

    /**
     * สถานะจริงจาก fail2ban — จำนวน IP ที่ถูกแบนอยู่ตอนนี้
     *
     * อ่านจากตัว fail2ban เอง ไม่ใช่จากฐานข้อมูลของ panel เพราะสองอย่างนี้ไม่ตรงกันได้:
     * ผู้ดูแลอาจสั่ง `fail2ban-client unban` เองจากบรรทัดคำสั่ง หรือ fail2ban อาจ
     * ไม่ได้โหลด jail นั้นเพราะไฟล์ผิด · หน้าจอต้องบอกสิ่งที่เป็นจริงบนเครื่อง
     *
     * @return array{active:bool,banned:int,total_banned:int,failed:int}
     */
    public function status(Site $site): array
    {
        return $this->statusOf($this->jailName($site));
    }

    /**
     * สถานะของ jail ที่ระบุด้วยชื่อ — ตัวเดียวกับ {@see self::status()} แต่ไม่ผูกกับเว็บไซต์
     *
     * @return array{active:bool,banned:int,total_banned:int,failed:int}
     */
    public function statusOf(string $jail): array
    {
        $result = $this->executor->exec([$this->client(), 'status', $jail], timeout: 15);

        if (!$result->ok()) {
            // jail ไม่มีอยู่ = ยังไม่ได้เปิด ไม่ใช่ error
            return ['active' => false, 'banned' => 0, 'total_banned' => 0, 'failed' => 0];
        }

        $output = $result->output();

        return [
            'active' => true,
            'banned' => $this->number($output, 'Currently banned'),
            'total_banned' => $this->number($output, 'Total banned'),
            'failed' => $this->number($output, 'Currently failed'),
        ];
    }

    /**
     * ปลดแบน IP หนึ่งของเว็บหนึ่ง
     *
     * ต้องมีเพราะการแบนพลาดเกิดขึ้นจริงและกระทบทั้งเครื่อง (fail2ban สั่ง firewall
     * ซึ่งไม่รู้จัก vhost) — ถ้าไม่มีทางปลดจากหน้าเว็บ ผู้ดูแลที่แบนตัวเองจะเข้า panel
     * ไม่ได้เลยและต้องหาทางเข้าเครื่องทางอื่น
     */
    public function unban(Site $site, string $ip): void
    {
        $this->unbanFrom($this->jailName($site), $ip);
    }

    /** ปลดแบน IP หนึ่งจาก jail ที่ระบุด้วยชื่อ */
    public function unbanFrom(string $jail, string $ip): void
    {
        $this->assertIp($ip);

        $result = $this->executor->exec(
            [$this->client(), 'set', $jail, 'unbanip', $ip],
            timeout: 15,
        );

        if (!$result->ok()) {
            throw new ExecutionFailed('ปลดแบน IP ไม่สำเร็จ: ' . trim($result->stderr ?: $result->output()));
        }
    }

    /** @return list<string> IP ที่ถูกแบนอยู่ตอนนี้ */
    public function bannedIps(Site $site): array
    {
        return $this->bannedIpsOf($this->jailName($site));
    }

    /** @return list<string> IP ที่ถูกแบนอยู่ตอนนี้ใน jail ที่ระบุด้วยชื่อ */
    public function bannedIpsOf(string $jail): array
    {
        $result = $this->executor->exec(
            [$this->client(), 'get', $jail, 'banip'],
            timeout: 15,
        );

        if (!$result->ok()) {
            return [];
        }

        return array_values(array_filter(preg_split('/\s+/', trim($result->output())) ?: []));
    }

    /**
     * ชื่อ jail ของเว็บหนึ่ง
     *
     * ใช้ `system_user` เป็นฐานเพราะถูกตรวจรูปแบบมาแล้วให้ปลอดภัยพอเป็นชื่อไฟล์
     * (ดู `Site::assertSystemUser`) — ต่างจากชื่อโดเมนที่มีจุดและอาจยาวเกินขีดจำกัด
     */
    public function jailName(Site $site): string
    {
        return self::PREFIX . $site->systemUser();
    }

    /**
     * เนื้อไฟล์ filter — จับ **ทุกคำขอ** ไม่ใช่เฉพาะที่ล้มเหลว
     *
     * ต่างจาก jail ทั่วไปของ fail2ban ที่จับ "รหัสผ่านผิด" แล้วนับครั้ง · ที่นี่นับ
     * ทุกคำขอจาก IP เดียวแล้วให้ `maxretry`/`findtime` ในไฟล์ jail เป็นตัวตัดสิน
     * ว่าเร็วเกินไปหรือไม่ — ซึ่งคือความหมายของ rate limit
     *
     * `<HOST>` คือ token ของ fail2ban ที่แทน IP (รองรับ IPv4/IPv6) และต้องเป็น
     * กลุ่มแรกเสมอ · รูปแบบ log ที่รองรับคือ combined ซึ่งเป็นค่าที่ทั้ง Apache
     * และ nginx ของ panel ตั้งไว้ตรงกัน
     */
    private function filterContent(): string
    {
        return <<<'CONF'
            # สร้างโดย phpcp — ห้ามแก้ด้วยมือ ไฟล์นี้ถูกเขียนทับทุกครั้งที่บันทึกค่าจากหน้าเว็บ
            #
            # จับทุกคำขอจาก IP เดียว แล้วให้ maxretry/findtime ในไฟล์ jail ตัดสินว่าเร็วเกินไปไหม
            # — ต่างจาก jail ทั่วไปที่จับเฉพาะการยืนยันตัวตนที่ล้มเหลว
            [Definition]
            failregex = ^<HOST> -.*"(GET|POST|HEAD|PUT|DELETE|PATCH|OPTIONS).*"
            ignoreregex =
            # ระบุไว้ทั้งที่ค่าเริ่มต้นก็คือ auto — ไม่งั้น fail2ban เตือนทุกครั้งที่ reload
            # ว่า "'allowipv6' not defined" จน journal เต็มไปด้วยข้อความเดียวซ้ำ ๆ
            # แล้วหา error จริงไม่เจอ (เหตุผลเดียวกับที่ agentd ปิด pcre.jit ไว้)
            allowipv6 = auto
            datepattern = ^[^\[]*\[({DATE})
                          {^LN-BEG}
            CONF . "\n";
    }

    /**
     * เนื้อไฟล์ jail ของเว็บหนึ่ง
     *
     * @param array{max_requests:int,window_seconds:int,ban_seconds:int,ignore_ips:string} $settings
     */
    private function jailContent(string $name, Site $site, array $settings): string
    {
        // ที่อยู่ log ต้องผ่าน executor->path() เพื่อให้โหมด sandbox ชี้ไปที่ prefix ของตัวเอง
        $logPath = $this->executor->path($site->accessLog());

        // **localhost ต้องไม่ถูกแบนเด็ดขาด ไม่ว่าผู้ใช้จะกรอกอะไร** — สามเหตุผล:
        //   1. คำขอจากเครื่องเดียวกันคือ health check, cron ของเว็บเอง และตัว panel
        //   2. การแบน 127.0.0.1 ตัดขาดหน้า panel ที่ผู้ดูแลใช้เข้ามาแก้ปัญหาพอดี
        //   3. ไม่มีประโยชน์: คนที่ยิงจาก localhost ได้ แปลว่าเข้าเครื่องได้แล้ว
        //
        // จำเป็นต้องใส่เองเพราะ `jail.conf` ของ Debian **comment `ignoreip` ไว้**
        // (`#ignoreip = 127.0.0.1/8 ::1`) — ไม่มีการยกเว้นค่าเริ่มต้นให้เลย
        $ignore = $this->ignoreList($settings['ignore_ips']);

        return sprintf(
            <<<'CONF'
                # สร้างโดย phpcp สำหรับเว็บไซต์ %s — ห้ามแก้ด้วยมือ
                #
                # การแบนมีผล**ทั้งเครื่อง** ไม่ใช่เฉพาะเว็บนี้ เพราะ fail2ban สั่ง firewall
                # ซึ่งไม่รู้จัก vhost — IP ที่ยิงเว็บนี้จนโดนแบนจะเข้าเว็บอื่นบนเครื่องไม่ได้ด้วย
                [%s]
                enabled  = true
                filter   = %s
                # **ต้องระบุ backend เอง** — Debian/Ubuntu ตั้ง `backend = systemd` ไว้ใน
                # [DEFAULT] ของ /etc/fail2ban/jail.d/defaults-debian.conf ซึ่งทำให้ fail2ban
                # อ่านจาก systemd journal แล้ว **เมิน logpath ทั้งบรรทัด** · jail จะดูเหมือน
                # ทำงานปกติทุกอย่างแต่ไม่นับคำขอสักรายการเดียว และไม่มีอะไรฟ้องเลย
                backend  = auto
                logpath  = %s
                # นับ %d คำขอภายใน %d วินาที = เกินเกณฑ์
                maxretry = %d
                findtime = %d
                bantime  = %d
                # ไม่ระบุ port เพื่อให้แบนทุกพอร์ต — คนที่ยิงจนโดนแบนไม่ควรคุยกับเครื่องนี้ได้เลย
                %s
                %s
                CONF,
            $site->domain,
            $name,
            $name,
            $logPath,
            $settings['max_requests'],
            $settings['window_seconds'],
            $settings['max_requests'],
            $settings['window_seconds'],
            $settings['ban_seconds'],
            $this->actionLine((string) ($settings['mode'] ?? self::MODE_BAN)),
            $ignore === '' ? '' : 'ignoreip = ' . $ignore,
        ) . "\n";
    }

    /**
     * เนื้อไฟล์ filter ของหน้าเข้าสู่ระบบ — จับเฉพาะการยืนยันตัวตนที่ **ล้มเหลว**
     *
     * ต่างจาก filter รายเว็บที่นับทุกคำขอ · ที่นี่คนที่ล็อกอินถูกไม่ควรถูกนับเลย
     *
     * ### ทำไม regex ถึงยึดกับ `"user_id":\d+,"ip":"` ไม่ใช่ `"ip":"` เฉย ๆ
     *
     * `actor` ในบรรทัดเดียวกันคือ**ชื่อผู้ใช้ที่คนยิงพิมพ์เข้ามาเอง** คนที่รู้ว่า
     * ระบบอ่าน log นี้จะตั้งชื่อผู้ใช้เป็น `evil","ip":"9.9.9.9` เพื่อให้ fail2ban
     * ไปแบน IP ของคนอื่นแทนตัวเอง · `json_encode` หนีเครื่องหมายคำพูดให้เป็น `\"`
     * ทำให้ข้อความปลอมไม่ตรงกับ `"ip":"` อยู่แล้ว แต่การยึดกับ `user_id` ซึ่งเป็น
     * **จำนวนเต็มดิบ** ที่ปลอมไม่ได้เลย ทำให้ไม่ต้องพึ่งรายละเอียดการหนีอักขระ
     *
     * พิสูจน์ด้วย `fail2ban-regex` บนเครื่องจริงแล้วว่าบรรทัดที่พยายามปลอมให้ผลเป็น
     * IP จริงของผู้ยิง ไม่ใช่ IP ที่ใส่มาในชื่อผู้ใช้
     *
     * `action` กับ `result` อยู่**หลัง** `ip` เสมอตามลำดับคีย์ที่ `AuditLog::mirror()`
     * เขียน — ลำดับนี้จึงเป็นส่วนหนึ่งของสัญญา ไม่ใช่เรื่องบังเอิญ
     */
    private function panelLoginFilter(): string
    {
        return <<<'CONF'
            # สร้างโดย phpcp — ห้ามแก้ด้วยมือ ไฟล์นี้ถูกเขียนทับทุกครั้งที่บันทึกค่าจากหน้าเว็บ
            #
            # จับการเข้าสู่ระบบและการยืนยันสองขั้นตอนที่ล้มเหลว จากสำเนา audit log แบบ JSON
            # ยึดกับ "user_id":<เลข>,"ip":" เพราะเลขนั้นปลอมผ่านชื่อผู้ใช้ไม่ได้
            [Definition]
            failregex = "user_id":\d+,"ip":"<HOST>",.*"action":"auth\.(?:login|2fa)",.*"result":"denied"
            ignoreregex =
            # ระบุไว้ทั้งที่ค่าเริ่มต้นก็คือ auto — ไม่งั้น fail2ban เตือนทุกครั้งที่ reload
            # ว่า "'allowipv6' not defined" จน journal เต็มไปด้วยข้อความเดียวซ้ำ ๆ
            allowipv6 = auto
            datepattern = ^\{"ts":"({DATE})
                          {^LN-BEG}
            CONF . "\n";
    }

    /**
     * เนื้อไฟล์ jail ของหน้าเข้าสู่ระบบ
     *
     * @param array{max_retry:int,find_seconds:int,ban_seconds:int,ignore_ips:string} $settings
     */
    private function panelLoginJail(string $auditLogPath, array $settings): string
    {
        $ignore = $this->ignoreList($settings['ignore_ips']);

        return sprintf(
            <<<'CONF'
                # สร้างโดย phpcp สำหรับหน้าเข้าสู่ระบบของ panel — ห้ามแก้ด้วยมือ
                #
                # **การแบนตัดขาดทุกพอร์ต รวมถึงพอร์ตของ panel เอง** — ผู้ดูแลที่พิมพ์รหัสผ่าน
                # ผิดเกินเกณฑ์จะเข้าหน้าจัดการไม่ได้จนกว่าจะครบเวลาแบน · ถ้าโดนเอง ให้เข้า
                # เครื่องทาง SSH แล้วสั่ง:
                #   sudo fail2ban-client set %s unbanip <ไอพี>
                # และใส่ไอพีประจำของตัวเองไว้ในช่อง "IP ที่ยกเว้น" กันไม่ให้เกิดซ้ำ
                [%s]
                enabled  = true
                filter   = %s
                # ต้องระบุเอง — Debian/Ubuntu ตั้ง backend = systemd ไว้ใน [DEFAULT] ซึ่งทำให้
                # fail2ban อ่าน journal แล้วเมิน logpath ทั้งบรรทัด · jail จะดูเหมือนทำงานปกติ
                # แต่ไม่นับอะไรเลยและไม่มีอะไรฟ้อง
                backend  = auto
                logpath  = %s
                # ล็อกอินผิด %d ครั้งภายใน %d วินาที = ถูกแบน
                maxretry = %d
                findtime = %d
                bantime  = %d
                %s
                %s
                CONF,
            self::PANEL_LOGIN_JAIL,
            self::PANEL_LOGIN_JAIL,
            self::PANEL_LOGIN_JAIL,
            $auditLogPath,
            $settings['max_retry'],
            $settings['find_seconds'],
            $settings['max_retry'],
            $settings['find_seconds'],
            $settings['ban_seconds'],
            $this->actionLine((string) ($settings['mode'] ?? self::MODE_BAN)),
            $ignore === '' ? '' : 'ignoreip = ' . $ignore,
        ) . "\n";
    }

    private function filterPath(string $name): string
    {
        return self::FILTER_DIR . '/' . $name . '.conf';
    }

    private function jailPath(string $name): string
    {
        return self::JAIL_DIR . '/' . $name . '.conf';
    }

    private function client(): string
    {
        return BinaryPath::resolve($this->executor, self::CLIENT_PATHS, 'fail2ban');
    }

    /**
     * fail2ban ต้องทำงานอยู่ก่อนแตะไฟล์
     *
     * ถ้าไม่ตรวจ จะเขียนไฟล์สำเร็จแล้วรายงานว่า "เปิดการจำกัดอัตราแล้ว" ทั้งที่ไม่มีอะไร
     * บังคับใช้เลย — ซึ่งอันตรายกว่าไม่มีฟีเจอร์นี้ เพราะผู้ดูแลเข้าใจว่าเว็บมีการป้องกันอยู่
     */
    private function assertRunning(): void
    {
        $result = $this->executor->exec([$this->client(), 'ping'], timeout: 15);

        if (!$result->ok()) {
            throw new ExecutionFailed(
                "fail2ban ไม่ทำงานอยู่ จึงยังบังคับใช้การจำกัดอัตราไม่ได้\n\n"
                . "ตรวจด้วย `sudo systemctl status fail2ban` แล้วสั่ง `sudo systemctl start fail2ban`\n"
                . 'ถ้ายังไม่ได้ติดตั้ง: `sudo apt install fail2ban`',
            );
        }
    }

    /**
     * สั่งให้ fail2ban อ่าน config ใหม่
     *
     * ใช้ `reload` ไม่ใช่ `restart` — restart ล้างรายการ IP ที่ถูกแบนอยู่ทั้งหมดทุก jail
     * รวมถึง jail ของ SSH ที่กันคนเดารหัสผ่าน · คนที่กำลังยิงอยู่จะได้รับอภัยโทษฟรี
     * ทุกครั้งที่มีใครกดบันทึกค่าของเว็บใดเว็บหนึ่ง
     */
    private function reload(): void
    {
        $result = $this->executor->exec([$this->client(), 'reload'], timeout: 30);

        if (!$result->ok()) {
            throw new ExecutionFailed(
                "เขียนไฟล์ตั้งค่าแล้วแต่สั่ง fail2ban ให้อ่านค่าใหม่ไม่สำเร็จ: "
                . trim($result->stderr ?: $result->output())
                . "\n\nไฟล์บนดิสก์ถูกต้องแล้ว — สั่งเองด้วย `sudo fail2ban-client reload`",
            );
        }
    }

    /** บริการ fail2ban ตอบอยู่หรือไม่ — ใช้ตอบหน้าจอ ไม่ใช่ด่านกันเหมือน assertRunning() */
    public function isRunning(): bool
    {
        return $this->executor->exec([$this->client(), 'ping'], timeout: 10)->ok();
    }

    /**
     * หน่วยความจำที่ fail2ban ใช้อยู่จริง (MB) — 0 เมื่อไม่ได้รัน
     *
     * แสดงบนหน้าจอคู่กับสวิตช์ปิด เพราะ "ปิดแล้วได้อะไรกลับมา" เป็นคำถามที่ผู้ดูแล
     * เครื่องเล็กถามจริง · ตัวเลขจากเครื่องตัวเองน่าเชื่อกว่าตัวเลขที่เราเขียนไว้ในคู่มือ
     */
    public function memoryUsageMb(): int
    {
        $result = $this->executor->exec(['/bin/ps', '-o', 'rss=', '-C', 'fail2ban-server'], timeout: 10);

        if (!$result->ok()) {
            return 0;
        }

        $kb = 0;
        foreach (preg_split('/\R/', trim($result->output())) ?: [] as $line) {
            $kb += (int) trim($line);
        }

        return (int) round($kb / 1024);
    }

    /** ดึงตัวเลขจากผลของ `fail2ban-client status` ซึ่งเป็นข้อความล้วน */
    private function number(string $output, string $label): int
    {
        return preg_match('/' . preg_quote($label, '/') . ':\s*(\d+)/', $output, $m) === 1
            ? (int) $m[1]
            : 0;
    }

    /** IP ที่ส่งมาจากฟอร์มต้องเป็น IP จริง ไม่ใช่ข้อความที่กลายเป็นอาร์กิวเมนต์อื่น */
    private function assertIp(string $ip): void
    {
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            throw new ValidationError('รูปแบบ IP ไม่ถูกต้อง');
        }
    }

    /**
     * ตรวจรูปแบบรายการ IP ที่ยกเว้น — ใช้ตอนบันทึกค่าจากหน้าเว็บ
     *
     * ยอมรับทั้ง IP เดี่ยวและ CIDR คั่นด้วยช่องว่างหรือจุลภาค แล้วคืนรูปแบบที่ fail2ban
     * อ่านได้ · ค่าที่ผิดรูปแบบต้องถูกปฏิเสธตั้งแต่ตอนกรอก ไม่ใช่ตอนที่ fail2ban
     * สตาร์ตไม่ขึ้นแล้วทำให้ jail ของ SSH หายไปด้วย
     */
    public static function normalizeIgnoreList(string $raw): string
    {
        $items = preg_split('/[\s,]+/', trim($raw)) ?: [];
        $clean = [];

        foreach ($items as $item) {
            if ($item === '') {
                continue;
            }

            [$address, $bits] = array_pad(explode('/', $item, 2), 2, null);

            if (filter_var($address, FILTER_VALIDATE_IP) === false) {
                throw new ValidationError("IP ที่ยกเว้นไม่ถูกต้อง: {$item}");
            }

            if ($bits !== null) {
                $max = str_contains((string) $address, ':') ? 128 : 32;

                if (!ctype_digit($bits) || (int) $bits < 0 || (int) $bits > $max) {
                    throw new ValidationError("ขนาดเครือข่ายไม่ถูกต้อง: {$item}");
                }
            }

            $clean[] = $item;
        }

        if (count($clean) > 64) {
            throw new ValidationError('ระบุ IP ที่ยกเว้นได้ไม่เกิน 64 รายการ');
        }

        return implode(' ', $clean);
    }
}
