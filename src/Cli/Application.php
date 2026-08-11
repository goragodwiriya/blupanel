<?php

declare (strict_types = 1);

namespace Phpcp\Cli;

use Phpcp\Agent\AgentException;
use Phpcp\Agent\CapabilityRegistry;
use Phpcp\Agent\SelfProtection;
use Phpcp\Domain\Quota;
use Phpcp\Domain\QuotaChecker;
use Phpcp\Domain\ScheduledJobRepository;
use Phpcp\Domain\UserRepository;
use Phpcp\Driver\Updater;
use Phpcp\Kernel\App;
use Phpcp\Kernel\Config;
use Phpcp\Kernel\Db;
use Phpcp\Kernel\Mode;
use Phpcp\Security\Password;
use Phpcp\Security\Permissions;
use Phpcp\Security\Secret;
use Phpcp\Security\SessionStore;
use Phpcp\Support\Fmt;

/**
 * คำสั่ง phpcp — เครื่องมือกู้ระบบเมื่อหน้าเว็บเข้าไม่ได้ (ARCHITECTURE §13)
 *
 * ต้องมีเสมอ เพราะ panel จงใจไม่ให้จัดการบริการของตัวเองผ่าน UI
 * และเพราะรหัสผ่าน/2FA ที่หายต้องมีทางแก้ที่หน้าเครื่อง
 */
final class Application
{
    private readonly Console $out;

    public function __construct()
    {
        $this->out = new Console();
    }

    /** @param list<string> $argv */
    public function run(array $argv): int
    {
        $command = $argv[1] ?? 'help';
        $args = array_slice($argv, 2);

        try {
            return match ($command) {
                'help', '--help', '-h' => $this->help(),
                'version', '--version' => $this->version(),
                'status' => $this->status(),
                'doctor' => $this->doctor(),
                'setup' => $this->setup($args),
                'db:migrate' => $this->migrate(),
                'sites:rebuild' => $this->sitesRebuild(),
                'key:generate' => $this->keyGenerate(),
                'user:list' => $this->userList(),
                'user:create' => $this->userCreate($args),
                'user:passwd' => $this->userPasswd($args),
                'user:disable-2fa' => $this->userDisable2fa($args),
                'customer:list' => $this->customerList(),
                'customer:create' => $this->customerCreate($args),
                'customer:passwd' => $this->customerPasswd($args),
                'customer:quota' => $this->customerQuota($args),
                'customer:expiry' => $this->customerExpiry($args),
                'customer:status' => $this->customerStatus($args),
                'mode:show' => $this->modeShow(),
                'mode:set' => $this->modeSet($args),
                'sandbox:seed' => $this->sandboxSeed(),
                'sandbox:reset' => $this->sandboxReset(),
                'capabilities' => $this->capabilities(),
                'audit:verify' => $this->auditVerify(),
                'self-update' => $this->selfUpdate($args),
                'serve' => $this->serve($args),
                default => $this->unknown($command),
            };
        } catch (\Throwable $e) {
            $this->out->fail($e->getMessage());

            return 1;
        }
    }

    /**
     * @return int
     */
    private function help(): int
    {
        $this->out->title('phpcp — PHP Server Control Panel');
        $this->out->line();
        $this->out->line('  ใช้: phpcp <คำสั่ง> [ตัวเลือก]');
        $this->out->line();

        $groups = [
            'ทั่วไป' => [
                'status' => 'ตรวจสุขภาพระบบทุกส่วน',
                'doctor' => 'ตรวจการตั้งค่าและสิทธิ์ที่ผิดพลาด',
                'version' => 'แสดงเวอร์ชัน'
            ],
            'ติดตั้งและฐานข้อมูล' => [
                'setup' => 'ติดตั้งครั้งแรก — สร้างฐานข้อมูลและผู้ดูแลระบบคนแรก',
                'db:migrate' => 'อัปเดตโครงสร้างฐานข้อมูล',
                'key:generate' => 'สร้าง secret key ลงไฟล์ config',
                'sites:rebuild' => 'สร้างไฟล์ตั้งค่าของทุกเว็บไซต์ใหม่ (หลังเปลี่ยนค่า webserver)'
            ],
            'ผู้ใช้งาน (กู้ระบบเมื่อเข้าหน้าเว็บไม่ได้)' => [
                'user:list' => 'รายชื่อผู้ใช้ทั้งหมด',
                'user:create' => 'สร้างผู้ใช้ใหม่',
                'user:passwd' => 'ตั้งรหัสผ่านใหม่ให้ผู้ใช้',
                'user:disable-2fa' => 'ปิด 2FA กรณีทำอุปกรณ์ยืนยันตัวตนหาย'
            ],
            'ลูกค้า (สำหรับขาย web hosting)' => [
                'customer:list' => 'รายชื่อลูกค้าทั้งหมด',
                'customer:create' => 'สร้างลูกค้าใหม่พร้อมโควตา',
                'customer:passwd' => 'ตั้งรหัสผ่านใหม่ให้ลูกค้า',
                'customer:quota' => 'อัปเดตโควตาของลูกค้า',
                'customer:expiry' => 'อัปเดตวันหมดอายุของลูกค้า',
                'customer:status' => 'เปลี่ยนสถานะลูกค้า (active/suspended/expired)'
            ],
            'อัปเดต' => [
                'self-update' => 'ตรวจและติดตั้งรุ่นใหม่ (ตรวจลายเซ็นก่อนเสมอ)'
            ],
            'โหมดการทำงาน' => [
                'mode:show' => 'ดูโหมดปัจจุบัน',
                'mode:set' => 'เปลี่ยนโหมด (production | sandbox | dryrun)',
                'sandbox:seed' => 'ใส่ข้อมูลตัวอย่างสำหรับทดสอบ',
                'sandbox:reset' => 'ล้างสภาพแวดล้อมทดสอบกลับเป็นค่าเริ่มต้น'
            ],
            'อื่น ๆ' => [
                'capabilities' => 'รายการคำสั่งที่ agent ยอมทำทั้งหมด',
                'audit:verify' => 'ตรวจความต่อเนื่องของ audit log',
                'serve' => 'เปิดเว็บเซิร์ฟเวอร์สำหรับพัฒนา (ไม่ใช่สำหรับ production)'
            ]
        ];

        foreach ($groups as $group => $commands) {
            $this->out->line('  '.$group);
            foreach ($commands as $name => $description) {
                $this->out->item('  '.$name, $description);
            }
            $this->out->line();
        }

        return 0;
    }

    /**
     * @return int
     */
    private function version(): int
    {
        $this->out->line('phpcp '.PHPCP_VERSION.' (PHP '.PHP_VERSION.')');

        return 0;
    }

