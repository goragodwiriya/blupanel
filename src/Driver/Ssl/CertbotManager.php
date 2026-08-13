<?php

declare(strict_types=1);

namespace Phpcp\Driver\Ssl;

use Phpcp\Agent\ExecutionFailed;
use Phpcp\Agent\Executor\Executor;
use Phpcp\Agent\ValidationError;
use Phpcp\Domain\Site;
use Phpcp\Support\Validator;

/**
 * ใบรับรอง SSL ผ่าน Let's Encrypt (certbot) — PROMPT.md หัวข้อ SSL Certificates
 *
 * สองการตัดสินใจที่สำคัญที่สุดของไฟล์นี้:
 *
 * 1. **ใช้ `--webroot` ไม่ใช่ `--apache`** — plugin ของ apache จะเข้าไปแก้ vhost ให้เอง
 *    แต่ vhost ทุกไฟล์ที่นี่ถูกสร้างจาก template และถูกเขียนทับทุกครั้งที่เว็บไซต์เปลี่ยน
 *    ถ้าปล่อยให้ certbot แก้ด้วย การแก้นั้นจะหายไปเงียบ ๆ ในการเปลี่ยนแปลงครั้งถัดไป
 *    แล้วเว็บที่เคยเป็น HTTPS จะกลับไปเป็น HTTP โดยไม่มีใครรู้
 *    การถือสิทธิ์ความเป็นเจ้าของไฟล์ config ไว้ที่เดียวสำคัญกว่าความสะดวก
 *
 * 2. **อ่านวันหมดอายุจากไฟล์ใบรับรองจริงด้วย openssl** ไม่ใช่จากสรุปของ certbot
 *    เพราะสิ่งที่ Apache ใช้งานจริงคือไฟล์ PEM ถ้าไฟล์กับฐานข้อมูลของ certbot
 *    ไม่ตรงกัน (เช่นมีคนคัดลอกใบรับรองมาวางเอง) หน้าจอต้องบอกความจริงของไฟล์
 */
final class CertbotManager
{
    private const BINARY = '/usr/bin/certbot';
    private const OPENSSL = '/usr/bin/openssl';

    /**
     * ตัวตอบโจทย์ DNS-01 ที่ certbot เรียกตอนขอใบ wildcard (PLAN-V2 เฟส E7)
     *
     * ต้องเป็นเส้นทางสัมบูรณ์ของเครื่องที่ติดตั้งจริง ไม่ใช่เส้นทางในโปรเจกต์ —
     * certbot รันมันเป็นโปรเซสลูกโดยไม่ผ่าน shell ของเรา
     */
    private const HOOK = '/usr/share/phpcp/bin/phpcp-acme-hook';

    /** ที่อยู่มาตรฐานของ Let's Encrypt */
    public const LIVE_DIR = '/etc/letsencrypt/live';

    /** ใบรับรองที่ panel สร้างเองสำหรับเว็บที่ยังไม่มีใบจริง */
    public const SELF_SIGNED_DIR = '/etc/phpcp/ssl';

    /** เตือนเมื่อเหลือน้อยกว่านี้ — certbot ต่ออายุอัตโนมัติที่ 30 วัน */
    public const WARN_DAYS = 21;

    public function isInstalled(Executor $executor): bool
    {
        return $executor->isSimulated() || $executor->exists(self::BINARY);
    }

    /**
     * ข้อมูลใบรับรองของเว็บไซต์หนึ่ง
     *
     * @return array{
     *     status:string, source:string, path:string, issuer:string, subject:string,
     *     domains:list<string>, expires_at:int, days_left:int, auto_renew:bool
     * }
     */
    public function inspect(Executor $executor, Site $site): array
    {
        foreach ([[self::LIVE_DIR, 'letsencrypt'], [self::SELF_SIGNED_DIR, 'self-signed']] as [$dir, $source]) {
            $path = $dir . '/' . $site->domain . '/fullchain.pem';

            if (!$executor->exists($executor->path($path))) {
                continue;
            }

            $info = $this->readCertificate($executor, $path);
            $info['source'] = $source;
            $info['path'] = $path;
            // ต่ออายุอัตโนมัติมีเฉพาะใบของ Let's Encrypt เพราะ timer ของ certbot เป็นคนทำ
            $info['auto_renew'] = $source === 'letsencrypt';

            return $info;
        }

        return [
            'status' => 'none',
            'source' => '',
            'path' => '',
            'issuer' => '',
            'subject' => '',
            'domains' => [],
            'expires_at' => 0,
            'days_left' => 0,
            'auto_renew' => false,
        ];
    }

