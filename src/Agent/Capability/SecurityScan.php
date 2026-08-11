<?php

declare(strict_types=1);

namespace Phpcp\Agent\Capability;

use Phpcp\Agent\Capability;
use Phpcp\Agent\Context;
use Phpcp\Agent\Executor\Executor;
use Phpcp\Domain\SecurityScore;
use Phpcp\Domain\ServiceCatalog;
use Phpcp\Domain\SiteRepository;
use Phpcp\Driver\Firewall\UfwDriver;
use Phpcp\Driver\Ssl\CertbotManager;
use Phpcp\Driver\SshManager;

/**
 * ตรวจความปลอดภัยทั้งเครื่อง — PROMPT.md หัวข้อ Security
 *
 * รวบรวมหลักฐานจริงจากระบบ ไม่ใช่จากค่าที่ panel จำไว้ในฐานข้อมูล
 * เพราะสิ่งที่ต้องตอบคือ "ตอนนี้เครื่องปลอดภัยแค่ไหน" ไม่ใช่ "panel คิดว่าปลอดภัยแค่ไหน"
 * ค่าที่ panel เคยตั้งไว้อาจถูกแก้จากบรรทัดคำสั่งไปแล้วโดยไม่ผ่านหน้าเว็บ
 *
 * ทุกข้อที่ไม่ผ่านต้องบอกทางแก้ที่ทำตามได้จริง ตามที่ PROMPT.md กำหนดว่า
 * "Show actionable recommendations" — คำเตือนที่บอกแต่ปัญหาโดยไม่บอกว่าแก้ที่ไหน
 * สุดท้ายจะถูกเมิน ข้อที่มีหน้าจอรับผิดชอบจะมี `fix_url` ชี้ไปหน้านั้น
 * ส่วนข้อที่แก้ได้ที่เครื่องเท่านั้น (เช่นสิทธิ์ไฟล์) จะบอกคำสั่งไว้ใน `advice` แทน
 */
final class SecurityScan implements Capability
{
    public static function name(): string
    {
        return 'security.scan';
    }

    public function permission(): string
    {
        return 'security.view';
    }

    public function isMutating(): bool
    {
        return false;
    }

    public function summary(): string
    {
        return 'ตรวจสถานะความปลอดภัยของเซิร์ฟเวอร์';
    }

    public function validate(array $args): array
    {
        return [];
    }

    public function run(array $args, Executor $executor, Context $context): array
    {
        $checks = [
            ...$this->firewallChecks($executor, $context),
            ...$this->sshChecks($executor),
            ...$this->sslChecks($executor, $context),
            ...$this->accountChecks($context),
            ...$this->phpChecks($context),
            ...$this->fileChecks($executor, $context),
        ];

        $score = SecurityScore::calculate($checks);

        return [
            'score' => $score,
            'grade' => SecurityScore::grade($score),
            'checks' => $checks,
            'recommendations' => SecurityScore::recommendations($checks),
            'failed_logins' => $this->failedLogins($context),
            'scanned_at' => time(),
        ];
    }

