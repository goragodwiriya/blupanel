<?php

declare(strict_types=1);

namespace Phpcp\Driver\Mail;

use Phpcp\Agent\ExecutionFailed;
use Phpcp\Agent\Executor\Executor;
use Phpcp\Agent\ValidationError;
use Phpcp\Driver\ConfigTransaction;
use Phpcp\Driver\SafeBlock;
use Phpcp\Driver\Template;

/**
 * เมลขาออกผ่าน Postfix — ตั้งใจทำแค่ "ส่งออก" ไม่ใช่เมลเซิร์ฟเวอร์เต็มรูปแบบ
 *
 * ## ทำไมถึงไม่ทำเมลเซิร์ฟเวอร์เต็มรูปแบบ
 *
 * สิ่งที่เว็บไซต์ต้องการจริง ๆ คือ "ส่งเมลยืนยันการสมัครออกไปได้" ซึ่งเป็นคนละเรื่อง
 * กับการเป็นเมลเซิร์ฟเวอร์ที่รับเมลเข้าและเก็บกล่องจดหมาย อย่างหลังต้องมี
 * Dovecot + ระบบผู้ใช้เมล + โควตา + antispam + antivirus + webmail + การสำรอง
 * กล่องจดหมาย และที่สำคัญที่สุดคือ **ต้องมีคนดูแลชื่อเสียงของไอพีตลอดเวลา**
 *
 * เมลเซิร์ฟเวอร์ที่ตั้งไม่ครบเป็นภาระมากกว่าประโยชน์: ถ้า SPF/DKIM/DMARC/rDNS
 * ไม่ครบ เมลจะเข้าถังขยะหรือถูกปฏิเสธทั้งหมด และถ้าตั้ง relay ผิดจนกลายเป็น
 * open relay เครื่องจะถูกใช้ส่งสแปมภายในไม่กี่ชั่วโมง แล้วไอพีติดบัญชีดำถาวร
 * ซึ่งกระทบทุกเว็บบนเครื่องเดียวกัน
 *
 * v1 จึงรองรับสองโหมดที่ปลอดภัยและใช้งานได้จริง:
 *
 *   local  ส่งตรงจากเครื่องนี้ — ง่ายที่สุด เหมาะกับเมลภายในและการแจ้งเตือน
 *          แต่ผู้รับปลายทางอาจตีเป็นสแปมถ้า DNS ยังไม่ครบ
 *   relay  ส่งผ่านผู้ให้บริการภายนอก (SendGrid, Amazon SES, Gmail, ฯลฯ)
 *          **แนะนำสำหรับเว็บสาธารณะ** เพราะเรื่องชื่อเสียงไอพีเป็นภาระของเขา
 *
 * ทั้งสองโหมดตั้งค่าให้ Postfix **รับคำขอส่งจากเครื่องนี้เท่านั้น** (loopback)
 * จึงไม่มีทางกลายเป็น open relay
 */
final class MailManager
{
    private const MAIN_CF = '/etc/postfix/main.cf';
    private const MASTER_CF = '/etc/postfix/master.cf';
    private const SASL_FILE = '/etc/postfix/sasl_passwd';
    private const POSTFIX = '/usr/sbin/postfix';
    private const POSTMAP = '/usr/sbin/postmap';

    public function __construct(private readonly Template $templates)
    {
    }

    public function isInstalled(Executor $executor): bool
    {
        return $executor->isSimulated() || $executor->exists($executor->path(self::MAIN_CF));
    }

    /**
     * สถานะปัจจุบันที่อ่านจากเครื่องจริง
     *
     * @return array{installed:bool,mode:string,relay_host:string,hostname:string,queued:int,open_relay:bool}
     */
    public function status(Executor $executor): array
    {
        if (!$this->isInstalled($executor)) {
            return [
                'installed' => false, 'mode' => '', 'relay_host' => '',
                'hostname' => '', 'queued' => 0, 'open_relay' => false,
            ];
        }

        $main = '';

        try {
            $main = $executor->readFile($executor->path(self::MAIN_CF));
        } catch (\Throwable) {
            // อ่านไม่ได้ก็ยังรายงานสถานะที่เหลือได้ ดีกว่าทั้งหน้าพัง
        }

        $relay = $this->directive($main, 'relayhost');

        return [
            'installed' => true,
            'mode' => $relay === '' ? 'local' : 'relay',
            'relay_host' => trim($relay, '[]'),
            'hostname' => $this->directive($main, 'myhostname'),
            'queued' => $this->queueSize($executor),
            // ตรวจว่าเผลอเปิดรับจากทั้งโลกไว้หรือไม่ — เป็นความผิดพลาดที่ราคาแพงที่สุด
            'open_relay' => $this->looksLikeOpenRelay($main),
        ];
    }

