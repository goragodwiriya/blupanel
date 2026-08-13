<?php

declare(strict_types=1);

namespace Phpcp\Driver\Mail;

use Phpcp\Agent\ExecutionFailed;
use Phpcp\Agent\Executor\Executor;
use Phpcp\Driver\ConfigTransaction;
use Phpcp\Driver\Template;

/**
 * กล่องจดหมายจริงบนเครื่อง — PLAN-MAIL เฟส M1
 *
 * ## รูปร่างของระบบ
 *
 * ```
 *   อินเทอร์เน็ต ──25──► Postfix ──LMTP──► Dovecot ──► Maildir (เจ้าของ vmail คนเดียว)
 *                ──587/465──► SASL auth ที่ Dovecot
 *                ──993/995──────────────► Dovecot
 * ```
 *
 * ## ทำไมตารางค้นหาเป็นไฟล์ที่ระบบเขียนเอง ไม่ใช่ให้เดมอนอ่านฐานข้อมูลตรง ๆ
 *
 * Postfix กับ Dovecot อ่าน SQLite ได้ทั้งคู่ (ต้องลงแพ็กเกจเพิ่ม) แต่การทำแบบนั้น
 * แปลว่ามีสองโปรแกรมนอกระบบเปิดไฟล์ฐานข้อมูลของ panel ค้างไว้ตลอดเวลา — ซึ่งชนกับ
 * สองอย่างที่ทั้งระบบยึดมาตลอด: **agent เป็นผู้เขียนไฟล์ตั้งค่าคนเดียว** และ
 * **ทุกการเปลี่ยนแปลงย้อนกลับได้ทั้งชุดผ่าน ConfigTransaction**
 *
 * ที่นี่จึงเขียนไฟล์ทั้งชุดใหม่ทุกครั้งที่มีอะไรเปลี่ยน แล้วให้ `postfix check` กับ
 * `doveconf -n` เป็นคนตัดสินว่าจะ commit หรือคืนค่าเดิม — เหมือน vhost ทุกประการ
 *
 * ## สิ่งที่ห้ามพลาดในไฟล์นี้
 *
 *   - **ทับท้ายเส้นทาง Maildir** — ไม่มีทับ Postfix จะเขียนเป็น mbox ไฟล์เดียว
 *   - **ตารางว่างต้องยังเขียนไฟล์** — โดเมนที่ปิดเมลไปแล้วต้องหายจากไฟล์จริง
 *     ไม่ใช่ค้างอยู่เพราะ "ไม่มีอะไรให้เขียน"
 *   - **แฮชรหัสผ่านสร้างด้วย doveadm** ไม่ใช่ `password_hash()` ของ PHP — รูปแบบ
 *     ต้องเป็นของ Dovecot ({ARGON2ID}...) ไม่งั้นล็อกอินไม่ผ่านโดยไม่มีข้อความบอก
 */
final class MailboxManager
{
    /** บัญชีระบบที่เป็นเจ้าของไฟล์เมลทั้งหมด — ไม่มี shell และไม่ใช่เจ้าของกล่องรายคน */
    public const VMAIL_USER = 'vmail';

    /** ที่เก็บกล่องจดหมายทุกกล่อง — `<โดเมน>/<ชื่อ>/` */
    public const MAIL_ROOT = '/srv/phpcp/mail';

    private const POSTFIX_DIR = '/etc/postfix';
    private const VMAILBOX = self::POSTFIX_DIR . '/vmailbox';
    private const VALIAS = self::POSTFIX_DIR . '/valias';
    private const VDOMAINS = self::POSTFIX_DIR . '/vdomains';

    /**
     * ไฟล์ตั้งค่าที่ panel เป็นเจ้าของทั้งไฟล์
     *
     * เปิดเป็น public เพราะ `mail.cert` ใช้เวลาแก้ไขของไฟล์นี้เป็นเครื่องหมายว่า
     * "บอกเดมอนเรื่องใบรับรองครั้งล่าสุดเมื่อไหร่" (ดู MailCertificate::changedSince)
     */
    public const DOVECOT_CONF = '/etc/dovecot/conf.d/99-phpcp.conf';
    private const DOVECOT_USERS = '/etc/dovecot/phpcp-users';

