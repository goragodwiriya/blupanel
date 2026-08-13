<?php

declare(strict_types=1);

namespace Phpcp\Agent\Capability;

use Phpcp\Agent\Context;
use Phpcp\Agent\Executor\Executor;
use Phpcp\Domain\MailboxRepository;
use Phpcp\Domain\SettingsRepository;
use Phpcp\Driver\Mail\DkimManager;
use Phpcp\Driver\Mail\MailCertificate;
use Phpcp\Driver\Ssl\CertbotManager;

/**
 * ความพร้อมของเมล — PLAN-MAIL §7
 *
 * **หัวใจของเฟส M3 อยู่ที่นี่** · PLAN-V2 เคยตัดเมลออกเพราะสามอย่างที่โค้ดแก้ให้ไม่ได้
 * (rDNS ตั้งได้ที่ผู้ให้บริการเท่านั้น · พอร์ต 25 ขาออกมักถูกบล็อก · IP ใหม่ไม่มี
 * ชื่อเสียง) · เฟสนี้ไม่ได้ทำให้สามข้อนั้นหายไป แต่ทำให้**ระบบบอกตรง ๆ** ว่าข้อไหน
 * ยังไม่พร้อมและข้อไหนต้องไปทำที่อื่น แทนที่จะปล่อยให้ผู้ดูแลค้นพบตอนที่ลูกค้าโทรมาบอก
 * ว่าเมลไม่ถึง
 *
 * ทุกข้อคืนสามอย่างเสมอ: ผ่านหรือไม่ · ค่าที่เจอจริง · ใครแก้ได้ (panel หรือที่อื่น)
 */
final class MailReadiness extends MailCapability
{
    public static function name(): string
    {
        return 'mail.readiness';
    }

    public function permission(): string
    {
        return 'mail.view';
    }

    public function isMutating(): bool
    {
        return false;
    }

    public function summary(): string
    {
        return 'ตรวจความพร้อมของเมลหกข้อ';
    }

    public function validate(array $args): array
    {
        return [];
    }

    public function run(array $args, Executor $executor, Context $context): array
    {
        $settings = new SettingsRepository($context->db);
        // ต้องเป็นชื่อที่ Postfix ประกาศจริง ไม่ใช่ค่าในช่องกรอก — หน้าความพร้อมที่บอกว่า
        // "ยังไม่ได้ตั้ง" ทั้งที่เครื่องประกาศชื่อถูกต้องอยู่ ทำให้ไล่ปัญหาผิดทางทั้งหมด
        $hostname = self::mailHostname($settings);
        $domains = (new MailboxRepository($context->db))->enabledDomains();

        $checks = [
            $this->hostnameCheck($hostname),
            $this->listeningCheck($executor),
            $this->outboundCheck($executor, $settings->get('mail.mode') ?: 'local'),
            $this->rdnsCheck($executor, $hostname),
            $this->dkimCheck($executor, $context, $domains),
            $this->tlsCheck($executor, $settings, $hostname),
        ];

        $failed = count(array_filter($checks, static fn (array $c): bool => !$c['ok']));

        return [
            'checks' => $checks,
            'ready' => $failed === 0,
            'failed' => $failed,
            'domains' => $domains,
            'message' => $failed === 0
                ? 'เมลพร้อมใช้งานครบทุกข้อ'
                : sprintf('ยังไม่พร้อม %d ข้อจาก %d ข้อ', $failed, count($checks)),
        ];
    }

    /** @return array{key:string,ok:bool,found:string,fix:string} */
    private function hostnameCheck(string $hostname): array
    {
        return [
            'key' => 'hostname',
            'ok' => $hostname !== '' && str_contains($hostname, '.'),
            'found' => $hostname !== '' ? $hostname : 'ยังไม่ได้ตั้ง',
            // ตั้งได้จากหน้าตั้งค่า จึงเป็นงานของ panel
            'fix' => 'panel',
        ];
    }

    /** @return array{key:string,ok:bool,found:string,fix:string} */
    private function listeningCheck(Executor $executor): array
    {
        $result = $executor->exec([$executor->path('/usr/bin/ss'), '-ltnH'], timeout: 10);
        $public = false;

        foreach (explode("\n", $result->stdout) as $line) {
            $fields = preg_split('/\s+/', trim($line)) ?: [];
            $local = $fields[3] ?? '';

            if (!str_ends_with($local, ':25')) {
                continue;
            }

            $address = trim(substr($local, 0, -3), '[]');

            if ($address !== '127.0.0.1' && $address !== '::1') {
                $public = true;
                break;
            }
        }

        return [
            'key' => 'listening',
            'ok' => $public,
            'found' => $public ? 'ฟังพอร์ต 25 จากภายนอกแล้ว' : 'ฟังแค่ loopback',
            'fix' => 'panel',
        ];
    }

    /**
     * ส่งออกได้จริงไหม — ต่อพอร์ต 25 ไปยัง MX ของ Gmail
     *
     * โหมด relay ไม่ต้องใช้พอร์ต 25 ขาออก จึงถือว่าผ่านเสมอ — การรายงานว่า "ไม่ผ่าน"
     * บนเครื่องที่ตั้งใจส่งผ่าน relay อยู่แล้วคือสัญญาณรบกวน ไม่ใช่ข้อมูล
     *
     * @return array{key:string,ok:bool,found:string,fix:string}
     */
    private function outboundCheck(Executor $executor, string $mode): array
    {
        if ($mode === 'relay') {
            return ['key' => 'outbound', 'ok' => true, 'found' => 'ส่งผ่าน relay ไม่ต้องใช้พอร์ต 25 ขาออก', 'fix' => 'panel'];
        }

        $socket = @stream_socket_client('tcp://gmail-smtp-in.l.google.com:25', $errno, $error, 6);
        $ok = is_resource($socket);

        if ($ok) {
            fclose($socket);
        }

        return [
            'key' => 'outbound',
            'ok' => $ok,
            'found' => $ok ? 'ต่อพอร์ต 25 ออกไปได้' : 'พอร์ต 25 ขาออกถูกบล็อก',
            // ปลดบล็อกต้องยื่นเรื่องกับผู้ให้บริการ หรือสลับไปใช้ relay
            'fix' => $ok ? 'panel' : 'provider',
        ];
    }