    /**
     * อ่านรายละเอียดของไฟล์ใบรับรองใบใดก็ได้บนเครื่อง
     *
     * มีไว้ให้ผู้ใช้นอกหน้าจอ SSL — ใบรับรองของ mail hostname ไม่ผูกกับเว็บไซต์
     * ใดเว็บไซต์หนึ่ง (PLAN-MAIL เฟส M3) จึงเรียก inspect() ที่รับ Site ไม่ได้
     *
     * @return array<string,mixed>
     */
    public function inspectFile(Executor $executor, string $path): array
    {
        return $this->readCertificate($executor, $path);
    }

    /**
     * อ่านรายละเอียดจากไฟล์ PEM ด้วย openssl
     *
     * @return array<string,mixed>
     */
    private function readCertificate(Executor $executor, string $path): array
    {
        $result = $executor->exec(
            [self::OPENSSL, 'x509', '-in', $executor->path($path), '-noout', '-subject', '-issuer', '-enddate', '-ext', 'subjectAltName'],
            timeout: 15,
        );

        if (!$result->ok()) {
            return [
                'status' => 'invalid',
                'issuer' => '',
                'subject' => '',
                'domains' => [],
                'expires_at' => 0,
                'days_left' => 0,
            ];
        }

        $out = $result->stdout;
        $expiresAt = 0;

        if (preg_match('/^notAfter=(.+)$/m', $out, $m) === 1) {
            $expiresAt = strtotime(trim($m[1])) ?: 0;
        }

        $daysLeft = $expiresAt > 0 ? (int) floor(($expiresAt - time()) / 86400) : 0;

        return [
            'status' => match (true) {
                $expiresAt === 0 => 'invalid',
                $expiresAt <= time() => 'expired',
                $daysLeft <= self::WARN_DAYS => 'expiring',
                default => 'valid',
            },
            'issuer' => $this->field($out, 'issuer'),
            'subject' => $this->field($out, 'subject'),
            'domains' => $this->altNames($out),
            'expires_at' => $expiresAt,
            'days_left' => $daysLeft,
        ];
    }

    private function field(string $out, string $name): string
    {
        if (preg_match('/^' . preg_quote($name, '/') . '=(.+)$/m', $out, $m) !== 1) {
            return '';
        }

        // openssl คืนมาเป็น "C = US, O = Let's Encrypt, CN = R11" — เอา CN มาแสดงก็พอ
        if (preg_match('/CN\s*=\s*([^,\/]+)/', $m[1], $cn) === 1) {
            return trim($cn[1]);
        }

        return trim($m[1]);
    }

    /** @return list<string> */
    private function altNames(string $out): array
    {
        if (preg_match('/X509v3 Subject Alternative Name:\s*\R\s*(.+)/', $out, $m) !== 1) {
            return [];
        }

        $names = [];

        foreach (explode(',', $m[1]) as $entry) {
            $entry = trim($entry);

            if (str_starts_with($entry, 'DNS:')) {
                $names[] = substr($entry, 4);
            }
        }

        return $names;
    }