    private const POSTMAP = '/usr/sbin/postmap';
    private const DOVEADM = '/usr/bin/doveadm';
    private const DOVECONF = '/usr/bin/doveconf';

    public function __construct(private readonly Template $templates)
    {
    }

    /** ติดตั้ง Dovecot ไว้แล้วหรือยัง — ไม่มีก็ทำอะไรกับกล่องจดหมายไม่ได้เลย */
    public function isInstalled(Executor $executor): bool
    {
        return $executor->isSimulated() || $executor->exists($executor->path('/etc/dovecot'));
    }

    /**
     * เขียนตารางค้นหาทั้งชุดใหม่จากข้อมูลที่ส่งมา
     *
     * ผู้เรียกส่ง "ภาพรวมทั้งเครื่อง" มาเสมอ ไม่ใช่ส่งเฉพาะสิ่งที่เปลี่ยน — การเขียน
     * ทั้งไฟล์ทำให้ไม่มีทางเหลือแถวกำพร้าของกล่องที่ถูกลบไปแล้ว ซึ่งเป็นบั๊กที่
     * อันตรายเป็นพิเศษกับเมล (กล่องที่ลบแล้วยังรับเมลต่อได้)
     *
     * @param list<string>                                                    $domains โดเมนที่เปิดเมล
     * @param list<array{address:string,maildir:string,password:string,quota_mb:int}> $boxes
     * @param list<array{source:string,destination:string}>                   $aliases
     * @param array{cert?:string,key?:string}                                 $tls ใบรับรองของ mail hostname
     * @return array{domains:int,mailboxes:int,aliases:int}
     */
    public function apply(Executor $executor, array $domains, array $boxes, array $aliases, array $tls = []): array
    {
        $transaction = new ConfigTransaction($executor);

        $transaction->write(self::VDOMAINS, $this->renderDomains($domains), 0644);
        $transaction->write(self::VMAILBOX, $this->renderMailboxes($boxes), 0644);
        $transaction->write(self::VALIAS, $this->renderAliases($aliases, $boxes), 0644);

        // ไฟล์นี้มีแฮชรหัสผ่านของทุกกล่อง — กลุ่ม dovecot อ่านได้ ผู้ใช้อื่นห้ามแตะ
        $transaction->write(self::DOVECOT_USERS, $this->renderDovecotUsers($boxes), 0640);

        $certificate = MailCertificate::pathsOrDefault(
            (string) ($tls['cert'] ?? ''),
            (string) ($tls['key'] ?? ''),
        );

        $transaction->write(self::DOVECOT_CONF, $this->templates->render('dovecot/99-phpcp.conf.tpl', [
            'MAIL_ROOT' => self::MAIL_ROOT,
            'VMAIL_USER' => self::VMAIL_USER,
            'USERS_FILE' => self::DOVECOT_USERS,
            'TLS_CERT' => $certificate['cert'],
            'TLS_KEY' => $certificate['key'],
            'GENERATED_AT' => date('Y-m-d H:i:s'),
        ]), 0644);

        $transaction->commit(fn (): array => $this->testConfig($executor));

        /*
         * **ไฟล์ผู้ใช้ต้องเป็นของกลุ่ม dovecot** ไม่ใช่ root:root
         *
         * เจ้าของ root สิทธิ์ 0640 แปลว่ากระบวนการ auth ของ Dovecot (รันเป็นผู้ใช้
         * dovecot) เปิดไฟล์ไม่ได้ · อาการที่ได้คือ "Temporary authentication failure"
         * ตอนล็อกอิน และเมลที่ส่งเข้ามาถูกตีกลับเพราะหา userdb ไม่เจอ — ทั้งสองอย่าง
         * ไม่มีคำว่า "permission" อยู่ในข้อความเลย (เจอจริงตอนทดสอบ M1)
         */
        $executor->exec(
            ['/bin/chown', 'root:dovecot', $executor->path(self::DOVECOT_USERS)],
            timeout: 15,
        );

        // .db ที่ Postfix อ่านจริงต้องสร้างหลัง commit — ถ้าสร้างก่อนแล้ว rollback
        // ไฟล์ข้อความจะกลับไปเป็นของเดิมแต่ .db ยังเป็นของใหม่ ซึ่งแยกจากกันเงียบ ๆ
        foreach ([self::VMAILBOX, self::VALIAS, self::VDOMAINS] as $file) {
            $this->postmap($executor, $file);
        }

        $this->reload($executor);

        return ['domains' => count($domains), 'mailboxes' => count($boxes), 'aliases' => count($aliases)];
    }