    /** @return list<array<string,mixed>> */
    private function firewallChecks(Executor $executor, Context $context): array
    {
        $driver = new UfwDriver();

        if (!$driver->isInstalled($executor)) {
            return [$this->check(
                'firewall.installed',
                'Firewall',
                SecurityScore::FAIL,
                20,
                'ไม่ได้ติดตั้ง ufw — เครื่องรับการเชื่อมต่อทุกพอร์ตที่มีบริการเปิดฟังอยู่',
                'ติดตั้งด้วย apt install ufw แล้วตั้งกฎก่อนเปิดใช้งาน',
                '/server/firewall',
            )];
        }

        $status = $driver->status($executor);

        if (!$status['readable']) {
            return [$this->check(
                'firewall.active',
                'Firewall',
                SecurityScore::UNKNOWN,
                20,
                'ตรวจสถานะจริงของ firewall ไม่ได้',
                $status['note'],
                '/server/firewall',
            )];
        }

        if (!$status['active']) {
            return [$this->check(
                'firewall.active',
                'Firewall',
                SecurityScore::FAIL,
                20,
                sprintf('firewall ปิดอยู่ (มีกฎที่ตั้งไว้ %d ข้อแต่ยังไม่มีผล)', count($status['rules'])),
                'เปิดใช้งาน firewall — ระบบจะเปิดพอร์ตของหน้าเว็บนี้และ SSH ไว้ให้ก่อนเสมอ',
                '/server/firewall',
            )];
        }

        $checks = [$this->check(
            'firewall.active',
            'Firewall',
            SecurityScore::PASS,
            20,
            sprintf('เปิดใช้งานอยู่ บังคับใช้ %d กฎ', count($status['rules'])),
        )];

        // เปิดพอร์ตกว้าง ๆ สู่อินเทอร์เน็ตคือความเสี่ยงที่พบบ่อยที่สุดรองจากไม่เปิด firewall เลย
        $panelPort = (string) $context->config->int('panel.port', 8443);
        $exposed = [];

        foreach ($status['rules'] as $rule) {
            if ($rule['action'] !== 'ALLOW' || $rule['source_spec'] !== '') {
                continue;
            }

            // พอร์ตที่ไม่ควรเปิดให้ทุกที่เข้าถึง — ฐานข้อมูลและหน้าจัดการ
            if (in_array($rule['port'], ['3306', '5432', '6379', '27017', '11211', $panelPort], true)) {
                $exposed[] = $rule['target'];
            }
        }

        if ($exposed !== []) {
            $checks[] = $this->check(
                'firewall.exposed',
                'พอร์ตที่เปิดกว้างเกินจำเป็น',
                SecurityScore::WARN,
                10,
                'เปิดให้เข้าถึงจากทุกที่: ' . implode(', ', $exposed),
                'จำกัดต้นทางของกฎเหล่านี้ให้เหลือเฉพาะ IP ที่ต้องใช้จริง '
                . 'พอร์ตฐานข้อมูลและหน้าจัดการไม่ควรเปิดสู่อินเทอร์เน็ตทั้งหมด',
                '/server/firewall',
            );
        } else {
            $checks[] = $this->check(
                'firewall.exposed',
                'พอร์ตที่เปิดกว้างเกินจำเป็น',
                SecurityScore::PASS,
                10,
                'ไม่พบพอร์ตฐานข้อมูลหรือหน้าจัดการที่เปิดให้ทุกที่เข้าถึง',
            );
        }

        return $checks;
    }

    /** @return list<array<string,mixed>> */
    private function sshChecks(Executor $executor): array
    {
        $manager = new SshManager();

        if (!$manager->isInstalled($executor)) {
            // ไม่มี SSH ไม่ใช่ปัญหาด้านความปลอดภัย — ยิ่งลดทางเข้าด้วยซ้ำ
            return [$this->check('ssh.config', 'SSH', SecurityScore::PASS, 15, 'ไม่ได้ติดตั้ง SSH บนเครื่องนี้')];
        }

        $values = $manager->read($executor);
        $problems = [];
        $status = SecurityScore::PASS;

        if ($values['PermitEmptyPasswords']['value'] === 'yes') {
            $problems[] = 'อนุญาตรหัสผ่านว่าง';
            $status = SecurityScore::FAIL;
        }

        if ($values['PermitRootLogin']['value'] === 'yes') {
            $problems[] = 'อนุญาตให้ root เข้าสู่ระบบด้วยรหัสผ่าน';
            $status = SecurityScore::FAIL;
        }

        if ($values['PasswordAuthentication']['value'] === 'yes' && $status !== SecurityScore::FAIL) {
            // เปิดรหัสผ่านอย่างเดียวยังไม่ถึงขั้นอันตราย ถ้ารหัสผ่านแข็งแรงพอ
            // แต่เป็นเป้าของการเดารหัสอัตโนมัติตลอดเวลา จึงนับเป็นควรปรับปรุง
            $problems[] = 'เปิดให้เข้าสู่ระบบด้วยรหัสผ่าน';
            $status = SecurityScore::WARN;
        }

        if ($values['PubkeyAuthentication']['value'] === 'no') {
            $problems[] = 'ปิดการเข้าสู่ระบบด้วยกุญแจ';
            $status = SecurityScore::FAIL;
        }

        return [$this->check(
            'ssh.config',
            'SSH',
            $status,
            15,
            $problems === []
                ? sprintf('ตั้งค่าเหมาะสม (พอร์ต %s)', $values['Port']['value'])
                : implode(' · ', $problems),
            $problems === [] ? '' : 'ปิด PermitRootLogin และ PermitEmptyPasswords แล้วใช้กุญแจแทนรหัสผ่าน',
            $problems === [] ? '' : '/server/ssh',
        )];
    }