    /**
     * ขอใบรับรองจาก Let's Encrypt ด้วยวิธี webroot
     *
     * @param list<string> $domains โดเมนทั้งหมดที่ต้องอยู่ในใบเดียวกัน
     */
    public function issue(Executor $executor, Site $site, array $domains, string $email, bool $staging): array
    {
        if (!$this->isInstalled($executor)) {
            throw new ValidationError('ไม่พบ certbot บนเครื่องนี้ — ติดตั้งด้วย apt install certbot ก่อน');
        }

        if ($domains === []) {
            throw new ValidationError('ต้องระบุโดเมนอย่างน้อยหนึ่งชื่อ');
        }

        $webroot = $executor->path($site->docroot());

        if (!$executor->exists($webroot)) {
            throw new ValidationError('ไม่พบไดเรกทอรีของเว็บไซต์ — สร้างเว็บไซต์ให้เรียบร้อยก่อนขอใบรับรอง');
        }

        // wildcard ออกด้วย HTTP-01 ไม่ได้เลย — Let's Encrypt บังคับ DNS-01 อย่างเดียว
        // เพราะการพิสูจน์ว่าคุมไฟล์บนเว็บได้ ไม่ได้พิสูจน์ว่าคุมทั้งโดเมน
        $hasWildcard = false;
        foreach ($domains as $domain) {
            $hasWildcard = $hasWildcard || str_starts_with($domain, '*.');
        }

        $argv = [
            self::BINARY, 'certonly',
            '--non-interactive', '--agree-tos',
            '--email', self::assertEmail($email),
            // ชื่อชุดใบรับรองผูกกับโดเมนหลักเสมอ เพื่อให้ path เดาได้และลบถูกใบ
            '--cert-name', Validator::domain($site->domain),
            // ไม่ให้ certbot ไปยุ่งกับ config ของเว็บเซิร์ฟเวอร์ — เราเป็นเจ้าของไฟล์นั้น
            '--no-eff-email',
        ];

        if ($hasWildcard) {
            array_push(
                $argv,
                '--manual',
                '--preferred-challenges', 'dns',
                '--manual-auth-hook', $executor->path(self::HOOK) . ' auth',
                '--manual-cleanup-hook', $executor->path(self::HOOK) . ' cleanup',
            );
        } else {
            array_push($argv, '--webroot', '--webroot-path', $webroot);
        }

        foreach ($domains as $domain) {
            // `*.example.com` ไม่ผ่าน Validator::domain เพราะ `*` ไม่ใช่อักขระของชื่อโฮสต์
            // — ตรวจส่วนที่เหลือแทน แล้วประกอบกลับ เพื่อไม่ให้ด่านตรวจถูกข้ามไปเฉย ๆ
            array_push($argv, '-d', str_starts_with($domain, '*.')
                ? '*.' . Validator::domain(substr($domain, 2))
                : Validator::domain($domain));
        }

        if ($staging) {
            // เซิร์ฟเวอร์ทดสอบของ Let's Encrypt ไม่นับโควตา ใช้ซ้อมได้ไม่จำกัด
            // ใบที่ได้เบราว์เซอร์ไม่เชื่อถือ แต่พิสูจน์ว่าขั้นตอน ACME ทั้งชุดทำงานจริง
            $argv[] = '--staging';
        }

        $result = $executor->exec($argv, timeout: 180);

        if (!$result->ok()) {
            throw new ExecutionFailed($this->explain($result->stderr ?: $result->stdout));
        }

        return ['output' => trim($result->stdout), 'staging' => $staging];
    }

    /** ต่ออายุใบเดียว — ไม่ใช้ `certbot renew` เปล่า ๆ ที่จะไปแตะทุกใบบนเครื่อง */
    public function renew(Executor $executor, string $certName, bool $force): array
    {
        $argv = [self::BINARY, 'renew', '--cert-name', Validator::domain($certName), '--non-interactive'];

        if ($force) {
            // ปกติ certbot ข้ามใบที่ยังไม่ใกล้หมดอายุ ปุ่ม "ต่ออายุเดี๋ยวนี้" จึงต้องบังคับ
            $argv[] = '--force-renewal';
        }

        $result = $executor->exec($argv, timeout: 180);

        if (!$result->ok()) {
            throw new ExecutionFailed($this->explain($result->stderr ?: $result->stdout));
        }

        return ['output' => trim($result->stdout)];
    }

    public function delete(Executor $executor, string $certName): void
    {
        $name = Validator::domain($certName);
        $result = $executor->exec([self::BINARY, 'delete', '--cert-name', $name, '--non-interactive'], timeout: 60);

        if (!$result->ok()) {
            throw new ExecutionFailed($this->explain($result->stderr ?: $result->stdout));
        }
    }