    private function directive(string $main, string $name): string
    {
        if (preg_match('/^' . preg_quote($name, '/') . '\s*=\s*(.+)$/m', $main, $m) !== 1) {
            return '';
        }

        return trim($m[1]);
    }

    /**
     * เดาว่าเป็น open relay หรือไม่จากค่าที่อันตรายที่สุดสองตัว
     *
     * ไม่ได้ครอบคลุมทุกกรณี แต่จับความผิดพลาดที่พบบ่อยที่สุดได้:
     * เปิด inet_interfaces เป็น all แล้วอนุญาต mynetworks กว้างเกินไป
     */
    private function looksLikeOpenRelay(string $main): bool
    {
        $interfaces = $this->directive($main, 'inet_interfaces');
        $networks = $this->directive($main, 'mynetworks');

        if ($interfaces === '' || str_contains($interfaces, 'loopback-only')) {
            return false;
        }

        return str_contains($networks, '0.0.0.0/0') || str_contains($networks, '::/0');
    }

    private function queueSize(Executor $executor): int
    {
        $result = $executor->exec([$executor->path('/usr/bin/mailq')], timeout: 15);

        if (!$result->ok()) {
            return 0;
        }

        // mailq พิมพ์ "Mail queue is empty" หรือสรุปท้ายว่า "-- N Kbytes in M Requests."
        if (preg_match('/(\d+) Request/', $result->stdout, $m) === 1) {
            return (int) $m[1];
        }

        return 0;
    }