    /** @return list<array<string,mixed>> */
    private function sslChecks(Executor $executor, Context $context): array
    {
        $repository = new SiteRepository($context->db);
        $certbot = new CertbotManager();

        $sites = [];
        foreach ($context->db->all('SELECT id FROM sites') as $row) {
            $site = $repository->load((int) $row['id']);

            if ($site !== null) {
                $sites[] = $site;
            }
        }

        if ($sites === []) {
            return [$this->check('ssl.coverage', 'SSL', SecurityScore::PASS, 15, 'ยังไม่มีเว็บไซต์บนเครื่องนี้')];
        }

        $noCert = [];
        $expiring = [];
        $notForced = [];

        foreach ($sites as $site) {
            $certificate = $certbot->inspect($executor, $site);

            if ($certificate['status'] === 'none') {
                $noCert[] = $site->domain;
                continue;
            }

            if (in_array($certificate['status'], ['expiring', 'expired', 'invalid'], true)) {
                $expiring[] = $site->domain;
            }

            if ($site->sslMode !== 'forced') {
                $notForced[] = $site->domain;
            }
        }

        $checks = [];

        $checks[] = match (true) {
            $expiring !== [] => $this->check(
                'ssl.coverage',
                'ใบรับรอง SSL',
                SecurityScore::FAIL,
                15,
                'ใบรับรองหมดอายุแล้วหรือใกล้หมด: ' . implode(', ', $expiring),
                'ต่ออายุใบรับรองก่อนที่ผู้ใช้จะเข้าเว็บไม่ได้',
                '/ssl',
            ),
            $noCert !== [] => $this->check(
                'ssl.coverage',
                'ใบรับรอง SSL',
                SecurityScore::FAIL,
                15,
                'เว็บไซต์ที่ยังไม่มีใบรับรอง: ' . implode(', ', $noCert),
                'ติดตั้งใบรับรองให้ครบทุกเว็บไซต์ — ข้อมูลที่ส่งผ่าน HTTP ถูกดักอ่านได้ทั้งหมด',
                '/ssl',
            ),
            default => $this->check(
                'ssl.coverage',
                'ใบรับรอง SSL',
                SecurityScore::PASS,
                15,
                sprintf('เว็บไซต์ทั้ง %d เว็บมีใบรับรองที่ใช้งานได้', count($sites)),
            ),
        };

        if ($notForced !== []) {
            $checks[] = $this->check(
                'ssl.forced',
                'บังคับ HTTPS',
                SecurityScore::WARN,
                8,
                'ยังเข้าผ่าน HTTP ได้: ' . implode(', ', $notForced),
                'เปิด "บังคับ HTTPS" เพื่อ redirect คำขอทาง HTTP ทั้งหมดไป HTTPS',
                '/ssl',
            );
        } else {
            $checks[] = $this->check('ssl.forced', 'บังคับ HTTPS', SecurityScore::PASS, 8, 'ทุกเว็บไซต์บังคับ HTTPS แล้ว');
        }

        $checks[] = $certbot->autoRenewActive($executor)
            ? $this->check('ssl.autorenew', 'ต่ออายุอัตโนมัติ', SecurityScore::PASS, 7, 'certbot.timer ทำงานอยู่')
            : $this->check(
                'ssl.autorenew',
                'ต่ออายุอัตโนมัติ',
                SecurityScore::WARN,
                7,
                'certbot.timer ยังไม่ถูกเปิดใช้งาน',
                'เปิดด้วย systemctl enable --now certbot.timer — ไม่อย่างนั้นใบรับรองจะหมดอายุใน 90 วัน',
                '/ssl',
            );

        return $checks;
    }