    /**
     * สร้างโฟลเดอร์ Maildir ของกล่อง พร้อมเจ้าของที่ถูกต้อง
     *
     * 0700 เพราะกล่องของคนหนึ่งต้องไม่ถูกอ่านโดยกระบวนการอื่นบนเครื่องเดียวกัน ·
     * ทุกกล่องเป็นของ vmail เหมือนกันหมด การแยกสิทธิ์ระหว่างกล่องเป็นหน้าที่ของ
     * Dovecot ที่จำกัดแต่ละ session ไว้ที่ home ของกล่องนั้น
     */
    public function createMaildir(Executor $executor, string $maildir): void
    {
        $path = $executor->path(rtrim($maildir, '/'));

        $executor->makeDirectory($path, 0700);

        /*
         * **ต้องเปลี่ยนเจ้าของโฟลเดอร์ของโดเมนด้วย ไม่ใช่แค่ของกล่อง**
         *
         * `mkdir -p` สร้างโฟลเดอร์ของโดเมนให้เป็นของ root ตอนสร้างกล่องแรก · Dovecot
         * รันเป็น vmail จึงเดินผ่านเข้าไปไม่ได้ แล้วเมลที่ส่งมาถูกตีกลับด้วยข้อความว่า
         * "Temporary internal error" ซึ่งไม่มีคำว่า permission อยู่เลย (เจอจริงตอนทดสอบ M1)
         */
        $owner = self::VMAIL_USER . ':' . self::VMAIL_USER;

        $executor->exec(['/bin/chown', $owner, dirname($path)], timeout: 15);
        $executor->exec(['/bin/chown', '-R', $owner, $path], timeout: 30);
    }

    /** ลบกล่องออกจากดิสก์ — เรียกหลังลบแถวในฐานข้อมูลและเขียน map ใหม่แล้วเท่านั้น */
    public function removeMaildir(Executor $executor, string $maildir): void
    {
        $path = rtrim($maildir, '/');

        // กันพลาดชนิดที่ลบทั้งเครื่อง — เส้นทางต้องอยู่ใต้ที่เก็บเมลจริงเท่านั้น
        if (!str_starts_with($path, self::MAIL_ROOT . '/') || str_contains($path, '..')) {
            throw new ExecutionFailed('เส้นทางกล่องจดหมายไม่ถูกต้อง: ' . $path);
        }

        $executor->exec(['/bin/rm', '-rf', $executor->path($path)], timeout: 60);
    }

    /**
     * ลบโฟลเดอร์เมลของทั้งโดเมน — ใช้ตอนลบโดเมนหรือลบเว็บไซต์
     *
     * แถวในฐานข้อมูลหายเองตาม `ON DELETE CASCADE` อยู่แล้ว แต่**ไฟล์บนดิสก์ไม่หายตาม** ·
     * กล่องที่ถูกลบพร้อมโดเมนจึงทิ้งเมลของลูกค้าไว้บนเครื่องตลอดไปโดยไม่มีอะไรอ้างถึง —
     * กินดิสก์ไปเรื่อย ๆ และเป็นข้อมูลส่วนตัวที่ค้างอยู่โดยไม่มีใครรู้ว่ายังอยู่
     */
    public function removeDomainDir(Executor $executor, string $domain): void
    {
        // ชื่อโดเมนผ่าน Validator มาแล้วทุกทาง แต่กันอีกชั้นเพราะพลาดที่นี่คือ rm -rf ผิดที่
        if ($domain === '' || str_contains($domain, '/') || str_contains($domain, '..')) {
            throw new ExecutionFailed('ชื่อโดเมนไม่ถูกต้อง: ' . $domain);
        }

        $path = self::MAIL_ROOT . '/' . $domain;

        if ($executor->exists($executor->path($path))) {
            $executor->exec(['/bin/rm', '-rf', $executor->path($path)], timeout: 120);
        }
    }