    /**
     * เขียนค่าตั้งของ Postfix ใหม่ทั้งชุด
     *
     * @param array{mode:string,hostname:string,from:string,relay_host:string,relay_port:int,relay_user:string,relay_password:string,relay_tls:bool} $config
     */
    public function apply(Executor $executor, array $config, bool $reload = true): array
    {
        if (!$this->isInstalled($executor)) {
            throw new ValidationError(
                'ไม่พบ Postfix บนเครื่องนี้ — ติดตั้งด้วย apt install postfix ก่อน '
                . '(เลือก "Internet Site" หรือ "Satellite system" ตอนติดตั้งก็ได้ ระบบจะเขียนค่าให้ใหม่ทั้งหมด)',
            );
        }

        $mode = $config['mode'];

        if (!in_array($mode, ['local', 'relay'], true)) {
            throw new ValidationError('โหมดเมลต้องเป็น local หรือ relay');
        }

        $relayLine = '';
        $transaction = new ConfigTransaction($executor);

        if ($mode === 'relay') {
            $host = self::assertHost($config['relay_host']);
            $port = self::assertPort($config['relay_port']);

            // วงเล็บเหลี่ยมบอก Postfix ว่าอย่าไปหา MX record ของโฮสต์นี้ —
            // ผู้ให้บริการ relay เกือบทั้งหมดต้องการแบบนี้ ถ้าไม่ใส่จะส่งไม่ออก
            $relayLine = sprintf('[%s]:%d', $host, $port);

            if ($config['relay_user'] !== '') {
                $transaction->write(
                    self::SASL_FILE,
                    sprintf(
                        "%s %s:%s\n",
                        $relayLine,
                        Template::assertValue('relay_user', $config['relay_user']),
                        Template::assertValue('relay_password', $config['relay_password']),
                    ),
                    // รหัสผ่านของผู้ให้บริการเมล — ผู้ใช้อื่นบนเครื่องต้องอ่านไม่ได้เลย
                    0600,
                );
            }
        }

        $hosting = (bool) ($config['hosting'] ?? false);
        $interfaces = $hosting ? 'all' : 'loopback-only';

        /*
         * **เปลี่ยนหน้าตัดเน็ตที่ฟังต้อง restart ไม่ใช่ reload**
         *
         * `postfix reload` อ่านค่าใหม่ทุกค่ายกเว้นค่าที่ต้องเปิด socket ใหม่ · เปิดเมล
         * ให้โดเมนแรกแล้วสั่งแค่ reload จะได้ main.cf ที่เขียนว่า `all` แต่ Postfix
         * ยังฟังแค่ loopback อยู่ — เมลจากอินเทอร์เน็ตไม่มีทางถึงเครื่องนี้เลย และ
         * ไม่มีอะไรฟ้อง เพราะทุกอย่างในไฟล์ถูกต้องหมด (เจอจริงบนเครื่องจริง 2026-08-12)
         *
         * อ่านค่าเดิมจากไฟล์ก่อนเขียนทับ เพื่อรู้ว่าครั้งนี้เปลี่ยนหรือไม่
         */
        $previous = '';

        try {
            $previous = $this->directive($executor->readFile($executor->path(self::MAIN_CF)), 'inet_interfaces');
        } catch (\Throwable) {
            // อ่านไม่ได้ = ถือว่าเปลี่ยน ปลอดภัยกว่าเดาว่าเหมือนเดิม
        }

        $restartRequired = $previous !== $interfaces;

        $transaction->write(self::MAIN_CF, $this->templates->render('postfix/main.cf.tpl', [
            'HOSTNAME' => self::assertHost($config['hostname']),
            'ORIGIN' => self::assertHost($config['hostname']),
            'RELAY_HOST' => $relayLine,
            'SASL_ENABLED' => $mode === 'relay' && $config['relay_user'] !== '' ? 'yes' : 'no',
            'TLS_SECURITY' => $config['relay_tls'] ? 'encrypt' : 'may',
            // เปิดรับเมลเข้าต้องฟังทุกหน้าตัดเน็ต ไม่ใช่แค่ loopback — แต่ mynetworks
            // ยังแคบเท่าเดิม คนนอกที่จะส่งผ่านเราต้องล็อกอินก่อนเสมอ
            'INET_INTERFACES' => $interfaces,
            'HOSTING_SECTION' => new SafeBlock($hosting ? $this->hostingSection($config) : ''),
            'GENERATED_AT' => date('Y-m-d H:i:s'),
        ]), 0644);

        $transaction->write(self::MASTER_CF, $this->templates->render('postfix/master.cf.tpl', [
            'SUBMISSION_SECTION' => new SafeBlock(
                $hosting ? $this->templates->render('postfix/submission.cf.tpl', []) : '',
            ),
            'GENERATED_AT' => date('Y-m-d H:i:s'),
        ]), 0644);

        $transaction->commit(fn (): array => $this->testConfig($executor));

        if ($mode === 'relay' && $config['relay_user'] !== '') {
            // postmap สร้างไฟล์ .db ที่ Postfix อ่านจริง — ไฟล์ข้อความอย่างเดียวไม่มีผล
            $result = $executor->exec([$executor->path(self::POSTMAP), $executor->path(self::SASL_FILE)], timeout: 30);

            if (!$result->ok()) {
                throw new ExecutionFailed('สร้างตารางรหัสผ่านของ Postfix ไม่สำเร็จ: ' . trim($result->stderr));
            }

            $executor->changeMode($executor->path(self::SASL_FILE . '.db'), 0600);
        }

        // ผู้เรียกที่กำลังทำงานใหญ่กว่านี้ (เปิดเมลให้โดเมน ซึ่งต้องเขียนตารางค้นหาต่อ)
        // สั่ง reload เองครั้งเดียวตอนจบ — reload กลางทางแล้วล้มจะทำให้ขั้นที่เหลือ
        // ไม่ได้ทำงาน ทั้งที่ไฟล์ที่เขียนไปแล้วถูกต้องทุกไฟล์
        if ($reload) {
            $this->reload($executor);
        }

        return ['mode' => $mode, 'relay' => $relayLine, 'restart_required' => $restartRequired];
    }

    /**
     * ส่วนของ main.cf ที่มีอยู่เฉพาะตอนเปิดรับเมลเข้า
     *
     * แยกเป็นเทมเพลตของตัวเองเพราะเป็นคนละเรื่องกับการส่งออก และเครื่องส่วนใหญ่
     * จะไม่มีส่วนนี้เลย — ไฟล์ตั้งค่าที่สั้นกว่าคือไฟล์ที่ตรวจสอบง่ายกว่า
     *
     * @param array<string,mixed> $config
     */
    private function hostingSection(array $config): string
    {
        // ใบรับรองของ mail hostname ถ้ายังไม่มี ใช้ใบที่ดิสโทรสร้างให้ไปก่อน —
        // ดีกว่าไม่มี TLS เลย และหน้าความพร้อมของเมลจะฟ้องว่ายังไม่ได้ขอใบจริง
        $cert = (string) ($config['tls_cert'] ?? '');
        $key = (string) ($config['tls_key'] ?? '');

        return $this->templates->render('postfix/hosting.cf.tpl', [
            'TLS_CERT' => $cert !== '' ? $cert : '/etc/ssl/certs/ssl-cert-snakeoil.pem',
            'TLS_KEY' => $key !== '' ? $key : '/etc/ssl/private/ssl-cert-snakeoil.key',
        ]);
    }