    /** @return list<array<string,mixed>> */
    private function accountChecks(Context $context): array
    {
        $total = (int) $context->db->value('SELECT count(*) FROM users WHERE status = :s', ['s' => 'active'], 0);
        $with2fa = (int) $context->db->value(
            "SELECT count(*) FROM users WHERE status = :s AND totp_secret IS NOT NULL AND totp_secret != ''",
            ['s' => 'active'],
            0,
        );

        if ($total === 0) {
            return [];
        }

        $checks = [$with2fa === $total
            ? $this->check('account.2fa', 'การยืนยันสองขั้นตอน', SecurityScore::PASS, 12, sprintf('เปิดใช้งานครบทั้ง %d บัญชี', $total))
            : $this->check(
                'account.2fa',
                'การยืนยันสองขั้นตอน',
                $with2fa === 0 ? SecurityScore::FAIL : SecurityScore::WARN,
                12,
                sprintf('เปิดใช้งานแล้ว %d จาก %d บัญชี', $with2fa, $total),
                'เปิด 2FA ให้ทุกบัญชีที่เข้าถึงหน้าจัดการได้ — รหัสผ่านอย่างเดียวกันการสวมสิทธิ์ไม่ได้',
                '/account/password',
            )];

        // บัญชีที่ล็อกอินไม่สำเร็จซ้ำ ๆ คือสัญญาณว่ามีคนกำลังเดารหัสอยู่
        $failed = $this->failedLogins($context);

        $checks[] = match (true) {
            $failed['last_24h'] >= 50 => $this->check(
                'account.bruteforce',
                'ความพยายามเข้าสู่ระบบที่ล้มเหลว',
                SecurityScore::FAIL,
                8,
                sprintf('%d ครั้งใน 24 ชั่วโมง จาก %d ที่อยู่', $failed['last_24h'], $failed['sources']),
                'ถ้าเป็นการเดารหัสจากภายนอก ให้จำกัดต้นทางของกฎที่เปิดพอร์ตหน้าเว็บนี้ '
                . 'ให้เหลือเฉพาะ IP ที่ใช้งานจริง',
                '/server/firewall',
            ),
            $failed['last_24h'] >= 10 => $this->check(
                'account.bruteforce',
                'ความพยายามเข้าสู่ระบบที่ล้มเหลว',
                SecurityScore::WARN,
                8,
                sprintf('%d ครั้งใน 24 ชั่วโมง', $failed['last_24h']),
                'ถ้าไม่ใช่การพิมพ์ผิดของผู้ใช้เอง แปลว่ามีคนกำลังเดารหัสอยู่ — '
                . 'เปิด 2FA ให้ครบทุกบัญชีและจำกัดต้นทางที่เข้าหน้าเว็บนี้ได้',
                '/server/users',
            ),
            default => $this->check(
                'account.bruteforce',
                'ความพยายามเข้าสู่ระบบที่ล้มเหลว',
                SecurityScore::PASS,
                8,
                sprintf('%d ครั้งใน 24 ชั่วโมง — อยู่ในระดับปกติ', $failed['last_24h']),
            ),
        };

        return $checks;
    }

    /** @return list<array<string,mixed>> */
    private function phpChecks(Context $context): array
    {
        $rows = $context->db->all('SELECT php_version, count(*) n FROM sites GROUP BY php_version');
        $outdated = [];

        foreach ($rows as $row) {
            if (in_array((string) $row['php_version'], ServiceCatalog::PHP_EOL_VERSIONS, true)) {
                $outdated[] = sprintf('PHP %s (%d เว็บ)', $row['php_version'], $row['n']);
            }
        }

        if ($rows === []) {
            return [];
        }

        return [$outdated === []
            ? $this->check('php.version', 'เวอร์ชัน PHP', SecurityScore::PASS, 10, 'ทุกเว็บไซต์ใช้เวอร์ชันที่ยังได้รับการอัปเดตความปลอดภัย')
            : $this->check(
                'php.version',
                'เวอร์ชัน PHP',
                SecurityScore::FAIL,
                10,
                'ใช้เวอร์ชันที่หมดระยะสนับสนุนแล้ว: ' . implode(', ', $outdated),
                'ย้ายไปเวอร์ชันที่ยังได้รับการอัปเดต — เวอร์ชันที่หมดอายุจะไม่มีแพตช์ช่องโหว่อีกแล้ว',
                '/php',
            )];
    }