    /**
     * @return int
     */
    private function status(): int
    {
        $app = App::boot();
        $config = $app->config;

        $this->out->title('สถานะระบบ');
        $this->out->item('เวอร์ชัน', PHPCP_VERSION);
        $this->out->item('โหมด', $config->mode->label(), $config->mode->isProduction() ? '32' : '33');
        $this->out->item('layout', $config->paths->layout);
        $this->out->item('ไฟล์ config', $config->sourceFile ?? '(ใช้ค่าเริ่มต้น)');

        $this->out->title('ฐานข้อมูล');
        $dbFile = $config->paths->database();
        if (is_file($dbFile)) {
            $db = $app->db();
            $pending = $db->pendingMigrations($config->paths->migrations());
            $this->out->item('ไฟล์', $dbFile);
            $this->out->item('ขนาด', Fmt::bytes((int) filesize($dbFile)));
            $this->out->item('ผู้ใช้', (string) (new UserRepository($db))->count().' บัญชี');
            $this->out->item('migration ค้าง', $pending === 0 ? 'ไม่มี' : $pending.' รายการ', $pending === 0 ? '32' : '33');
        } else {
            $this->out->warn('ยังไม่มีฐานข้อมูล — รัน `phpcp setup` ก่อน');
        }

        if (is_file($dbFile)) {
            $this->out->title('งานตามเวลา');
            $jobs = new ScheduledJobRepository($app->db());
            $lastRunAt = $jobs->lastRunAt();

            $this->out->item(
                'รอบล่าสุด',
                $lastRunAt === null ? 'ยังไม่เคยทำงาน' : Fmt::ago($lastRunAt),
                $lastRunAt !== null && time() - $lastRunAt <= 300 ? '32' : '33',
            );

            foreach ($jobs->all() as $job) {
                $this->out->item(
                    (string) $job['name'],
                    sprintf(
                        '%-14s %-10s %s',
                        (string) $job['schedule'],
                        (int) $job['enabled'] === 1 ? ((string) ($job['last_status'] ?? '—')) : 'ปิดอยู่',
                        $job['last_run_at'] === null ? 'ยังไม่เคยรัน' : Fmt::ago((int) $job['last_run_at']),
                    ),
                );
            }
        }

        $this->out->title('Agent');
        $agent = $app->agent();
        $this->out->item('socket', $agent->socketPath());

        if ($agent->isAvailable()) {
            $this->out->ok('เชื่อมต่อได้');

            try {
                $data = $agent->data('system.metrics', [], $app->systemActor('cli.status'));
                $this->out->item('เครื่อง', (string) $data['hostname']);
                $this->out->item('uptime', Fmt::duration((int) $data['uptime']['seconds']));
                $this->out->item('CPU', Fmt::percent((float) $data['cpu']['percent']));
                $this->out->item('RAM', Fmt::percent((float) $data['memory']['percent']));
                $this->out->item('Disk', Fmt::percent((float) $data['disk']['percent']));
            } catch (\Throwable $e) {
                $this->out->warn('เรียก capability ไม่สำเร็จ: '.$e->getMessage());
            }
        } else {
            $this->out->fail('ติดต่อ agent ไม่ได้ — ตรวจว่า phpcp-agentd ทำงานอยู่หรือไม่');
        }

        $this->out->line();

        return 0;
    }

    private function doctor(): int
    {
        $app = App::boot();
        $config = $app->config;
        $problems = 0;

        $this->out->title('ตรวจสอบระบบ');

        // 1. PHP extension ที่จำเป็น
        $required = ['pdo_sqlite', 'sodium', 'posix', 'pcntl', 'sockets', 'openssl', 'mbstring', 'json', 'filter', 'fileinfo'];
        $missing = array_values(array_filter($required, static fn(string $e): bool => !extension_loaded($e)));

        if ($missing === []) {
            $this->out->ok('PHP extension ครบทุกตัวที่ต้องการ');
        } else {
            $this->out->fail('ขาด PHP extension: '.implode(', ', $missing));
            $problems++;
        }

        // 2. secret key
        if ($config->hasSecretKey()) {
            try {
                $config->secretKey();
                $this->out->ok('secret key ใช้งานได้');
            } catch (\Throwable $e) {
                $this->out->fail('secret key ไม่ถูกต้อง: '.$e->getMessage());
                $problems++;
            }
        } else {
            $this->out->fail('ยังไม่ได้ตั้ง secret key — รัน `phpcp key:generate`');
            $problems++;
        }

        // 3. สิทธิ์ไฟล์ config (มี secret จึงห้าม world-readable)
        //
        // เคสอ่านไม่ได้ต้องมาก่อน — sourceFile เป็น null ทั้งตอน "ไม่มีไฟล์" และตอน
        // "มีไฟล์แต่สิทธิ์ไม่พอ" ถ้าไม่แยกออกมา doctor จะเงียบสนิทในกรณีหลัง
        // ทั้งที่เป็นสาเหตุที่ทำให้ panel ทั้งตัวถอยไปทำงานในโหมด sandbox
        if ($config->sourceFile === null && Config::unreadableCandidates() !== []) {
            foreach (Config::unreadableCandidates() as $candidate) {
                $this->out->fail(sprintf(
                    'มีไฟล์ config ที่ %s แต่ผู้ใช้นี้อ่านไม่ได้ — chown root:phpcp แล้ว chmod 640',
                    $candidate,
                ));
                $problems++;
            }
        }

        if ($config->sourceFile !== null && is_file($config->sourceFile)) {
            $perms = fileperms($config->sourceFile) & 0o777;
            if (($perms & 0o004) !== 0) {
                $this->out->fail(sprintf('ไฟล์ config อ่านได้โดยทุกคน (%o) ควรเป็น 0640', $perms));
                $problems++;
            } else {
                $this->out->ok(sprintf('สิทธิ์ไฟล์ config เหมาะสม (%o)', $perms));
            }
        }

        // 4. ฐานข้อมูลและ migration
        if (!is_file($config->paths->database())) {
            $this->out->fail('ยังไม่มีฐานข้อมูล — รัน `phpcp setup`');
            $problems++;
        } else {
            $perms = fileperms($config->paths->database()) & 0o777;
            if (($perms & 0o044) !== 0) {
                $this->out->warn(sprintf('ไฟล์ฐานข้อมูลอ่านได้โดยผู้อื่น (%o) ควรเป็น 0600', $perms));
            }

            $pending = $app->db()->pendingMigrations($config->paths->migrations());
            if ($pending > 0) {
                $this->out->fail("มี migration ค้าง {$pending} รายการ — รัน `phpcp db:migrate`");
                $problems++;
            } else {
                $this->out->ok('โครงสร้างฐานข้อมูลเป็นปัจจุบัน');
            }

            // 5. ต้องมีผู้ดูแลระบบอย่างน้อยหนึ่งคน
            $users = new UserRepository($app->db());
            if ($users->countByRole(Permissions::SUPERADMIN) < 1) {
                $this->out->fail('ไม่มีผู้ดูแลระบบที่ใช้งานได้ — รัน `phpcp user:create`');
                $problems++;
            } else {
                $this->out->ok('มีผู้ดูแลระบบ '.$users->countByRole(Permissions::SUPERADMIN).' บัญชี');
            }

            // 6. ความต่อเนื่องของ audit log
            $chain = $app->audit()->verifyChain();
            if ($chain['ok']) {
                $this->out->ok("audit log ต่อเนื่องครบ {$chain['count']} รายการ");
            } else {
                $this->out->fail("audit log ถูกแก้ไข — chain ขาดที่ id {$chain['broken_at']}");
                $problems++;
            }

            // 7. ตัวจับเวลา — ตรวจแยกจากบริการอื่นเพราะมันล้มแบบเงียบสนิท
            //
            // ต่างจาก agent หรือเว็บที่ล่มแล้วเห็นทันที: scheduler ที่ไม่ทำงานทำให้หน้าจอ
            // ปกติทุกอย่าง แต่กลไกคืนค่าอัตโนมัติของ SSH/firewall หายไปเฉย ๆ
            // ผู้ดูแลจะรู้ตัวตอนที่เปลี่ยนพอร์ต SSH ผิดแล้วไม่มีอะไรคืนค่าให้เท่านั้น
            $problems += $this->checkScheduler($app);
        }

        // 8. ไฟล์ของ Now.js ที่ commit เข้ามาในโปรเจกต์ (การตัดสินใจ N8)
        $problems += $this->checkSpaBundle($config->paths->spa());

        // 9. agent
        if ($app->agent()->isAvailable()) {
            $this->out->ok('agent ตอบสนอง');
        } else {
            $this->out->fail('agent ไม่ตอบสนอง');
            $problems++;
        }

        // 10. โหมดกับสิทธิ์ต้องสอดคล้องกัน
        if ($config->mode->isProduction() && $config->paths->layout === 'portable') {
            $this->out->warn('โหมด production กับ layout portable ไม่ควรใช้คู่กันบนเซิร์ฟเวอร์จริง');
        }

        if (!$config->mode->isProduction()) {
            $this->out->warn('ระบบอยู่ในโหมด '.$config->mode->label().' — คำสั่งจะไม่มีผลกับเซิร์ฟเวอร์จริง');
        }

        $this->out->line();
        if ($problems === 0) {
            $this->out->ok('ไม่พบปัญหา');
        } else {
            $this->out->fail("พบปัญหา {$problems} รายการ");
        }
        $this->out->line();

        return $problems === 0 ? 0 : 1;
    }