    /**
     * แฮชรหัสผ่านด้วยเครื่องมือของ Dovecot เอง
     *
     * ต้องเป็น `doveadm pw` ไม่ใช่ `password_hash()` ของ PHP — Dovecot ตรวจรหัสจาก
     * รูปแบบที่นำหน้าด้วยชื่อ scheme (`{ARGON2ID}$argon2id$...`) ถ้าใส่แฮชของ PHP
     * ลงไปตรง ๆ มันจะอ่านไม่ออกและปฏิเสธการล็อกอินทุกครั้งโดยไม่บอกว่าเพราะอะไร
     */
    public function hashPassword(Executor $executor, string $plain): string
    {
        $result = $executor->exec(
            [$executor->path(self::DOVEADM), 'pw', '-s', 'ARGON2ID', '-p', $plain],
            timeout: 30,
        );

        if (!$result->ok() || trim($result->stdout) === '') {
            throw new ExecutionFailed('สร้างแฮชรหัสผ่านไม่สำเร็จ: ' . trim($result->stderr));
        }

        return trim($result->stdout);
    }

    /**
     * ตรวจค่าตั้งของทั้งสองฝั่งก่อน commit
     *
     * @return array{0:bool,1:string}
     */
    public function testConfig(Executor $executor): array
    {
        $postfix = $executor->exec([$executor->path('/usr/sbin/postfix'), 'check'], timeout: 20);

        if (!$postfix->ok()) {
            return [false, 'Postfix: ' . trim($postfix->stderr ?: $postfix->stdout)];
        }

        $dovecot = $executor->exec([$executor->path(self::DOVECONF), '-n'], timeout: 20);

        if (!$dovecot->ok()) {
            return [false, 'Dovecot: ' . trim($dovecot->stderr ?: $dovecot->stdout)];
        }

        return [true, ''];
    }

    /**
     * บอกทั้งสองเดมอนให้อ่านค่าใหม่ — **ใช้คำสั่งของตัวมันเอง ไม่ใช่ systemctl restart**
     *
     * `postfix reload` กับ `doveadm reload` ทำงานเสร็จในเสี้ยววินาทีและไม่ตัดการเชื่อมต่อ
     * ที่กำลังใช้งานอยู่ · ส่วน `systemctl restart` หยุดแล้วสตาร์ตใหม่ ซึ่งแปลว่ามีช่วง
     * ที่เมลขาเข้าถูกปฏิเสธ และบนเครื่องที่ startup ช้าอาจใช้เวลานานกว่าที่ agent รอไหว
     * (เจอจริงตอนทดสอบ: คำสั่งเมลทุกคำสั่งได้ "agent ไม่ตอบกลับภายใน 30 วินาที")
     *
     * ถ้าเดมอนยังไม่ได้รันอยู่เลย reload จะล้ม — ค่อยถอยไปสั่งผ่าน systemctl ให้สตาร์ต
     */
    public function reload(Executor $executor): void
    {
        $commands = [
            'postfix' => [$executor->path('/usr/sbin/postfix'), 'reload'],
            'dovecot' => [$executor->path(self::DOVEADM), 'reload'],
        ];

        foreach ($commands as $unit => $command) {
            if ($executor->exec($command, timeout: 15)->ok()) {
                continue;
            }

            $executor->exec(
                [$executor->path('/usr/bin/systemctl'), 'reload-or-restart', $unit],
                timeout: 20,
            );
        }
    }

    /** @param list<string> $domains */
    private function renderDomains(array $domains): string
    {
        $lines = [$this->header('โดเมนที่เครื่องนี้รับเมลให้')];

        foreach ($domains as $domain) {
            // ค่าทางขวาไม่มีความหมาย Postfix ดูแค่ว่าคีย์มีอยู่ไหม
            $lines[] = $domain . ' OK';
        }

        return implode("\n", $lines) . "\n";
    }