    /**
     * สร้างใบรับรองที่เซ็นเอง
     *
     * มีไว้สองกรณี: เครื่องที่ยังไม่ได้ชี้โดเมนมาจริงจึงขอ Let's Encrypt ไม่ได้
     * และเว็บภายในที่ไม่มีชื่อโดเมนสาธารณะเลย
     *
     * เบราว์เซอร์จะขึ้นคำเตือนเสมอ ซึ่งถูกต้องแล้ว — หน้าจอต้องบอกเรื่องนี้ให้ชัด
     * ไม่ใช่ทำให้ดูเหมือนใบรับรองปกติ
     *
     * @param list<string> $domains โดเมนทั้งหมดของเว็บ · ว่าง = ใช้โดเมนหลักอย่างเดียว
     */
    public function selfSign(Executor $executor, Site $site, array $domains = [], int $days = 825): array
    {
        $domain = Validator::domain($site->domain);
        $dir = self::SELF_SIGNED_DIR . '/' . $domain;
        $resolved = $executor->path($dir);

        $executor->makeDirectory($resolved, 0700);

        /*
         * **ต้องใส่ทุกโดเมนของเว็บ ไม่ใช่แค่โดเมนหลัก** — เหมือนที่ issue() ทำ
         *
         * ไคลเอนต์สมัยใหม่ดูแต่ subjectAltName ไม่สนใจ CN แล้ว · ใบที่มีแต่โดเมนหลัก
         * จึงถูกปฏิเสธทันทีเมื่อเข้าผ่านชื่ออื่นของเว็บเดียวกัน (`www.` หรือ
         * `mail.`) ทั้งที่หน้าจอบอกว่าติดตั้งใบเรียบร้อยแล้ว
         *
         * เจอตอนทำ M3: ชื่อโฮสต์ของเมลเป็นโดเมนหนึ่งของเว็บ ใบที่เซ็นเองจึงไม่ครอบคลุม
         * มันเลย แล้ว `mail.cert` มองไม่เห็นใบนั้น (ถูกต้องแล้ว) — ทางที่ควรใช้ได้
         * กลับตันโดยไม่มีอะไรอธิบาย
         */
        $names = [];

        foreach ($domains === [] ? [$site->domain] : $domains as $name) {
            // `*.example.com` ไม่ผ่าน Validator::domain เพราะ `*` ไม่ใช่อักขระของชื่อโฮสต์
            $names[] = str_starts_with((string) $name, '*.')
                ? '*.' . Validator::domain(substr((string) $name, 2))
                : Validator::domain((string) $name);
        }

        $names = array_values(array_unique($names));

        $result = $executor->exec([
            self::OPENSSL, 'req', '-x509', '-nodes',
            '-newkey', 'rsa:2048',
            '-days', (string) max(1, min($days, 3650)),
            '-keyout', $resolved . '/privkey.pem',
            '-out', $resolved . '/fullchain.pem',
            // -subj กันไม่ให้ openssl หยุดถามข้อมูลแบบโต้ตอบจนคำสั่งค้าง
            '-subj', '/CN=' . $domain,
            // ต้องรวมเป็น -addext เดียวคั่นด้วยจุลภาค · ส่งสองครั้ง openssl จะฟ้องซ้ำซ้อน
            '-addext', 'subjectAltName=' . implode(',', array_map(
                static fn (string $name): string => 'DNS:' . $name,
                $names,
            )),
            // `openssl req -x509` ตั้ง CA:TRUE ให้เองถ้าไม่ระบุ ซึ่งผิดสำหรับใบของเว็บไซต์
            // Apache จะเตือน AH01906 และเบราว์เซอร์รุ่นใหม่ปฏิเสธใบที่เป็น CA ทันที
            '-addext', 'basicConstraints=critical,CA:FALSE',
            '-addext', 'keyUsage=critical,digitalSignature,keyEncipherment',
            '-addext', 'extendedKeyUsage=serverAuth',
        ], timeout: 60);

        if (!$result->ok()) {
            throw new ExecutionFailed('สร้างใบรับรองที่เซ็นเองไม่สำเร็จ: ' . trim($result->stderr));
        }

        $executor->changeMode($resolved . '/privkey.pem', 0600);

        return ['path' => $dir];
    }

    /** ลบใบที่เซ็นเอง — แยกจาก delete() เพราะ certbot ไม่รู้จักใบพวกนี้ */
    public function deleteSelfSigned(Executor $executor, string $domain): void
    {
        $dir = self::SELF_SIGNED_DIR . '/' . Validator::domain($domain);
        $resolved = $executor->path($dir);

        if (!$executor->exists($resolved)) {
            return;
        }

        $real = $executor->realPath($resolved);
        $base = rtrim($executor->path(self::SELF_SIGNED_DIR), '/');

        // ตรวจหลัง realpath ด้วย เผื่อมี symlink ชี้ออกนอกไดเรกทอรี
        if ($real === null || !str_starts_with($real, $base . '/')) {
            throw new ValidationError('เส้นทางใบรับรองอยู่นอกไดเรกทอรีที่กำหนด — ยกเลิกการลบ');
        }

        $executor->removePath($real);
    }