    /**
     * @param array $args
     * @return int
     */
    private function setup(array $args): int
    {
        $app = App::boot();
        $config = $app->config;

        $this->out->title('ติดตั้ง PHP Server Control Panel');

        $config->paths->ensureDirectories();
        $this->out->ok('สร้างไดเรกทอรีที่จำเป็นแล้ว');

        if (!$config->hasSecretKey()) {
            $this->keyGenerate();
            $this->out->warn('สร้าง secret key แล้ว กรุณารัน `phpcp setup` อีกครั้ง');

            return 1;
        }

        $ran = $app->db()->migrate($config->paths->migrations());
        $this->out->ok($ran === [] ? 'โครงสร้างฐานข้อมูลเป็นปัจจุบันอยู่แล้ว' : 'รัน migration: '.implode(', ', $ran));

        $this->installScheduledJobs($app);

        $users = new UserRepository($app->db());
        if ($users->countByRole(Permissions::SUPERADMIN) > 0) {
            $this->out->info('มีผู้ดูแลระบบอยู่แล้ว ข้ามการสร้างบัญชีแรก');

            return 0;
        }

        $username = $this->argValue($args, '--user') ?: 'admin';
        $password = Password::random(20);

        $id = $users->create($username, $password, Permissions::SUPERADMIN, 'ผู้ดูแลระบบ', mustChangePassword: true);

        $app->audit()->write($app->systemActor('cli.setup'), 'system.setup', $username, 'ok', [
            'user_id' => $id,
            'mode' => $config->mode->value
        ]);

        $this->out->ok("สร้างบัญชีผู้ดูแลระบบ: {$username}");
        $this->out->box('รหัสผ่านชั่วคราว (แสดงครั้งเดียวเท่านั้น)', $password);
        $this->out->warn('ระบบจะบังคับให้เปลี่ยนรหัสผ่านนี้ตอนเข้าสู่ระบบครั้งแรก');
        $this->out->line();

        return 0;
    }

    /**
     * @return int
     */
    private function migrate(): int
    {
        $app = App::boot();
        $ran = $app->db()->migrate($app->config->paths->migrations());

        if ($ran === []) {
            $this->out->ok('ไม่มี migration ค้าง');
        } else {
            foreach ($ran as $version) {
                $this->out->ok('รัน migration: '.$version);
            }
        }

        $this->installScheduledJobs($app);

        return 0;
    }

    /**
     * ตรวจสุขภาพของ scheduler — คืนจำนวนปัญหาที่พบ
     *
     * เกณฑ์ "ค้างเกิน 5 นาที" มาจากคาบการทำงานจริง (ทุกนาที) บวกที่ว่างพอสำหรับงานที่ยาว
     * อย่าง disk.usage บนเครื่องที่มีเว็บเยอะ — ถ้าค้างนานกว่านี้แปลว่าไม่ได้ทำงานจริง
     */
    private function checkScheduler(App $app): int
    {
        $problems = 0;
        $jobs = new ScheduledJobRepository($app->db());

        $missing = array_values(array_filter(
            array_column(ScheduledJobRepository::DEFAULTS, 'name'),
            static fn (string $name): bool => $jobs->find($name) === null,
        ));

        if ($missing !== []) {
            $this->out->fail('ไม่มีงานตามเวลาที่ระบบต้องมี: '.implode(', ', $missing).' — รัน `phpcp db:migrate`');
            $problems++;
        }

        $lastRunAt = $jobs->lastRunAt();

        if ($lastRunAt === null) {
            $this->out->fail(
                'ตัวจับเวลายังไม่เคยทำงานเลย — ตรวจด้วย `systemctl status phpcp-scheduler.timer` '
                .'(ถ้าไม่ทำงาน กลไกคืนค่าอัตโนมัติของ SSH/firewall จะไม่ทำงานตามไปด้วย)'
            );
            $problems++;
        } elseif (time() - $lastRunAt > 300) {
            $this->out->fail(sprintf(
                'ตัวจับเวลาค้างมา %s แล้ว — สั่ง `systemctl restart phpcp-scheduler.timer`',
                Fmt::duration(time() - $lastRunAt),
            ));
            $problems++;
        } else {
            $this->out->ok('ตัวจับเวลาทำงานล่าสุดเมื่อ '.Fmt::ago($lastRunAt));
        }

        foreach ($jobs->failing() as $job) {
            $this->out->warn(sprintf(
                'งาน %s ล้มเหลวรอบล่าสุด: %s',
                (string) $job['name'],
                (string) ($job['last_error'] ?? 'ไม่ทราบสาเหตุ'),
            ));
        }

        return $problems;
    }