    /**
     * ตรวจค่าตั้งด้วย Postfix เอง
     *
     * @return array{0:bool,1:string}
     */
    public function testConfig(Executor $executor): array
    {
        $result = $executor->exec([$executor->path(self::POSTFIX), 'check'], timeout: 20);
        $output = trim($result->stderr) !== '' ? $result->stderr : $result->stdout;

        return [$result->ok(), $output];
    }

    /**
     * สตาร์ต Postfix ใหม่ทั้งตัว — ใช้เมื่อพอร์ตที่ฟังเปลี่ยนเท่านั้น
     *
     * แพงกว่า reload (มีช่วงสั้น ๆ ที่ไม่รับเมล) จึงไม่ใช้เป็นค่าเริ่มต้น
     */
    public function restart(Executor $executor): void
    {
        $executor->exec(
            [$executor->path('/usr/bin/systemctl'), 'restart', 'postfix'],
            timeout: 60,
        );
    }

    public function reload(Executor $executor): void
    {
        $result = $executor->exec([$executor->path('/usr/bin/systemctl'), 'reload-or-restart', 'postfix'], timeout: 30);

        if (!$result->ok()) {
            throw new ExecutionFailed(
                "เขียนค่าตั้งเรียบร้อยแล้วแต่สั่ง reload postfix ไม่สำเร็จ — ค่าใหม่จะยังไม่มีผล\n\n"
                . trim($result->stderr ?: $result->stdout),
            );
        }
    }

    /**
     * ส่งเมลทดสอบ
     *
     * ใช้ `sendmail` ไม่ใช่ฟังก์ชัน mail() ของ PHP เพราะต้องพิสูจน์ว่า
     * "เส้นทางของระบบ" ใช้ได้จริง ซึ่งเป็นเส้นทางเดียวกับที่เว็บไซต์ของผู้ใช้จะใช้
     */
    public function sendTest(Executor $executor, string $to, string $from): array
    {
        $to = self::assertEmail($to);
        $from = self::assertEmail($from);

        $message = sprintf(
            "From: %s\r\nTo: %s\r\nSubject: =?UTF-8?B?%s?=\r\n"
            . "MIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\n\r\n%s\r\n",
            $from,
            $to,
            base64_encode('ทดสอบเมลขาออกจาก PHP Server Control Panel'),
            "ถ้าคุณได้รับข้อความนี้ แปลว่าเมลขาออกของเซิร์ฟเวอร์ทำงานแล้ว\n\n"
            . 'ส่งเมื่อ ' . date('d/m/Y H:i:s'),
        );

        $result = $executor->exec(
            [$executor->path('/usr/sbin/sendmail'), '-t', '-i', '-f', $from],
            timeout: 30,
            stdin: $message,
        );

        if (!$result->ok()) {
            throw new ExecutionFailed('ส่งเมลทดสอบไม่สำเร็จ: ' . trim($result->stderr ?: $result->stdout));
        }

        return [
            'to' => $to,
            'queued' => $this->queueSize($executor),
        ];
    }

    public static function assertEmail(string $email): string
    {
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new ValidationError("อีเมลไม่ถูกต้อง: {$email}");
        }

        return $email;
    }

    public static function assertHost(string $host): string
    {
        if ($host === '') {
            throw new ValidationError('ต้องระบุชื่อโฮสต์');
        }

        // รับได้ทั้งชื่อโดเมนและไอพี — ผู้ให้บริการ relay บางรายให้มาเป็นไอพี
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return $host;
        }

        if (preg_match('/^(?=.{1,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/i', $host) !== 1) {
            throw new ValidationError("ชื่อโฮสต์ไม่ถูกต้อง: {$host}");
        }

        return strtolower($host);
    }

    public static function assertPort(int $port): int
    {
        if ($port < 1 || $port > 65535) {
            throw new ValidationError('หมายเลขพอร์ตต้องอยู่ระหว่าง 1 ถึง 65535');
        }

        return $port;
    }
}