    /** @return array{key:string,ok:bool,found:string,fix:string} */
    private function rdnsCheck(Executor $executor, string $hostname): array
    {
        $ip = trim($executor->exec([$executor->path('/bin/sh'), '-c', 'hostname -I | awk "{print \$1}"'], timeout: 10)->stdout);
        $ptr = $ip !== '' ? trim((string) gethostbyaddr($ip)) : '';

        return [
            'key' => 'rdns',
            'ok' => $ptr !== '' && $ptr !== $ip && $ptr === $hostname,
            'found' => $ptr !== '' ? $ptr : 'ไม่มี PTR',
            // **ข้อเดียวที่ panel ทำให้ไม่ได้เลย** — ต้องตั้งที่ผู้ให้บริการ VPS
            'fix' => 'provider',
        ];
    }

    /**
     * @param list<string> $domains
     * @return array{key:string,ok:bool,found:string,fix:string}
     */
    private function dkimCheck(Executor $executor, Context $context, array $domains): array
    {
        if ($domains === []) {
            return ['key' => 'dkim', 'ok' => false, 'found' => 'ยังไม่มีโดเมนที่เปิดเมล', 'fix' => 'panel'];
        }

        $manager = new DkimManager(new \Phpcp\Driver\Template($context->config->paths->templates()));
        $missing = [];

        foreach ($domains as $domain) {
            if (!$executor->exists($executor->path($manager->keyPath($domain)))) {
                $missing[] = $domain;
            }
        }

        return [
            'key' => 'dkim',
            'ok' => $missing === [],
            'found' => $missing === []
                ? sprintf('มีกุญแจครบ %d โดเมน', count($domains))
                : 'ยังไม่มีกุญแจ: ' . implode(', ', $missing),
            'fix' => 'panel',
        ];
    }

    /**
     * ใบรับรองของ mail hostname — **อ่านจากไฟล์จริง ไม่ใช่เชื่อค่าในฐานข้อมูล**
     *
     * ค่าที่บันทึกไว้บอกได้แค่ว่า "เคยผูกใบไว้" · สิ่งที่ผู้ดูแลต้องรู้จริง ๆ คือใบที่
     * เดมอนหยิบไปใช้ตอนนี้หมดอายุหรือยัง และชื่อในใบตรงกับชื่อที่เครื่องประกาศไหม —
     * ใบที่หมดอายุเมื่อวานยังเป็นไฟล์ที่ "มีอยู่" ทุกประการ (§7 บอกไว้ว่าอ่านวันหมดอายุ
     * จากไฟล์)
     *
     * @return array{key:string,ok:bool,found:string,fix:string}
     */
    private function tlsCheck(Executor $executor, SettingsRepository $settings, string $hostname): array
    {
        $cert = trim($settings->get('mail.tls_cert'));

        if ($cert === '' || !$executor->exists($executor->path($cert))) {
            /*
             * ยังไม่ได้ผูกใบ — แต่บอกด้วยว่ามีใบที่ใช้ได้รออยู่บนเครื่องหรือเปล่า
             * "ยังไม่ได้ตั้ง" กับ "ขอใบมาแล้วแต่ยังไม่ได้กดผูก" ต้องแก้คนละแบบ
             */
            $available = (new MailCertificate(new CertbotManager()))->locate($executor, $hostname);

            return [
                'key' => 'tls',
                'ok' => false,
                'found' => $available !== null
                    ? sprintf('มีใบของ %s อยู่แล้วแต่เมลยังไม่ได้ใช้ — กดปุ่มผูกใบรับรอง', $available['name'])
                    : 'ใช้ใบรับรองของดิสโทร (โปรแกรมเมลจะเตือน)',
                'fix' => 'panel',
            ];
        }

        $info = (new CertbotManager())->inspectFile($executor, $cert);
        $status = (string) ($info['status'] ?? 'invalid');
        $days = (int) ($info['days_left'] ?? 0);
        $covers = MailCertificate::covers((array) ($info['domains'] ?? []), $hostname);

        return [
            'key' => 'tls',
            // ใกล้หมดอายุยังถือว่าผ่าน — certbot ต่อให้เองที่ 30 วัน การขึ้นสีแดงตรงนั้น
            // คือการฝึกให้ผู้ดูแลเพิกเฉยกับข้อที่แดงอยู่ตลอดโดยไม่มีอะไรให้ทำ
            'ok' => in_array($status, ['valid', 'expiring'], true) && $covers,
            'found' => match (true) {
                !$covers => sprintf('ใบนี้ไม่ครอบคลุม %s (ในใบมี: %s)', $hostname, implode(', ', (array) ($info['domains'] ?? [])) ?: '—'),
                $status === 'expired' => sprintf('หมดอายุแล้ว (%s)', $cert),
                $status === 'invalid' => sprintf('อ่านไฟล์ใบรับรองไม่ได้ (%s)', $cert),
                default => sprintf('%s · เหลือ %d วัน', $cert, $days),
            },
            'fix' => 'panel',
        ];
    }
}