    /**
     * ตรวจว่าไฟล์ Now.js ที่เสิร์ฟอยู่ตรงกับที่ commit ไว้ — การตัดสินใจ N8
     *
     * Now.js เป็นโค้ดจากภายนอกที่โปรเจกต์นี้ไม่ได้เขียนเอง และมันรันในหน้าเว็บของ panel
     * ซึ่งเป็นหน้าที่คุม root ได้ · จุดนี้จึงเป็นเป้าหมายที่คุ้มค่าที่สุดสำหรับการแก้ไฟล์
     * แบบเงียบ ๆ บนเครื่อง ไฟล์เดียวที่ถูกสลับก็เพียงพอที่จะขโมยเซสชันผู้ดูแลได้
     *
     * SHA256SUMS สร้างตอน commit และตรวจที่นี่ ไม่ใช่ตอนโหลดหน้าเว็บ — SRI ของเบราว์เซอร์
     * ป้องกันได้แค่ตัวกลางระหว่างทาง แต่ไม่ช่วยเลยถ้าไฟล์บนดิสก์ถูกแก้ เพราะหน้า HTML
     * ที่ประกาศค่า hash ก็อยู่บนดิสก์เดียวกันและถูกแก้พร้อมกันได้
     */
    private function checkSpaBundle(string $spaDir): int
    {
        $sumsFile = $spaDir.'/vendor/now/SHA256SUMS';

        if (!is_file($sumsFile)) {
            $this->out->fail('ไม่พบ '.$sumsFile.' — ไฟล์ของ Now.js ไม่ครบ SPA จะเปิดไม่ได้');

            return 1;
        }

        $broken = [];
        $lines = file($sumsFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];

        foreach ($lines as $line) {
            // รูปแบบเดียวกับ sha256sum: "<hash>  <ชื่อไฟล์>"
            $parts = preg_split('/\s+/', trim($line), 2);

            if ($parts === false || count($parts) !== 2) {
                continue;
            }

            [$expected, $name] = $parts;
            $file = $spaDir.'/vendor/now/'.$name;

            if (!is_file($file)) {
                $broken[] = $name.' (ไม่มีไฟล์)';
                continue;
            }

            if (!hash_equals($expected, hash_file('sha256', $file) ?: '')) {
                $broken[] = $name.' (checksum ไม่ตรง)';
            }
        }

        if ($broken !== []) {
            $this->out->fail('ไฟล์ของ Now.js ไม่ตรงกับที่ commit ไว้: '.implode(', ', $broken));

            return 1;
        }

        $this->out->ok('ไฟล์ของ Now.js ครบและ checksum ตรงทั้ง '.count($lines).' ไฟล์');

        return 0;
    }

    /**
     * เติมงานตามเวลาที่ระบบต้องมี — เรียกทุกครั้งที่ migrate หรือ setup
     *
     * ตั้งใจให้อยู่ตรงนี้แทนที่จะเป็นไฟล์ migration เพราะเครื่องที่ติดตั้งไปแล้ว
     * ผ่าน migration เก่าไปหมดแล้ว งานที่เพิ่มเข้ามาทีหลัง (เช่น rollback.run ในเฟส A1)
     * จะไม่มีวันไปถึงเครื่องเหล่านั้นเลยถ้าเขียนไว้ใน migration ตัวใดตัวหนึ่ง
     */
    private function installScheduledJobs(App $app): void
    {
        $added = (new ScheduledJobRepository($app->db()))->installDefaults();

        if ($added !== []) {
            $this->out->ok('เพิ่มงานตามเวลา: '.implode(', ', $added));
        }
    }

    /**
     * ข้อความบอกสาเหตุที่ไม่มี config — แยก "ไม่มีไฟล์" ออกจาก "มีแต่อ่านไม่ได้"
     *
     * สองกรณีนี้แก้คนละวิธีสิ้นเชิง การบอกให้ "คัดลอก config.example.php" ทั้งที่
     * ไฟล์มีอยู่แล้วจะพาผู้ดูแลไปผิดทางและอาจทับ config เดิมทิ้ง
     *
     * @return string
     */
    private function missingConfigReason(): string
    {
        $unreadable = Config::unreadableCandidates();

        if ($unreadable !== []) {
            return sprintf(
                "อ่านไฟล์ config ไม่ได้ (มีไฟล์อยู่แต่สิทธิ์ไม่พอ): %s\n".
                '  แก้ด้วย: chown root:phpcp %s && chmod 640 %s',
                implode(', ', $unreadable),
                $unreadable[0],
                $unreadable[0],
            );
        }

        return 'ไม่พบไฟล์ config — คัดลอก etc/config.example.php เป็น etc/config.php ก่อน';
    }

    /**
     * สร้างไฟล์ vhost ของทุกเว็บไซต์ใหม่ตามค่า `webserver` ปัจจุบัน
     *
     * ต้องรันหลังเปลี่ยนค่า `webserver` ในไฟล์ตั้งค่า — ไฟล์เดิมเป็นรูปแบบของ
     * เซิร์ฟเวอร์ตัวเก่า การแก้ค่าเฉย ๆ ไม่ทำให้อะไรเกิดขึ้นเลย
     *
     * ส่งผ่าน agent เหมือนที่หน้าเว็บทำ — ชั้น CLI ไม่มีสิทธิ์เขียน /etc/apache2
     * หรือ /etc/nginx เอง และการตรวจค่ากับ audit log ต้องอยู่ที่เดียวกันทั้งระบบ
     *
     * @return int
     */
    private function sitesRebuild(): int
    {
        $app = App::boot();

        try {
            $result = $app->agent()->data('site.rebuild', [], $app->systemActor('cli.sites_rebuild'));
        } catch (AgentException $e) {
            $this->out->fail($e->getMessage());

            return 1;
        }

        foreach ((array) ($result['removed_stale'] ?? []) as $path) {
            $this->out->info('ลบไฟล์ของเซิร์ฟเวอร์ตัวเก่า: ' . $path);
        }

        $this->out->ok((string) $result['message']);

        // การเปลี่ยนพอร์ตที่ฟังทำตอน reload ไม่ได้ตามการออกแบบของ Apache เอง —
        // ถ้าไม่บอกตรงนี้ ผู้ดูแลจะเจอ nginx สตาร์ตไม่ขึ้นเพราะพอร์ตชนแล้วหาสาเหตุไม่เจอ
        if (($result['webserver'] ?? '') === 'nginx-proxy') {
            $this->out->warn('โหมดนี้ให้ Apache ถอยไปฟัง 127.0.0.1:8080 — ต้องรีสตาร์ตหนึ่งครั้ง ไม่ใช่แค่ reload');
            $this->out->line('  sudo systemctl restart apache2 && sudo systemctl start nginx');
        }

        return 0;
    }

    /**
     * @return int
     */
    private function keyGenerate(): int
    {
        $app = App::boot();
        $file = $app->config->sourceFile;

        if ($file === null) {
            $this->out->fail($this->missingConfigReason());

            return 1;
        }

        if ($app->config->hasSecretKey()) {
            $this->out->warn('มี secret key อยู่แล้ว การสร้างใหม่จะทำให้ TOTP secret เดิมถอดไม่ได้');
            if (!$this->out->confirm('ยืนยันสร้างใหม่หรือไม่')) {
                return 1;
            }
        }

        $contents = file_get_contents($file);
        if ($contents === false) {
            $this->out->fail("อ่านไฟล์ config ไม่ได้: {$file}");

            return 1;
        }

        $key = Secret::generateKey();
        $updated = preg_replace(
            "/('secret_key'\s*=>\s*)'[^']*'/",
            "$1'".$key."'",
            $contents,
            1,
            $count,
        );

        if ($updated === null || $count === 0) {
            $this->out->fail("ไม่พบบรรทัด 'secret_key' ในไฟล์ config — เพิ่มด้วยมือแล้วลองใหม่");

            return 1;
        }

        file_put_contents($file, $updated, LOCK_EX);
        @chmod($file, 0640);

        $this->out->ok("บันทึก secret key ลง {$file} แล้ว");

        return 0;
    }