    /** @param list<array{address:string,maildir:string,password:string,quota_mb:int}> $boxes */
    private function renderMailboxes(array $boxes): string
    {
        $lines = [$this->header('อีเมล → เส้นทาง Maildir (ทับท้ายคือสิ่งที่บอกว่าเป็น Maildir ไม่ใช่ mbox)')];

        foreach ($boxes as $box) {
            $lines[] = $box['address'] . ' ' . $box['maildir'];
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * @param list<array{source:string,destination:string}> $aliases
     * @param list<array{address:string,maildir:string,password:string,quota_mb:int}> $boxes
     */
    private function renderAliases(array $aliases, array $boxes): string
    {
        $lines = [$this->header('ที่อยู่ที่ส่งต่อไปที่อื่น · `@โดเมน` คือ catch-all ของโดเมนนั้น')];

        /*
         * **ทุกกล่องต้องมีบรรทัดชี้กลับหาตัวเองก่อน** — ไม่ใช่ของเกิน
         *
         * Postfix แปลง virtual alias **ซ้ำจนกว่าจะไม่มีอะไรตรง** · โดเมนที่มี catch-all
         * (`@example.com`) จะกลืนกล่องจริงทุกกล่องไปด้วย: เมลถึง `sales@` ถูกแปลงเป็น
         * `somchai@` ตาม alias แล้ว `somchai@` ถูกแปลงต่อด้วย catch-all จนไปโผล่ที่
         * กล่องของ catch-all แทน — กล่องจริงไม่ได้รับเมลเลยสักฉบับ
         *
         * บรรทัดที่ชี้กลับหาตัวเองหยุดการแปลงรอบสองไว้ตรงนั้น
         *
         * เจอจากการส่งเมลจริงเท่านั้น — ไฟล์ตั้งค่าทุกไฟล์ถูกต้องหมดและ `postmap -q`
         * ก็ตอบถูก แต่เมลไปผิดกล่อง (PLAN-MAIL M2, 2026-08-12)
         */
        foreach ($boxes as $box) {
            $lines[] = $box['address'] . ' ' . $box['address'];
        }

        foreach ($aliases as $alias) {
            $lines[] = $alias['source'] . ' ' . $alias['destination'];
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * ไฟล์ผู้ใช้ของ Dovecot — รูปแบบ passwd-file
     *
     * `user:hash::::::userdb_quota_rule=*:bytes=N`
     * ช่องที่เว้นว่างคือ uid/gid/gecos/home/shell ที่ไม่ได้ใช้ เพราะทุกกล่องรันด้วย
     * uid ของ vmail เหมือนกันหมด (ตั้งไว้ในไฟล์ตั้งค่า ไม่ใช่รายบรรทัด)
     *
     * @param list<array{address:string,maildir:string,password:string,quota_mb:int}> $boxes
     */
    private function renderDovecotUsers(array $boxes): string
    {
        $lines = [$this->header('กล่องจดหมายและแฮชรหัสผ่าน — ไฟล์นี้มีความลับ สิทธิ์ 0640')];

        foreach ($boxes as $box) {
            $lines[] = sprintf(
                '%s:%s::::::userdb_quota_rule=*:bytes=%dM',
                $box['address'],
                $box['password'],
                max(1, $box['quota_mb']),
            );
        }

        return implode("\n", $lines) . "\n";
    }

    private function header(string $what): string
    {
        return "# ไฟล์นี้สร้างโดย PHP Server Control Panel — ห้ามแก้ด้วยมือ\n"
            . '# ' . $what . "\n"
            . '# สร้างเมื่อ ' . date('Y-m-d H:i:s') . "\n";
    }

    private function postmap(Executor $executor, string $file): void
    {
        $result = $executor->exec([$executor->path(self::POSTMAP), $executor->path($file)], timeout: 30);

        if (!$result->ok()) {
            throw new ExecutionFailed('สร้างตาราง ' . basename($file) . ' ไม่สำเร็จ: ' . trim($result->stderr));
        }
    }
}