    /** สถานะ timer ที่ต่ออายุอัตโนมัติ — ผู้ใช้ต้องเห็นว่ามันทำงานอยู่จริงหรือไม่ */
    public function autoRenewActive(Executor $executor): bool
    {
        $result = $executor->exec(
            [$executor->path('/usr/bin/systemctl'), 'is-enabled', 'certbot.timer'],
            timeout: 15,
        );

        return str_starts_with(trim($result->stdout), 'enabled');
    }

    public static function assertEmail(string $email): string
    {
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new ValidationError('อีเมลไม่ถูกต้อง — Let\'s Encrypt ใช้อีเมลนี้แจ้งเตือนใบรับรองใกล้หมดอายุ');
        }

        if (mb_strlen($email) > 254) {
            throw new ValidationError('อีเมลยาวเกินไป');
        }

        return $email;
    }

    /**
     * แปลข้อความผิดพลาดของ certbot เป็นสิ่งที่ผู้ดูแลแก้ต่อได้
     *
     * ข้อความดิบของ certbot ยาวและมีรายละเอียดของ ACME ปนมาก
     * ผู้ใช้ส่วนใหญ่เจอแค่ไม่กี่สาเหตุ จึงบอกสาเหตุกับทางแก้ตรง ๆ แล้วแนบต้นฉบับไว้ท้าย
     */
    private function explain(string $raw): string
    {
        $raw = trim($raw);

        $hint = match (true) {
            str_contains($raw, 'NXDOMAIN') || str_contains($raw, 'DNS problem') =>
                'โดเมนยังชี้มาที่เซิร์ฟเวอร์นี้ไม่ถูกต้อง — ตรวจ DNS record ให้ชี้มาที่ IP ของเครื่องนี้ก่อน',
            str_contains($raw, 'Timeout during connect') =>
                'Let\'s Encrypt เชื่อมต่อเข้ามาที่พอร์ต 80 ไม่ได้ — ตรวจว่า firewall เปิดพอร์ต 80 และ Apache ทำงานอยู่',
            str_contains($raw, 'too many certificates') || str_contains($raw, 'rateLimited') =>
                'ขอใบรับรองของโดเมนนี้บ่อยเกินโควตาของ Let\'s Encrypt — รอแล้วลองใหม่ '
                . 'หรือใช้โหมดทดสอบ (staging) ระหว่างที่ยังปรับแต่งอยู่',
            str_contains($raw, '404') && str_contains($raw, 'acme-challenge') =>
                'Let\'s Encrypt เข้าถึงไฟล์ตรวจสอบใน .well-known/acme-challenge ไม่ได้ — '
                . 'ตรวจว่า DocumentRoot ถูกต้องและไม่มี rewrite rule ดักไฟล์นั้นไว้',
            /*
             * ชื่อที่ไม่มี TLD สาธารณะ — `.test` `.local` `.internal` `.lan` หรือชื่อเปล่า ๆ
             *
             * **ไม่ใช่ความผิดพลาดที่แก้แล้วขอใหม่ได้** · Let's Encrypt ออกใบให้ชื่อที่พิสูจน์
             * ความเป็นเจ้าของผ่าน DNS สาธารณะไม่ได้เลยตามนิยาม ลองกี่ครั้งก็ได้ผลเดิม ·
             * เครื่องพัฒนาแทบทุกเครื่องใช้ชื่อแบบนี้ ข้อความจึงต้องชี้ไปทางที่ใช้ได้จริง
             * ไม่ใช่บอกว่าล้มเหลวเฉย ๆ แล้วปล่อยให้ไปนั่งไล่ DNS ที่ไม่มีวันถูก
             */
            str_contains($raw, 'valid public suffix') || str_contains($raw, 'Domain name does not end with') =>
                'ชื่อนี้ไม่มี TLD สาธารณะ (เช่นลงท้าย .test .local .internal) — Let\'s Encrypt '
                . 'ออกใบให้ไม่ได้เลยไม่ว่าลองกี่ครั้ง เพราะพิสูจน์ความเป็นเจ้าของผ่าน DNS '
                . 'สาธารณะไม่ได้ · ใช้วิธี "ใบที่เซ็นเอง" แทน ซึ่งใช้งานได้จริงทั้ง HTTPS และเมล '
                . 'เพียงแต่เบราว์เซอร์กับโปรแกรมเมลจะขึ้นคำเตือน',
            default => 'ขอใบรับรองไม่สำเร็จ',
        };

        return $hint . "\n\n" . mb_substr($raw, 0, 1200);
    }
}