    /**
     * @return int
     */
    private function userList(): int
    {
        $app = App::boot();
        $users = (new UserRepository($app->db()))->all();

        $this->out->title('ผู้ใช้งานระบบ ('.count($users).' บัญชี)');

        foreach ($users as $user) {
            $flags = [];
            if ((int) $user['totp_enabled'] === 1) {
                $flags[] = '2FA';
            }
            if ((int) $user['must_change_password'] === 1) {
                $flags[] = 'ต้องเปลี่ยนรหัสผ่าน';
            }
            if ($user['status'] !== 'active') {
                $flags[] = 'ถูกระงับ';
            }

            $this->out->item(
                (string) $user['username'],
                sprintf(
                    '%-22s เข้าสู่ระบบล่าสุด %s%s',
                    Permissions::roleLabel((string) $user['role']),
                    Fmt::ago($user['last_login_at'] === null ? null : (int) $user['last_login_at']),
                    $flags === [] ? '' : '  ['.implode(', ', $flags).']',
                ),
            );
        }

        $this->out->line();

        return 0;
    }

    /**
     * @return int
     */
    private function customerList(): int
    {
        $app = App::boot();
        $customers = new UserRepository($app->db());

        $accounts = $customers->hostingAccounts();

        $this->out->title('บัญชีโฮสติ้ง ('.count($accounts).' บัญชี)');

        $quotaChecker = new QuotaChecker($customers);

        foreach ($accounts as $customer) {
            $customerId = (int) $customer['id'];
            $summary = $quotaChecker->summary($customerId) ?? [];

            // สองแกน: status คุมสิทธิ์ล็อกอิน · service_status คุมบริการโฮสติ้ง
            $flags = [];
            if ($customer['status'] !== 'active') {
                $flags[] = 'ล็อกอิน: '.$customer['status'];
            }
            if ($customer['service_status'] !== 'active') {
                $flags[] = 'บริการ: '.$customer['service_status'];
            }
            if ($customer['expiry_at'] !== null && (int) $customer['expiry_at'] < time()) {
                $flags[] = 'หมดอายุ';
            }

            $expiryText = $customer['expiry_at'] !== null ? date('Y-m-d', (int) $customer['expiry_at']) : 'ไม่มี';

            $domainQuota = Quota::format($summary['domains']['limit'] ?? 0);
            $dbQuota = Quota::format($summary['databases']['limit'] ?? 0);

            $this->out->item(
                (string) $customer['username'],
                sprintf(
                    '%-15s โดเมน %d/%s ฐานข้อมูล %d/%s วันหมดอายุ %s%s',
                    Permissions::roleLabel('webadmin'),
                    $summary['domains']['used'] ?? 0,
                    $domainQuota,
                    $summary['databases']['used'] ?? 0,
                    $dbQuota,
                    $expiryText,
                    $flags === [] ? '' : '  ['.implode(', ', $flags).']',
                ),
            );
        }

        $this->out->line();

        return 0;
    }

    /**
     * @param array $args
     * @return int
     */
    private function customerCreate(array $args): int
    {
        $app = App::boot();

        $username = $this->argValue($args, '--user') ?: $this->out->ask('ชื่อผู้ใช้');
        $displayName = $this->argValue($args, '--display-name') ?: $username;
        $email = $this->argValue($args, '--email') ?: $this->out->ask('อีเมล');
        $rawDomains = $this->argValue($args, '--quota-domains');
        $rawSubdomains = $this->argValue($args, '--quota-subdomains');
        $rawAliases = $this->argValue($args, '--quota-aliases');
        $rawEmails = $this->argValue($args, '--quota-emails');
        $rawDatabases = $this->argValue($args, '--quota-databases');
        $rawFtp = $this->argValue($args, '--quota-ftp');
        $expiryAt = $this->argValue($args, '--expiry');
        $expiryTimestamp = $expiryAt !== '' ? strtotime($expiryAt) : null;

        if ($expiryAt !== '' && $expiryTimestamp === false) {
            $this->out->fail("อ่านวันหมดอายุไม่ออก: {$expiryAt} — ใช้รูปแบบ YYYY-MM-DD");

            return 1;
        }

        $password = Password::random(20);

        // ส่งผ่าน agent เหมือนที่หน้าเว็บทำ — การตรวจค่าและ audit log จึงอยู่ที่เดียวกัน
        // ไม่ใช่สองชุดที่ค่อย ๆ เพี้ยนจากกัน (PLAN-V2 A2)
        try {
            $result = $app->agent()->data('customer.create', [
                'username' => $username,
                'password' => $password,
                'display_name' => $displayName,
                'email' => $email,
                'quota_domains' => $rawDomains !== '' ? (int) $rawDomains : 10,
                'quota_subdomains' => $rawSubdomains !== '' ? (int) $rawSubdomains : 20,
                'quota_aliases' => $rawAliases !== '' ? (int) $rawAliases : 50,
                'quota_emails' => $rawEmails !== '' ? (int) $rawEmails : 100,
                'quota_databases' => $rawDatabases !== '' ? (int) $rawDatabases : 10,
                'quota_ftp_users' => $rawFtp !== '' ? (int) $rawFtp : 5,
                'expiry_at' => $expiryTimestamp === false ? null : $expiryTimestamp,
                'must_change_password' => true,
            ], $app->systemActor('cli.customer_create'));
        } catch (AgentException $e) {
            $this->out->fail($e->getMessage());

            return 1;
        }

        $this->out->ok((string) $result['message']);
        $this->out->box('รหัสผ่านชั่วคราว', $password);
        $this->out->warn('ระบบจะบังคับให้เปลี่ยนรหัสผ่านนี้ตอนเข้าสู่ระบบครั้งแรก');

        return 0;
    }

    /**
     * @param array $args
     * @return int
     */
    private function customerPasswd(array $args): int
    {
        $app = App::boot();
        $customers = new UserRepository($app->db());

        $username = $args[0] ?? $this->out->ask('ชื่อผู้ใช้');
        $customer = $customers->findByUsername($username);

        if ($customer === null) {
            $this->out->fail("ไม่พบลูกค้า: {$username}");

            return 1;
        }

        $password = $this->out->ask('รหัสผ่านใหม่ (เว้นว่างเพื่อให้ระบบสุ่มให้)', hidden: true);
        $generated = false;

        if ($password === '') {
            $password = Password::random(20);
            $generated = true;
        }

        $customers->setPassword((int) $customer['id'], $password, clearMustChange: false);

        $app->audit()->write($app->systemActor('cli.customer_passwd'), 'customer.password_changed', $username, 'ok', ['via' => 'cli']);

        $this->out->ok("ตั้งรหัสผ่านใหม่ให้ {$username} แล้ว");

        if ($generated) {
            $this->out->box('รหัสผ่านใหม่', $password);
        }

        return 0;
    }