    /**
     * สิทธิ์ไฟล์ที่เปิดกว้างเกินไป
     *
     * ตรวจเฉพาะไฟล์ที่ panel เป็นเจ้าของและรู้ว่าควรเป็นเท่าไร ไม่ไล่ scan ทั้งเครื่อง —
     * การ scan ทั้งเครื่องช้ามากและให้ผลบวกลวงเยอะจนไม่มีใครอ่าน
     *
     * @return list<array<string,mixed>>
     */
    private function fileChecks(Executor $executor, Context $context): array
    {
        $bad = [];

        foreach ([$context->config->paths->configFile(), $context->config->paths->database()] as $path) {
            $resolved = $executor->path($path);

            if (!$executor->exists($resolved)) {
                continue;
            }

            $stat = $executor->stat($resolved);
            $mode = ((int) ($stat['mode'] ?? 0)) & 0777;

            // ตรวจ "คุณสมบัติ" ไม่ใช่เลขโหมดตายตัว
            //
            // ตัวติดตั้งตั้ง config.php เป็น root:phpcp 0640 โดยเจตนา — ผู้ใช้ของ web tier
            // ต้องอ่านไฟล์นี้ได้แต่ต้องแก้ไม่ได้และไม่ใช่เจ้าของ ซึ่งแข็งแรงกว่า 0600
            // ที่ผู้ใช้ของเว็บเป็นเจ้าของเสียอีก ถ้าเทียบกับ 0600 ตรง ๆ การตั้งค่าที่ถูกต้อง
            // ของตัวติดตั้งเองจะถูกรายงานว่าผิด แล้วคะแนนทั้งหน้าจะหมดความน่าเชื่อถือ
            //
            // สิ่งที่อันตรายจริงมีสองอย่าง: ผู้ใช้อื่นบนเครื่องแตะได้ และกลุ่มเขียนได้
            $problems = [];

            if (($mode & 0007) !== 0) {
                $problems[] = 'ผู้ใช้อื่นบนเครื่องเข้าถึงได้';
            }

            if (($mode & 0020) !== 0) {
                $problems[] = 'สมาชิกกลุ่มแก้ไขได้';
            }

            if ($problems !== []) {
                $bad[] = sprintf('%s (%04o — %s)', basename($path), $mode, implode(', ', $problems));
            }
        }

        if ($bad === []) {
            return [$this->check('file.permissions', 'สิทธิ์ไฟล์สำคัญ', SecurityScore::PASS, 10, 'ไฟล์ตั้งค่าและฐานข้อมูลของ panel ตั้งสิทธิ์ถูกต้อง')];
        }

        return [$this->check(
            'file.permissions',
            'สิทธิ์ไฟล์สำคัญ',
            SecurityScore::FAIL,
            10,
            'เปิดกว้างเกินไป: ' . implode(', ', $bad),
            'ไฟล์เหล่านี้เก็บรหัสผ่านที่ผ่านการเข้ารหัสและ session ของผู้ดูแล '
            . 'ผู้ใช้อื่นบนเครื่องต้องแตะไม่ได้เลย — แก้ที่เครื่องด้วย chmod o=,g-w กับไฟล์ที่ระบุ',
        )];
    }

    /** @return array{last_24h:int,last_7d:int,sources:int} */
    private function failedLogins(Context $context): array
    {
        $count = static fn (int $since): int => (int) $context->db->value(
            "SELECT count(*) FROM audit_log WHERE action = 'auth.login' AND result != 'ok' AND ts >= :t",
            ['t' => $since],
            0,
        );

        return [
            'last_24h' => $count(time() - 86400),
            'last_7d' => $count(time() - 604800),
            'sources' => (int) $context->db->value(
                "SELECT count(DISTINCT actor_ip) FROM audit_log
                 WHERE action = 'auth.login' AND result != 'ok' AND ts >= :t",
                ['t' => time() - 86400],
                0,
            ),
        ];
    }

    /** @return array<string,mixed> */
    private function check(
        string $id,
        string $title,
        string $status,
        int $weight,
        string $detail,
        string $advice = '',
        string $fixUrl = '',
    ): array {
        return [
            'id' => $id,
            'title' => $title,
            'status' => $status,
            'weight' => $weight,
            'detail' => $detail,
            'advice' => $advice,
            'fix_url' => $fixUrl,
        ];
    }
}