    /**
     * @param array $args
     * @return int
     */
    private function customerQuota(array $args): int
    {
        $app = App::boot();
        $customers = new UserRepository($app->db());

        $username = $args[0] ?? $this->out->ask('ชื่อผู้ใช้');
        $customer = $customers->findByUsername($username);

        if ($customer === null) {
            $this->out->fail("ไม่พบลูกค้า: {$username}");

            return 1;
        }

        $quotaDomains = $this->argValue($args, '--domains');
        $quotaSubdomains = $this->argValue($args, '--subdomains');
        $quotaAliases = $this->argValue($args, '--aliases');
        $quotaEmails = $this->argValue($args, '--emails');
        $quotaDatabases = $this->argValue($args, '--databases');
        $quotaFtpUsers = $this->argValue($args, '--ftp');

        if ($quotaDomains === '' && $quotaSubdomains === '' && $quotaAliases === '' &&
            $quotaEmails === '' && $quotaDatabases === '' && $quotaFtpUsers === '') {
            $this->out->fail('ต้องระบุอย่างน้อย 1 โควตาที่ต้องการอัปเดต');

            return 1;
        }

        // ส่งเฉพาะโควตาที่ระบุมา — ค่าที่ไม่ได้ใส่ต้องไม่ถูกแตะ
        $quotas = array_filter([
            'quota_domains' => $quotaDomains,
            'quota_subdomains' => $quotaSubdomains,
            'quota_aliases' => $quotaAliases,
            'quota_emails' => $quotaEmails,
            'quota_databases' => $quotaDatabases,
            'quota_ftp_users' => $quotaFtpUsers,
        ], static fn (string $value): bool => $value !== '');

        try {
            $result = $app->agent()->data(
                'customer.quota_update',
                ['user_id' => (int) $customer['id']] + array_map(intval(...), $quotas),
                $app->systemActor('cli.customer_quota'),
            );

            $this->out->ok((string) $result['message']);
        } catch (AgentException $e) {
            $this->out->fail($e->getMessage());

            return 1;
        }

        return 0;
    }

    /**
     * @param array $args
     * @return int
     */
    private function customerExpiry(array $args): int
    {
        $app = App::boot();
        $customers = new UserRepository($app->db());

        $username = $args[0] ?? $this->out->ask('ชื่อผู้ใช้');
        $customer = $customers->findByUsername($username);

        if ($customer === null) {
            $this->out->fail("ไม่พบลูกค้า: {$username}");

            return 1;
        }

        $expiryAt = $this->argValue($args, '--expiry');

        if ($expiryAt === '') {
            $this->out->fail('ต้องระบุวันหมดอายุด้วย --expiry=YYYY-MM-DD หรือทิ้งว่างไว้เพื่อกำหนด');

            return 1;
        }

        $expiryTimestamp = $expiryAt !== '' ? strtotime($expiryAt) : null;

        try {
            $customers->updateExpiry((int) $customer['id'], $expiryTimestamp);

            $app->audit()->write($app->systemActor('cli.customer_expiry'), 'customer.expiry_update', $username, 'ok', [
                'expiry_at' => $expiryTimestamp
            ]);

            $this->out->ok($expiryTimestamp === null
                    ? "ยกเลิกวันหมดอายุของ {$customer['username']} แล้ว"
                    : "ตั้งวันหมดอายุของ {$customer['username']} เป็น ".date('Y-m-d', $expiryTimestamp).' แล้ว'
            );
        } catch (\InvalidArgumentException $e) {
            $this->out->fail($e->getMessage());

            return 1;
        }

        return 0;
    }

    /**
     * @param array $args
     * @return int
     */
    private function customerStatus(array $args): int
    {
        $app = App::boot();
        $customers = new UserRepository($app->db());

        $username = $args[0] ?? $this->out->ask('ชื่อผู้ใช้');
        $customer = $customers->findByUsername($username);

        if ($customer === null) {
            $this->out->fail("ไม่พบลูกค้า: {$username}");

            return 1;
        }

        $status = $this->argValue($args, '--status');
        if ($status === '') {
            $this->out->fail('ต้องระบุสถานะด้วย --status=active|suspended|expired');

            return 1;
        }

        if (!in_array($status, ['active', 'suspended', 'expired'], true)) {
            $this->out->fail('สถานะไม่ถูกต้อง ใช้ได้: active, suspended, expired');

            return 1;
        }

        $customers->setStatus((int) $customer['id'], $status);

        $app->audit()->write($app->systemActor('cli.customer_status'), 'customer.status_update', $username, 'ok', [
            'status' => $status
        ]);

        $this->out->ok("เปลี่ยนสถานะของ {$customer['username']} เป็น {$status} แล้ว");

        return 0;
    }

    /**
     * @param array $args
     * @return int
     */
    private function userCreate(array $args): int
    {
        $app = App::boot();
        $users = new UserRepository($app->db());

        $username = $this->argValue($args, '--user') ?: $this->out->ask('ชื่อผู้ใช้');
        $role = $this->argValue($args, '--role') ?: Permissions::SUPERADMIN;

        if (!Permissions::isValidRole($role)) {
            $this->out->fail('บทบาทไม่ถูกต้อง ใช้ได้: superadmin, sysadmin, webadmin');

            return 1;
        }

        if (preg_match('/^[a-zA-Z][a-zA-Z0-9_.-]{2,31}$/', $username) !== 1) {
            $this->out->fail('ชื่อผู้ใช้ต้องยาว 3-32 ตัว ขึ้นต้นด้วยตัวอักษร');

            return 1;
        }

        if ($users->findByUsername($username) !== null) {
            $this->out->fail('มีชื่อผู้ใช้นี้อยู่แล้ว');

            return 1;
        }

        $password = Password::random(20);
        $users->create($username, $password, $role, $username, mustChangePassword: true);

        $app->audit()->write($app->systemActor('cli.user_create'), 'user.create', $username, 'ok', ['role' => $role]);

        $this->out->ok("สร้างผู้ใช้ {$username} ({$role}) แล้ว");
        $this->out->box('รหัสผ่านชั่วคราว', $password);

        return 0;
    }

    /**
     * @param array $args
     * @return int
     */
    private function userPasswd(array $args): int
    {
        $app = App::boot();
        $users = new UserRepository($app->db());

        $username = $args[0] ?? $this->out->ask('ชื่อผู้ใช้');
        $user = $users->findByUsername($username);

        if ($user === null) {
            $this->out->fail("ไม่พบผู้ใช้: {$username}");

            return 1;
        }

        $password = $this->out->ask('รหัสผ่านใหม่ (เว้นว่างเพื่อให้ระบบสุ่มให้)', hidden: true);
        $generated = false;

        if ($password === '') {
            $password = Password::random(20);
            $generated = true;
        } else {
            $problems = Password::problems(
                $password,
                $app->config->int('security.password_min_length', 12),
                $username,
            );

            if ($problems !== []) {
                foreach ($problems as $problem) {
                    $this->out->fail($problem);
                }

                return 1;
            }
        }

        $users->setPassword((int) $user['id'], $password);

        // เปลี่ยนรหัสผ่านแล้วต้องตัด session เดิมทั้งหมด
        $removed = (new SessionStore($app->db(), $app->config))->destroyAllFor((int) $user['id']);

        $app->audit()->write($app->systemActor('cli.user_passwd'), 'auth.password_changed', $username, 'ok', [
            'via' => 'cli',
            'sessions_revoked' => $removed
        ]);

        $this->out->ok("ตั้งรหัสผ่านใหม่ให้ {$username} แล้ว (ตัด session เดิม {$removed} รายการ)");

        if ($generated) {
            $this->out->box('รหัสผ่านใหม่', $password);
        }

        return 0;
    }

    /**
     * @param array $args
     * @return int
     */
    private function userDisable2fa(array $args): int
    {
        $app = App::boot();
        $users = new UserRepository($app->db());

        $username = $args[0] ?? $this->out->ask('ชื่อผู้ใช้');
        $user = $users->findByUsername($username);

        if ($user === null) {
            $this->out->fail("ไม่พบผู้ใช้: {$username}");

            return 1;
        }

        $users->disableTotp((int) $user['id']);

        $app->audit()->write($app->systemActor('cli.disable_2fa'), 'user.disable_2fa', $username, 'ok', ['via' => 'cli']);

        $this->out->ok("ปิด 2FA ของ {$username} แล้ว");
        $this->out->warn('ควรให้ผู้ใช้ตั้ง 2FA ใหม่ทันทีที่เข้าสู่ระบบได้');

        return 0;
    }

    /**
     * @return int
     */
    private function modeShow(): int
    {
        $config = App::boot()->config;

        $this->out->title('โหมดการทำงาน');
        $this->out->item('ปัจจุบัน', $config->mode->label(), $config->mode->isProduction() ? '32' : '33');
        $this->out->item('ค่าที่ตั้ง', $config->mode->value);

        if (!$config->mode->isProduction()) {
            $this->out->item('prefix ทดสอบ', $config->sandboxPrefix());
            $this->out->line();
            $this->out->warn($config->mode->bannerText());
        }

        $this->out->line();

        return 0;
    }

    /**
     * @param array $args
     * @return int
     */
    private function modeSet(array $args): int
    {
        $app = App::boot();
        $target = $args[0] ?? '';
        $mode = Mode::tryFrom($target);

        if ($mode === null) {
            $this->out->fail('โหมดไม่ถูกต้อง ใช้ได้: production, sandbox, dryrun');

            return 1;
        }

        $file = $app->config->sourceFile;
        if ($file === null) {
            $this->out->fail($this->missingConfigReason());

            return 1;
        }

        // เปลี่ยนเข้าสู่ production ต้องยืนยันสองชั้น เพราะคำสั่งจะเริ่มมีผลกับเครื่องจริง
        if ($mode->isProduction()) {
            $this->out->warn('โหมด production จะทำให้ทุกคำสั่งมีผลกับเซิร์ฟเวอร์จริงทันที');
            if (!$this->out->confirm('ยืนยันเปลี่ยนเป็นโหมด production หรือไม่')) {
                return 1;
            }
            if ($this->out->ask('พิมพ์คำว่า production เพื่อยืนยัน') !== 'production') {
                $this->out->fail('ยกเลิก');

                return 1;
            }
        }

        $contents = (string) file_get_contents($file);
        $updated = preg_replace("/('mode'\s*=>\s*)'[^']*'/", "$1'".$mode->value."'", $contents, 1, $count);

        if ($updated === null || $count === 0) {
            $this->out->fail("ไม่พบบรรทัด 'mode' ในไฟล์ config");

            return 1;
        }

        file_put_contents($file, $updated, LOCK_EX);

        $app->audit()->write($app->systemActor('cli.mode_set'), 'system.mode_changed', $mode->value, 'ok', [
            'from' => $app->config->mode->value,
            'to' => $mode->value
        ]);

        $this->out->ok('เปลี่ยนเป็นโหมด '.$mode->label().' แล้ว');
        $this->out->warn('ต้องรีสตาร์ต phpcp-agentd เพื่อให้โหมดใหม่มีผล');

        return 0;
    }

    /**
     * @return int
     */
    private function sandboxSeed(): int
    {
        $app = App::boot();

        if ($app->config->mode->isProduction()) {
            $this->out->fail('ใส่ข้อมูลตัวอย่างในโหมด production ไม่ได้');

            return 1;
        }

        $count = (new Seeder($app))->run();

        $this->out->ok('ใส่ข้อมูลตัวอย่างแล้ว');
        foreach ($count as $label => $number) {
            $this->out->item($label, (string) $number.' รายการ');
        }
        $this->out->line();

        return 0;
    }

    /**
     * @return int
     */
    private function sandboxReset(): int
    {
        $app = App::boot();

        if ($app->config->mode->isProduction()) {
            $this->out->fail('ล้างข้อมูลในโหมด production ไม่ได้');

            return 1;
        }

        if (!$this->out->confirm('ล้างข้อมูลทดสอบทั้งหมด (ยกเว้นบัญชีผู้ใช้) หรือไม่')) {
            return 1;
        }

        $removed = (new Seeder($app))->reset();

        $prefix = $app->config->sandboxPrefix();
        $stateDir = $prefix.'/state';
        if (is_dir($stateDir)) {
            foreach (glob($stateDir.'/*.json') ?: [] as $file) {
                @unlink($file);
            }
        }

        $this->out->ok("ล้างข้อมูลทดสอบแล้ว ({$removed} แถว) และรีเซ็ตสถานะบริการจำลอง");

        return 0;
    }

    /**
     * @return int
     */
    private function capabilities(): int
    {
        $registry = new CapabilityRegistry();

        $this->out->title('Capability ที่ agent ยอมทำ ('.count($registry->names()).' รายการ)');

        foreach ($registry->describe() as $name => $info) {
            $this->out->item(
                $name,
                sprintf('%-18s %s  %s', $info['permission'], $info['mutating'] ? '[เปลี่ยนแปลง]' : '[อ่านอย่างเดียว]', $info['summary']),
            );
        }

        $this->out->title('ทรัพยากรที่ป้องกันตัวเองไว้');
        $this->out->item('systemd unit', implode(', ', SelfProtection::protectedUnits()));
        foreach (SelfProtection::protectedPaths() as $path) {
            $this->out->item('', $path);
        }
        $this->out->line();

        return 0;
    }

    /**
     * ตรวจและติดตั้งรุ่นใหม่
     *
     *   phpcp self-update --check                       ตรวจอย่างเดียว ไม่เปลี่ยนอะไร
     *   phpcp self-update --manifest=https://.../x.json  ระบุแหล่งเอง
     *
     * ตั้งใจให้เป็นคำสั่งบรรทัดคำสั่งอย่างเดียว ไม่มีปุ่มบนหน้าเว็บ —
     * การเปลี่ยนโค้ดของตัว panel เองขณะที่มีคนใช้งานอยู่ควรเป็นการตัดสินใจที่ตั้งใจ
     * และทำตอนที่ผู้ดูแลอยู่หน้าเครื่องพร้อมกู้ ไม่ใช่ปุ่มที่กดพลาดได้
     *
     * @param list<string> $args
     */
    private function selfUpdate(array $args): int
    {
        $updater = new Updater();

        $this->out->title('ตรวจรุ่นใหม่');
        $this->out->line();
        $this->out->line('  เวอร์ชันที่ติดตั้งอยู่ : '.PHPCP_VERSION);

        if (!$updater->isConfigured()) {
            $this->out->fail(
                'build นี้ไม่ได้ฝังกุญแจสาธารณะไว้ จึงตรวจลายเซ็นของแพ็กเกจไม่ได้',
            );
            $this->out->line();
            $this->out->line('  self-update ถูกปิดไว้โดยเจตนา ไม่ใช่ข้อผิดพลาด — ตัวอัปเดตที่ไม่ตรวจลายเซ็น');
            $this->out->line('  คือช่องทางยึดเครื่องด้วยสิทธิ์ root ที่ตรงที่สุดเท่าที่จะมีได้');
            $this->out->line('  ให้อัปเดตด้วยการดาวน์โหลดจากแหล่งที่เชื่อถือได้แล้วรัน ./install.sh ซ้ำแทน');
            $this->out->line();

            return 1;
        }

        $manifest = $this->option($args, '--manifest', '');

        if ($manifest === '') {
            $this->out->fail('ยังไม่ได้ตั้งที่อยู่ของไฟล์ manifest — ระบุด้วย --manifest=https://...');

            return 1;
        }

        $release = $updater->parseManifest($updater->fetch($manifest));

        $this->out->line('  รุ่นล่าสุดที่ประกาศ    : '.$release['version']);
        $this->out->line();

        try {
            $updater->assertUpgrade($release['version'], PHPCP_VERSION);
        } catch (\Throwable $e) {
            $this->out->ok($e->getMessage());

            return 0;
        }

        if (in_array('--check', $args, true)) {
            $this->out->warn('มีรุ่นใหม่ให้อัปเดต — สั่งซ้ำโดยไม่ใส่ --check เพื่อติดตั้ง');

            return 0;
        }

        $archive = $updater->fetch($release['url']);

        // ตรวจลายเซ็นก่อนแตะไฟล์ใด ๆ บนดิสก์ ไม่ใช่หลังแตกไฟล์
        $updater->verify($archive, $release['signature'], $release['version'], PHPCP_VERSION);
        $this->out->ok('ลายเซ็นถูกต้อง — แพ็กเกจมาจากผู้เผยแพร่ตัวจริงและไม่ถูกแก้ระหว่างทาง');

        $target = sys_get_temp_dir().'/phpcp-update-'.bin2hex(random_bytes(6)).'.tar.gz';
        file_put_contents($target, $archive);
        @chmod($target, 0600);

        $this->out->line();
        $this->out->ok('ดาวน์โหลดและตรวจสอบเรียบร้อย: '.$target);
        $this->out->line();
        $this->out->line('  ขั้นตอนต่อไปทำด้วยมือโดยเจตนา เพราะการแทนที่โค้ดที่กำลังรันอยู่');
        $this->out->line('  ควรทำตอนที่ผู้ดูแลพร้อมกู้ระบบ ไม่ใช่ระหว่างที่สคริปต์ทำงานค้างอยู่:');
        $this->out->line();
        $this->out->line('    tar -xzf '.$target.' -C /tmp/phpcp-new');
        $this->out->line('    sudo /tmp/phpcp-new/install.sh');
        $this->out->line();

        return 0;
    }

    /** @param list<string> $args */
    private function option(array $args, string $name, string $default): string
    {
        foreach ($args as $arg) {
            if (str_starts_with($arg, $name.'=')) {
                return substr($arg, strlen($name) + 1);
            }
        }

        return $default;
    }

    /**
     * @return int
     */
    private function auditVerify(): int
    {
        $result = App::boot()->audit()->verifyChain();

        if ($result['ok']) {
            $this->out->ok("audit log ต่อเนื่องครบ {$result['count']} รายการ ไม่พบร่องรอยการแก้ไข");

            return 0;
        }

        $this->out->fail("audit log ถูกแก้ไข — chain ขาดที่ id {$result['broken_at']}");

        return 1;
    }

    /**
     * @param array $args
     * @return int
     */
    private function serve(array $args): int
    {
        $app = App::boot();

        if ($app->config->mode->isProduction()) {
            $this->out->fail('คำสั่ง serve ใช้สำหรับพัฒนาเท่านั้น ห้ามใช้ในโหมด production');
            $this->out->info('บนเซิร์ฟเวอร์จริงให้ใช้ phpcp-web.service ตาม ARCHITECTURE §5.2');

            return 1;
        }

        $host = $this->argValue($args, '--host') ?: '127.0.0.1';
        $port = (int) ($this->argValue($args, '--port') ?: '8080');
        $workers = max(2, (int) ($this->argValue($args, '--workers') ?: '6'));

        $docroot = PHPCP_ROOT.'/public';

        $this->out->title('เว็บเซิร์ฟเวอร์สำหรับพัฒนา');
        $this->out->item('ที่อยู่', "http://{$host}:{$port}");
        $this->out->item('document root', $docroot);
        $this->out->item('โหมด', $app->config->mode->label());
        $this->out->item('worker', (string) $workers);
        $this->out->line();
        $this->out->warn('ใช้สำหรับทดสอบเท่านั้น กด Ctrl+C เพื่อหยุด');
        $this->out->line();

        $descriptors = [0 => STDIN, 1 => STDOUT, 2 => STDERR];

        // php -S มี worker เดียวเป็นค่าเริ่มต้น ซึ่งใช้กับ SSE ไม่ได้เลย:
        // การเชื่อมต่อ /api/stream/metrics เส้นเดียวจะยึด worker ไว้จนหน้าอื่นโหลดไม่ได้ทั้งเว็บ
        // PHP_CLI_SERVER_WORKERS สั่งให้ fork หลายตัว (ใช้ได้บน Linux เท่านั้น)
        // บนเซิร์ฟเวอร์จริงใช้ phpcp-fpm ที่ตั้ง pm.static ไว้แล้วจึงไม่มีปัญหานี้
        $env = ['PHP_CLI_SERVER_WORKERS' => (string) $workers] + getenv();

        $process = proc_open(
            [PHP_BINARY, '-S', "{$host}:{$port}", '-t', $docroot],
            $descriptors,
            $pipes,
            null,
            $env,
        );

        return is_resource($process) ? proc_close($process) : 1;
    }

    /**
     * @param string $command
     */
    private function unknown(string $command): int
    {
        $this->out->fail("ไม่รู้จักคำสั่ง: {$command}");
        $this->out->info('ดูคำสั่งทั้งหมดด้วย `phpcp help`');

        return 1;
    }

    /** อ่านค่าตัวเลือกแบบ --key=value หรือ --key value */
    private function argValue(array $args, string $key): string
    {
        foreach ($args as $index => $arg) {
            if (str_starts_with($arg, $key.'=')) {
                return substr($arg, strlen($key) + 1);
            }
            if ($arg === $key) {
                return $args[$index + 1] ?? '';
            }
        }

        return '';
    }
}
