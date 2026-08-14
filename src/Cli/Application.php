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
                'panel:cert' => $this->panelCert($args),
                'panel:cert-sync' => $this->panelCertSync(),
                'mail:enable' => $this->mailDomain($args, true),
                'mail:disable' => $this->mailDomain($args, false),
                'mail:box-add' => $this->mailBoxAdd($args),
                'mail:box-del' => $this->mailBoxDelete($args),
                'mail:list' => $this->mailList(),
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
        $this->out->line('  Usage: phpcp <command> [options]');
        $this->out->line();

        $groups = [
            'General' => [
                'status' => 'Check the health of every part of the system',
                'doctor' => 'Check for wrong settings and permissions',
                'version' => 'Show the version'
            ],
            'Install and database' => [
                'setup' => 'First-time install - creates the database and the first administrator',
                'db:migrate' => 'Update the database schema',
                'key:generate' => 'Generate a secret key into the config file',
                'sites:rebuild' => 'Regenerate the config files of every site (after changing webserver settings)'
            ],
            'Mail (real mailboxes on this machine)' => [
                'panel:cert' => 'Certificate for the panel - `phpcp panel:cert panel.example.com` or `--self-signed`',
                'panel:cert-sync' => 'Copy a freshly renewed certificate to the panel (certbot calls this itself)',
                'mail:enable' => 'Enable mail for a domain - `phpcp mail:enable example.com`',
                'mail:disable' => 'Disable mail for a domain (mailboxes stay, but no mail is accepted)',
                'mail:box-add' => 'Create a mailbox - `phpcp mail:box-add me@example.com [--quota=1024] [--password=...]`',
                'mail:box-del' => 'Delete a mailbox and all of its mail',
                'mail:list' => 'List the domains with mail enabled and all mailboxes'
            ],
            'Users (recovery when the web UI cannot be reached)' => [
                'user:list' => 'List all users',
                'user:create' => 'Create a new user',
                'user:passwd' => 'Set a new password for a user',
                'user:disable-2fa' => 'Disable 2FA when the authenticator device is lost'
            ],
            'Customers (for selling web hosting)' => [
                'customer:list' => 'List all customers',
                'customer:create' => 'Create a new customer with quotas',
                'customer:passwd' => 'Set a new password for a customer',
                'customer:quota' => 'Update the quotas of a customer',
                'customer:expiry' => 'Update the expiry date of a customer',
                'customer:status' => 'Change customer status (active/suspended/expired)'
            ],
            'Updates' => [
                'self-update' => 'Check for and install a new release (always verifies the signature first)'
            ],
            'Operating mode' => [
                'mode:show' => 'Show the current mode',
                'mode:set' => 'Change the mode (production | sandbox | dryrun)',
                'sandbox:seed' => 'Load sample data for testing',
                'sandbox:reset' => 'Reset the test environment back to its defaults'
            ],
            'Other' => [
                'capabilities' => 'List every command the agent will carry out',
                'audit:verify' => 'Check the continuity of the audit log',
                'serve' => 'Start a development web server (not for production)'
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

        $this->out->title('System status');
        $this->out->item('Version', PHPCP_VERSION);
        $this->out->item('Mode', $config->mode->value, $config->mode->isProduction() ? '32' : '33');
        $this->out->item('layout', $config->paths->layout);
        $this->out->item('Config file', $config->sourceFile ?? '(using defaults)');

        $this->out->title('Database');
        $dbFile = $config->paths->database();
        if (is_file($dbFile)) {
            $db = $app->db();
            $pending = $db->pendingMigrations($config->paths->migrations());
            $this->out->item('File', $dbFile);
            $this->out->item('Size', Fmt::bytes((int) filesize($dbFile)));
            $this->out->item('Users', (string) (new UserRepository($db))->count().' accounts');
            $this->out->item('Pending migrations', $pending === 0 ? 'none' : $pending.' items', $pending === 0 ? '32' : '33');
        } else {
            $this->out->warn('No database yet - run `phpcp setup` first');
        }

        if (is_file($dbFile)) {
            $this->out->title('Scheduled jobs');
            $jobs = new ScheduledJobRepository($app->db());
            $lastRunAt = $jobs->lastRunAt();

            $this->out->item(
                'Last run',
                $lastRunAt === null ? 'never run' : Fmt::ago($lastRunAt),
                $lastRunAt !== null && time() - $lastRunAt <= 300 ? '32' : '33',
            );

            foreach ($jobs->all() as $job) {
                $this->out->item(
                    (string) $job['name'],
                    sprintf(
                        '%-14s %-10s %s',
                        (string) $job['schedule'],
                        (int) $job['enabled'] === 1 ? ((string) ($job['last_status'] ?? '—')) : 'disabled',
                        $job['last_run_at'] === null ? 'never run' : Fmt::ago((int) $job['last_run_at']),
                    ),
                );
            }
        }

        $this->out->title('Agent');
        $agent = $app->agent();
        $this->out->item('socket', $agent->socketPath());

        if ($agent->isAvailable()) {
            $this->out->ok('Connected');

            try {
                $data = $agent->data('system.metrics', [], $app->systemActor('cli.status'));
                $this->out->item('Host', (string) $data['hostname']);
                $this->out->item('uptime', Fmt::duration((int) $data['uptime']['seconds']));
                $this->out->item('CPU', Fmt::percent((float) $data['cpu']['percent']));
                $this->out->item('RAM', Fmt::percent((float) $data['memory']['percent']));
                $this->out->item('Disk', Fmt::percent((float) $data['disk']['percent']));
            } catch (\Throwable $e) {
                $this->out->warn('Capability call failed: '.$e->getMessage());
            }
        } else {
            $this->out->fail('Cannot reach the agent - check whether phpcp-agentd is running');
        }

        $this->out->line();

        return 0;
    }

    private function doctor(): int
    {
        $app = App::boot();
        $config = $app->config;
        $problems = 0;

        $this->out->title('System check');

        // 1. PHP extension ที่จำเป็น
        $required = ['pdo_sqlite', 'sodium', 'posix', 'pcntl', 'sockets', 'openssl', 'mbstring', 'json', 'filter', 'fileinfo'];
        $missing = array_values(array_filter($required, static fn(string $e): bool => !extension_loaded($e)));

        if ($missing === []) {
            $this->out->ok('Every required PHP extension is present');
        } else {
            $this->out->fail('Missing PHP extensions: '.implode(', ', $missing));
            $problems++;
        }

        // 2. secret key
        if ($config->hasSecretKey()) {
            try {
                $config->secretKey();
                $this->out->ok('secret key is valid');
            } catch (\Throwable $e) {
                $this->out->fail('secret key is invalid: '.$e->getMessage());
                $problems++;
            }
        } else {
            $this->out->fail('No secret key set yet - run `phpcp key:generate`');
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
                    'A config file exists at %s but this user cannot read it - chown root:phpcp then chmod 640',
                    $candidate,
                ));
                $problems++;
            }
        }

        if ($config->sourceFile !== null && is_file($config->sourceFile)) {
            $perms = fileperms($config->sourceFile) & 0o777;
            if (($perms & 0o004) !== 0) {
                $this->out->fail(sprintf('The config file is readable by everyone (%o), it should be 0640', $perms));
                $problems++;
            } else {
                $this->out->ok(sprintf('Config file permissions are correct (%o)', $perms));
            }
        }

        // 4. ฐานข้อมูลและ migration
        if (!is_file($config->paths->database())) {
            $this->out->fail('No database yet - run `phpcp setup`');
            $problems++;
        } else {
            $perms = fileperms($config->paths->database()) & 0o777;
            if (($perms & 0o044) !== 0) {
                $this->out->warn(sprintf('The database file is readable by others (%o), it should be 0600', $perms));
            }

            $pending = $app->db()->pendingMigrations($config->paths->migrations());
            if ($pending > 0) {
                $this->out->fail("{$pending} pending migrations - run `phpcp db:migrate`");
                $problems++;
            } else {
                $this->out->ok('The database schema is up to date');
            }

            // 5. ต้องมีผู้ดูแลระบบอย่างน้อยหนึ่งคน
            $users = new UserRepository($app->db());
            if ($users->countByRole(Permissions::SUPERADMIN) < 1) {
                $this->out->fail('No usable administrator account - run `phpcp user:create`');
                $problems++;
            } else {
                $this->out->ok('Administrator accounts: '.$users->countByRole(Permissions::SUPERADMIN));
            }

            // 6. ความต่อเนื่องของ audit log
            $chain = $app->audit()->verifyChain();
            if ($chain['ok']) {
                $this->out->ok("audit log is continuous across {$chain['count']} entries");
            } else {
                $this->out->fail("audit log was modified - the chain breaks at id {$chain['broken_at']}");
                $problems++;
            }

            // 7. ตัวจับเวลา — ตรวจแยกจากบริการอื่นเพราะมันล้มแบบเงียบสนิท
            //
            // ต่างจาก agent หรือเว็บที่ล่มแล้วเห็นทันที: scheduler ที่ไม่ทำงานทำให้หน้าจอ
            // ปกติทุกอย่าง แต่กลไกคืนค่าอัตโนมัติของ SSH/firewall หายไปเฉย ๆ
            // ผู้ดูแลจะรู้ตัวตอนที่เปลี่ยนพอร์ต SSH ผิดแล้วไม่มีอะไรคืนค่าให้เท่านั้น
            $problems += $this->checkScheduler($app);

            // 7.1 ไฟล์ตั้งค่าของเว็บไซต์ตรงกับเว็บเซิร์ฟเวอร์ที่เลือกไว้หรือไม่
            $problems += $this->checkWebserverConfigs($app);

            // 7.2 มีใครฟังพอร์ต 80 อยู่จริงไหม
            $problems += $this->checkHttpPort();

            // 7.3 http://localhost ของเครื่องพัฒนา (ถ้าเปิดไว้) ใช้งานได้จริงไหม
            $problems += $this->checkLocalhostSite($config);
        }

        // 8. ไฟล์ของ Now.js ที่ commit เข้ามาในโปรเจกต์ (การตัดสินใจ N8)
        $problems += $this->checkSpaBundle($config->paths->spa());

        // 9. agent
        if ($app->agent()->isAvailable()) {
            $this->out->ok('agent responds');
        } else {
            $this->out->fail('agent does not respond');
            $problems++;
        }

        // 10. โหมดกับสิทธิ์ต้องสอดคล้องกัน
        if ($config->mode->isProduction() && $config->paths->layout === 'portable') {
            $this->out->warn('production mode and the portable layout should not be used together on a real server');
        }

        if (!$config->mode->isProduction()) {
            $this->out->warn('The system is in '.$config->mode->value.' mode - commands will not affect the real server');
        }

        $this->out->line();
        if ($problems === 0) {
            $this->out->ok('No problems found');
        } else {
            $this->out->fail("Found {$problems} problem(s)");
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

        $this->out->title('Installing PHP Server Control Panel');

        $config->paths->ensureDirectories();
        $this->out->ok('Created the required directories');

        if (!$config->hasSecretKey()) {
            $this->keyGenerate();
            $this->out->warn('Generated a secret key, please run `phpcp setup` once more');

            return 1;
        }

        $ran = $app->db()->migrate($config->paths->migrations());
        $this->out->ok($ran === [] ? 'The database schema is already up to date' : 'Ran migrations: '.implode(', ', $ran));

        $this->installScheduledJobs($app);

        $users = new UserRepository($app->db());
        if ($users->countByRole(Permissions::SUPERADMIN) > 0) {
            $this->out->info('An administrator already exists, skipping creation of the first account');

            return 0;
        }

        $username = $this->argValue($args, '--user') ?: 'admin';
        $password = Password::random(20);

        $id = $users->create($username, $password, Permissions::SUPERADMIN, mustChangePassword: true);

        $app->audit()->write($app->systemActor('cli.setup'), 'system.setup', $username, 'ok', [
            'user_id' => $id,
            'mode' => $config->mode->value
        ]);

        $this->out->ok("Created administrator account: {$username}");
        $this->out->box('Temporary password (shown only once)', $password);
        $this->out->warn('You will be forced to change this password at the first login');
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
            $this->out->ok('No pending migrations');
        } else {
            foreach ($ran as $version) {
                $this->out->ok('Ran migrations: '.$version);
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
    /**
     * ไฟล์ vhost ของทุกเว็บต้องตรงกับโหมดเว็บเซิร์ฟเวอร์ที่ตั้งไว้
     *
     * **สภาพที่ตรวจจับ:** เปลี่ยนโหมดแล้วค่าระดับเครื่องถูกเขียน (ports.conf, map)
     * แต่ vhost รายเว็บไม่ได้ถูกเขียนตาม — เกิดได้เมื่อการสลับล้มกลางทางหรือมีคน
     * แก้ค่าในไฟล์เองแล้วไม่ได้รัน `sites:rebuild`
     *
     * อาการที่ผู้ดูแลเห็นคือ **เว็บลูกค้าตอบ 403/404 ทั้งเครื่อง** โดยที่ทุกหน้าจอ
     * ของ panel ยังปกติดีทุกอย่าง ไม่มีอะไรฟ้องเลยสักจุด (เจอจริงเมื่อ 2026-08-11)
     *
     * @return int จำนวนปัญหาที่พบ
     */
    private function checkWebserverConfigs(App $app): int
    {
        $sites = new \Phpcp\Domain\SiteRepository($app->db());
        $rows = $sites->listWithCounts();

        if ($rows === []) {
            return 0;
        }

        $mode = trim((new \Phpcp\Domain\SettingsRepository($app->db()))->get('webserver.mode'));
        if ($mode === '') {
            $mode = $app->config->string('webserver', 'apache');
        }

        $templates = new \Phpcp\Driver\Template($app->config->paths->templates());
        $driver = match ($mode) {
            'nginx' => new \Phpcp\Driver\WebServer\NginxDriver($templates),
            'nginx-proxy' => new \Phpcp\Driver\WebServer\NginxProxyDriver($templates),
            default => new \Phpcp\Driver\WebServer\ApacheDriver($templates),
        };

        $missing = [];

        foreach ($rows as $row) {
            $site = $sites->load((int) $row['id']);
            if ($site === null) {
                continue;
            }

            foreach ($driver->vhostPaths($site) as $path) {
                if (!is_file($path)) {
                    $missing[] = $site->domain;
                    break;
                }
            }
        }

        if ($missing === []) {
            $this->out->ok(sprintf('Site config files are complete for mode %s (%d sites)', $mode, count($rows)));

            return 0;
        }

        $this->out->fail(sprintf(
            'Site %d of %d has no config file for mode %s (%s) - run `phpcp sites:rebuild`',
            count($missing),
            count($rows),
            $mode,
            implode(', ', array_slice($missing, 0, 3)) . (count($missing) > 3 ? ' …' : ''),
        ));

        return 1;
    }

    /**
     * มีเว็บเซิร์ฟเวอร์ฟังพอร์ต 80 อยู่จริงหรือไม่
     *
     * **สภาพที่ตรวจจับ:** apache2 กับ nginx ขึ้นครบทั้งคู่ ไฟล์ vhost ครบทุกเว็บ
     * แต่ไม่มีใครฟังพอร์ต 80 เลย — เกิดจากการสลับโหมดที่ทิ้ง `ports.conf` ไว้ที่
     * 127.0.0.1:8080 ขณะที่ vhost ทั้งหมดประกาศ `*:80` (เจอจริง 2026-08-12)
     *
     * ทุกอย่างที่ผู้ดูแลมองเห็นดูปกติหมด: `systemctl status` เขียว · configtest ผ่าน ·
     * หน้าจอ panel ใช้งานได้ทุกหน้า เพราะ panel ฟังพอร์ตของตัวเองแยกต่างหาก · สิ่งเดียว
     * ที่ผิดคือทุกเว็บบนเครื่องเงียบไปพร้อมกัน
     *
     * @return int จำนวนปัญหาที่พบ
     */
    private function checkHttpPort(): int
    {
        $socket = @stream_socket_client('tcp://127.0.0.1:80', $errno, $error, 2);

        if (is_resource($socket)) {
            fclose($socket);
            $this->out->ok('A web server is listening on port 80');

            return 0;
        }

        $this->out->fail(
            'Nothing is listening on port 80 - every site on this machine is unreachable. Run `phpcp sites:rebuild` '
            . 'then `systemctl restart apache2` (changing the listening port cannot be done with a reload)',
        );

        return 1;
    }

    /**
     * http://localhost ของเครื่องพัฒนา — ตรวจเฉพาะเครื่องที่เปิดไว้
     *
     * เครื่องที่ให้บริการจริงไม่ตั้ง `sites.localhost_docroot` จึงไม่มีอะไรให้ตรวจ
     * และไม่มีบรรทัดรบกวนใน doctor
     *
     * @return int จำนวนปัญหาที่พบ
     */
    private function checkLocalhostSite(Config $config): int
    {
        $docroot = $config->localhostDocroot();

        if ($docroot === '') {
            return 0;
        }

        $problems = 0;

        if (!is_dir($docroot)) {
            $this->out->fail(sprintf('sites.localhost_docroot points at %s, which does not exist', $docroot));
            $problems++;
        }

        // pool มาตรฐานของดิสโทร — ถ้าไม่มี ไฟล์ .php จะตอบ 503 ส่วนไฟล์ static ยังปกติ
        // ซึ่งเป็นอาการที่หลอกที่สุด เพราะหน้าแรกที่เป็น index.html ยังเปิดได้
        $socket = '/run/php/php' . $config->localhostPhp() . '-fpm.sock';

        if (!file_exists($socket)) {
            $this->out->fail(sprintf(
                'http://localhost uses %s but that socket does not exist - install php%s-fpm or fix sites.localhost_php',
                $socket,
                $config->localhostPhp(),
            ));
            $problems++;
        }

        if ($problems === 0) {
            $this->out->ok(sprintf('http://localhost serves %s (PHP %s)', $docroot, $config->localhostPhp()));
        }

        return $problems;
    }

    private function checkScheduler(App $app): int
    {
        $problems = 0;
        $jobs = new ScheduledJobRepository($app->db());

        $missing = array_values(array_filter(
            array_column(ScheduledJobRepository::DEFAULTS, 'name'),
            static fn (string $name): bool => $jobs->find($name) === null,
        ));

        if ($missing !== []) {
            $this->out->fail('Missing scheduled jobs that the system requires: '.implode(', ', $missing).' - run `phpcp db:migrate`');
            $problems++;
        }

        $lastRunAt = $jobs->lastRunAt();

        if ($lastRunAt === null) {
            $this->out->fail(
                'The timer has never run - check with `systemctl status phpcp-scheduler.timer` '
                .'(if it is not running, the automatic rollback for SSH/firewall does not work either)'
            );
            $problems++;
        } elseif (time() - $lastRunAt > 300) {
            $this->out->fail(sprintf(
                'The timer has been stuck for %s - run `systemctl restart phpcp-scheduler.timer`',
                Fmt::duration(time() - $lastRunAt),
            ));
            $problems++;
        } else {
            $this->out->ok('The timer last ran '.Fmt::ago($lastRunAt));
        }

        foreach ($jobs->failing() as $job) {
            $this->out->warn(sprintf(
                'Job %s failed on its last run: %s',
                (string) $job['name'],
                (string) ($job['last_error'] ?? 'unknown reason'),
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
            $this->out->fail('Not found: '.$sumsFile.' - the Now.js files are incomplete, the SPA will not load');

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
                $broken[] = $name.' (file missing)';
                continue;
            }

            if (!hash_equals($expected, hash_file('sha256', $file) ?: '')) {
                $broken[] = $name.' (checksum mismatch)';
            }
        }

        if ($broken !== []) {
            $this->out->fail('Now.js files do not match what was committed: '.implode(', ', $broken));

            return 1;
        }

        $this->out->ok('All Now.js files are present and their checksums match: '.count($lines).' files');

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
            $this->out->ok('Added scheduled jobs: '.implode(', ', $added));
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
                "Cannot read the config file (it exists but the permissions are insufficient): %s\n".
                '  Fix with: chown root:phpcp %s && chmod 640 %s',
                implode(', ', $unreadable),
                $unreadable[0],
                $unreadable[0],
            );
        }

        return 'No config file found - copy etc/config.example.php to etc/config.php first';
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
            $this->out->info('Removed the config file of the previous server: ' . $path);
        }

        $this->out->ok((string) $result['message']);

        // การเปลี่ยนพอร์ตที่ฟังทำตอน reload ไม่ได้ตามการออกแบบของ Apache เอง —
        // ถ้าไม่บอกตรงนี้ ผู้ดูแลจะเจอ nginx สตาร์ตไม่ขึ้นเพราะพอร์ตชนแล้วหาสาเหตุไม่เจอ
        if (($result['webserver'] ?? '') === 'nginx-proxy') {
            $this->out->warn('This mode moves Apache back to 127.0.0.1:8080 - it needs one restart, not just a reload');
            $this->out->line('  sudo systemctl restart apache2 && sudo systemctl start nginx');
        }

        // **บอกเสมอว่า http://localhost เปิดหรือปิด** ไม่ใช่เงียบเมื่อปิด
        //
        // ค่านี้อยู่ในไฟล์ตั้งค่า และวิธีที่พลาดง่ายที่สุดคือใส่ผิดบล็อก (ต้องอยู่ใน
        // `sites`) ซึ่งอ่านผ่านตาแล้วเหมือนถูกทุกอย่าง · ถ้าไม่รายงานตรงนี้ ผู้ดูแลจะ
        // เห็นแต่ "สร้างไฟล์ใหม่แล้ว" แล้วไปเจอ 404 ที่เบราว์เซอร์โดยไม่มีเบาะแสเลย
        $localhost = (string) ($result['localhost'] ?? '');

        if ($localhost === '') {
            $this->out->info('http://localhost: disabled - set sites.localhost_docroot in the sites block of config.php');
        } else {
            $this->out->ok('http://localhost serves ' . $localhost);
        }

        // agent อ่าน config.php ตอนบูตครั้งเดียว — แก้ไฟล์แล้วสั่ง rebuild เลยจะได้
        // ผลลัพธ์ของค่าเก่าเงียบ ๆ · เทียบกับสิ่งที่ agent เห็นจริงแล้วบอกให้รีสตาร์ต
        if ($localhost !== $app->config->localhostDocroot()) {
            $this->out->warn(sprintf(
                'The agent still uses the old sites.localhost_docroot (%s) - restart it and run this again',
                ($result['localhost'] ?? '') === '' ? 'disabled' : (string) $result['localhost'],
            ));
            $this->out->line('  sudo systemctl restart phpcp-agentd && sudo phpcp sites:rebuild');
        }

        // ทางกลับก็เปลี่ยนพอร์ตที่ฟังเหมือนกัน — ports.conf เพิ่งถูกเขียนคืนให้ฟัง 80
        // ถ้าไม่รีสตาร์ต Apache จะยังติดอยู่ที่ 8080 แล้วทั้งเครื่องเงียบต่อไปเหมือนเดิม
        if (($result['webserver'] ?? '') === 'apache') {
            $this->out->warn('If you just switched back from nginx-proxy, one restart is required, not just a reload');
            $this->out->line('  sudo systemctl stop nginx && sudo systemctl restart apache2');
        }

        return 0;
    }

    /**
     * เปิด/ปิดเมลของโดเมน
     *
     * เดินผ่าน agent เหมือนที่หน้าเว็บทำ — ชั้น CLI ไม่มีสิทธิ์เขียน /etc/postfix เอง
     * และการตรวจค่ากับ audit log ต้องอยู่ที่เดียวกันทั้งระบบ
     *
     * @param list<string> $args
     */
    private function mailDomain(array $args, bool $enabled): int
    {
        $domain = $this->firstValue($args);

        if ($domain === '') {
            $this->out->fail('A domain is required - `phpcp mail:' . ($enabled ? 'enable' : 'disable') . ' example.com`');

            return 1;
        }

        return $this->runMailCapability('mail.domain_set', ['domain' => $domain, 'enabled' => $enabled]);
    }

    /** @param list<string> $args */
    private function mailBoxAdd(array $args): int
    {
        $address = $this->firstValue($args);

        if ($address === '') {
            $this->out->fail('An address is required - `phpcp mail:box-add me@example.com`');

            return 1;
        }

        $quota = (int) ($this->argValue($args, '--quota') ?: 1024);

        // ระบุรหัสเองได้ — ใช้ตอนย้ายกล่องมาจากที่อื่นและต้องใช้รหัสเดิม
        // ไม่ระบุ = ระบบสุ่มให้แล้วแสดงครั้งเดียว ซึ่งเป็นทางที่ปลอดภัยกว่า
        return $this->runMailCapability('mail.box_create', [
            'address' => $address,
            'quota_mb' => $quota,
            'password' => $this->argValue($args, '--password'),
        ], 'password');
    }

    /** @param list<string> $args */
    private function mailBoxDelete(array $args): int
    {
        $address = $this->firstValue($args);

        if ($address === '') {
            $this->out->fail('An address is required - `phpcp mail:box-del me@example.com`');

            return 1;
        }

        return $this->runMailCapability('mail.box_delete', ['address' => $address]);
    }

    /**
     * เรียก capability ของเมลแล้วรายงานผลแบบเดียวกันทุกคำสั่ง
     *
     * @param array<string,mixed> $payload
     * @param string $secretKey คีย์ในผลลัพธ์ที่ต้องแสดงในกรอบ "ครั้งเดียวเท่านั้น"
     */
    /**
     * เปลี่ยนใบรับรองของหน้าจัดการจากบรรทัดคำสั่ง
     *
     * **ต้องมีทางนี้เสมอ** — ใบที่ผิดทำให้เบราว์เซอร์ปฏิเสธการเชื่อมต่อทั้งหมด แล้วหน้าเว็บ
     * ซึ่งเป็นที่เดียวที่จะแก้ได้ก็เข้าไม่ได้ไปด้วย · `--self-signed` คือทางกลับที่ใช้ได้
     * แม้ตอนที่ทุกอย่างพังแล้ว
     *
     * @param list<string> $args
     */
    private function panelCert(array $args): int
    {
        $selfSigned = in_array('--self-signed', $args, true);
        $domain = $selfSigned ? '' : $this->firstValue($args);

        if (!$selfSigned && $domain === '') {
            $this->out->fail(
                'A domain is required - `phpcp panel:cert panel.example.com` '
                . 'or `phpcp panel:cert --self-signed` to go back to the self-signed certificate',
            );

            return 1;
        }

        /*
         * ไม่ตั้งเวลาถอนคืนเมื่อสั่งจากบรรทัดคำสั่ง — กลไกนั้นมีไว้กันคนที่ทำงานผ่านหน้าเว็บ
         * ถูกตัดขาดจากเครื่องตัวเอง · คนที่สั่งจากตรงนี้อยู่บนเครื่องแล้วและแก้กลับได้ทันที
         * การคืนค่าอัตโนมัติจึงกลายเป็นความประหลาดใจที่ไม่มีประโยชน์
         */
        return $this->runMailCapability('panel.cert_set', ['domain' => $domain, 'window' => 0]);
    }

    /**
     * คัดลอกใบที่เพิ่งต่ออายุมาให้หน้าจัดการ — certbot เรียกผ่าน deploy hook
     *
     * อ่านจากค่าตั้งว่าตอนนี้ผูกกับโดเมนไหน แล้วทำซ้ำสิ่งที่เคยทำ · ไม่ผูกกับโดเมนไหนอยู่
     * ก็จบเงียบ ๆ ด้วยรหัส 0 เพราะ hook ที่คืนค่าไม่เป็นศูนย์ทำให้ certbot รายงานว่าการ
     * ต่ออายุล้มเหลวทั้งที่ใบใหม่ออกมาเรียบร้อยแล้ว
     */
    private function panelCertSync(): int
    {
        $app = App::boot();
        $domain = (new \Phpcp\Domain\SettingsRepository($app->db()))
            ->get(\Phpcp\Agent\Capability\PanelCertSet::SETTING);

        if ($domain === '') {
            $this->out->info('The panel still uses the self-signed certificate - there is nothing to copy');

            return 0;
        }

        return $this->runMailCapability('panel.cert_set', ['domain' => $domain, 'window' => 0]);
    }

    private function runMailCapability(string $capability, array $payload, string $secretKey = ''): int
    {
        $app = App::boot();

        try {
            $result = $app->agent()->data($capability, $payload, $app->systemActor('cli.' . $capability));
        } catch (AgentException $e) {
            $this->out->fail($e->getMessage());

            return 1;
        }

        $this->out->ok((string) ($result['message'] ?? 'Success'));

        if ($secretKey !== '' && ($result[$secretKey] ?? '') !== '') {
            $this->out->box('Mailbox password (shown only once)', (string) $result[$secretKey]);
        }

        return 0;
    }

    /** รายชื่อโดเมนที่เปิดเมลและกล่องทั้งหมด — อ่านอย่างเดียว ไม่ต้องผ่าน agent */
    private function mailList(): int
    {
        $app = App::boot();
        $repository = new \Phpcp\Domain\MailboxRepository($app->db());

        $domains = $repository->enabledDomains();

        if ($domains === []) {
            $this->out->info('No domain has mail enabled yet - `phpcp mail:enable example.com`');

            return 0;
        }

        $this->out->ok('Domains with mail enabled: ' . implode(', ', $domains));

        foreach ($repository->activeMailboxes() as $box) {
            $this->out->line(sprintf('  %s  (%d MB)', $box['address'], $box['quota_mb']));
        }

        return 0;
    }

    /**
     * ค่าแรกที่ไม่ใช่ตัวเลือก — ใช้กับคำสั่งที่รับอาร์กิวเมนต์เดียว
     *
     * @param list<string> $args
     */
    private function firstValue(array $args): string
    {
        foreach ($args as $arg) {
            if (!str_starts_with($arg, '-')) {
                return $arg;
            }
        }

        return '';
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
            $this->out->warn('A secret key already exists, regenerating it makes existing TOTP secrets impossible to decrypt');
            if (!$this->out->confirm('Regenerate it')) {
                return 1;
            }
        }

        $contents = file_get_contents($file);
        if ($contents === false) {
            $this->out->fail("Cannot read the config file: {$file}");

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
            $this->out->fail("Could not find the line 'secret_key' in the config file - add it by hand and try again");

            return 1;
        }

        file_put_contents($file, $updated, LOCK_EX);
        @chmod($file, 0640);

        $this->out->ok("Saved the secret key to {$file}");

        return 0;
    }

    /**
     * @return int
     */
    private function userList(): int
    {
        $app = App::boot();
        $users = (new UserRepository($app->db()))->all();

        $this->out->title('Panel users ('.count($users).' accounts)');

        foreach ($users as $user) {
            $flags = [];
            if ((int) $user['totp_enabled'] === 1) {
                $flags[] = '2FA';
            }
            if ((int) $user['must_change_password'] === 1) {
                $flags[] = 'must change password';
            }
            if ($user['status'] !== 'active') {
                $flags[] = 'suspended';
            }

            $this->out->item(
                (string) $user['username'],
                sprintf(
                    '%-22s last login %s%s',
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

        $this->out->title('Hosting accounts ('.count($accounts).' accounts)');

        $quotaChecker = new QuotaChecker($customers);

        foreach ($accounts as $customer) {
            $customerId = (int) $customer['id'];
            $summary = $quotaChecker->summary($customerId) ?? [];

            // สองแกน: status คุมสิทธิ์ล็อกอิน · service_status คุมบริการโฮสติ้ง
            $flags = [];
            if ($customer['status'] !== 'active') {
                $flags[] = 'login: '.$customer['status'];
            }
            if ($customer['service_status'] !== 'active') {
                $flags[] = 'service: '.$customer['service_status'];
            }
            if ($customer['expiry_at'] !== null && (int) $customer['expiry_at'] < time()) {
                $flags[] = 'expired';
            }

            $expiryText = $customer['expiry_at'] !== null ? date('Y-m-d', (int) $customer['expiry_at']) : 'none';

            $domainQuota = Quota::format($summary['domains']['limit'] ?? 0);
            $dbQuota = Quota::format($summary['databases']['limit'] ?? 0);

            $this->out->item(
                (string) $customer['username'],
                sprintf(
                    '%-15s domains %d/%s databases %d/%s expires %s%s',
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

        $username = $this->argValue($args, '--user') ?: $this->out->ask('Username');
        $email = $this->argValue($args, '--email') ?: $this->out->ask('Email');
        $rawDomains = $this->argValue($args, '--quota-domains');
        $rawSubdomains = $this->argValue($args, '--quota-subdomains');
        $rawAliases = $this->argValue($args, '--quota-aliases');
        $rawEmails = $this->argValue($args, '--quota-emails');
        $rawDatabases = $this->argValue($args, '--quota-databases');
        $rawFtp = $this->argValue($args, '--quota-ftp');
        $expiryAt = $this->argValue($args, '--expiry');
        $expiryTimestamp = $expiryAt !== '' ? strtotime($expiryAt) : null;

        if ($expiryAt !== '' && $expiryTimestamp === false) {
            $this->out->fail("Cannot read the expiry date: {$expiryAt} - use the format YYYY-MM-DD");

            return 1;
        }

        $password = Password::random(20);

        // ส่งผ่าน agent เหมือนที่หน้าเว็บทำ — การตรวจค่าและ audit log จึงอยู่ที่เดียวกัน
        // ไม่ใช่สองชุดที่ค่อย ๆ เพี้ยนจากกัน (PLAN-V2 A2)
        try {
            $result = $app->agent()->data('customer.create', [
                'username' => $username,
                'password' => $password,
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
        $this->out->box('Temporary password', $password);
        $this->out->warn('You will be forced to change this password at the first login');

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

        $username = $args[0] ?? $this->out->ask('Username');
        $customer = $customers->findByUsername($username);

        if ($customer === null) {
            $this->out->fail("Customer not found: {$username}");

            return 1;
        }

        $password = $this->out->ask('New password (leave empty to have one generated)', hidden: true);
        $generated = false;

        if ($password === '') {
            $password = Password::random(20);
            $generated = true;
        }

        $customers->setPassword((int) $customer['id'], $password, clearMustChange: false);

        $app->audit()->write($app->systemActor('cli.customer_passwd'), 'customer.password_changed', $username, 'ok', ['via' => 'cli']);

        $this->out->ok("Set a new password for {$username}");

        if ($generated) {
            $this->out->box('New password', $password);
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

        $username = $args[0] ?? $this->out->ask('Username');
        $customer = $customers->findByUsername($username);

        if ($customer === null) {
            $this->out->fail("Customer not found: {$username}");

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
            $this->out->fail('At least one quota to update must be given');

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

        $username = $args[0] ?? $this->out->ask('Username');
        $customer = $customers->findByUsername($username);

        if ($customer === null) {
            $this->out->fail("Customer not found: {$username}");

            return 1;
        }

        $expiryAt = $this->argValue($args, '--expiry');

        if ($expiryAt === '') {
            $this->out->fail('An expiry date is required with --expiry=YYYY-MM-DD, or leave it empty to clear it');

            return 1;
        }

        $expiryTimestamp = $expiryAt !== '' ? strtotime($expiryAt) : null;

        try {
            $customers->updateExpiry((int) $customer['id'], $expiryTimestamp);

            $app->audit()->write($app->systemActor('cli.customer_expiry'), 'customer.expiry_update', $username, 'ok', [
                'expiry_at' => $expiryTimestamp
            ]);

            $this->out->ok($expiryTimestamp === null
                    ? "Cleared the expiry date of {$customer['username']}"
                    : "Set the expiry date of {$customer['username']} to ".date('Y-m-d', $expiryTimestamp)
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

        $username = $args[0] ?? $this->out->ask('Username');
        $customer = $customers->findByUsername($username);

        if ($customer === null) {
            $this->out->fail("Customer not found: {$username}");

            return 1;
        }

        $status = $this->argValue($args, '--status');
        if ($status === '') {
            $this->out->fail('A status is required with --status=active|suspended|expired');

            return 1;
        }

        if (!in_array($status, ['active', 'suspended', 'expired'], true)) {
            $this->out->fail('Invalid status, allowed: active, suspended, expired');

            return 1;
        }

        $customers->setStatus((int) $customer['id'], $status);

        $app->audit()->write($app->systemActor('cli.customer_status'), 'customer.status_update', $username, 'ok', [
            'status' => $status
        ]);

        $this->out->ok("Changed the status of {$customer['username']} to {$status}");

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

        $username = $this->argValue($args, '--user') ?: $this->out->ask('Username');
        $role = $this->argValue($args, '--role') ?: Permissions::SUPERADMIN;

        if (!Permissions::isValidRole($role)) {
            $this->out->fail('Invalid role, allowed: superadmin, sysadmin, webadmin');

            return 1;
        }

        if (preg_match('/^[a-zA-Z][a-zA-Z0-9_.-]{2,31}$/', $username) !== 1) {
            $this->out->fail('The username must be 3-32 characters long and start with a letter');

            return 1;
        }

        if ($users->findByUsername($username) !== null) {
            $this->out->fail('That username already exists');

            return 1;
        }

        $password = Password::random(20);
        $users->create($username, $password, $role, mustChangePassword: true);

        $app->audit()->write($app->systemActor('cli.user_create'), 'user.create', $username, 'ok', ['role' => $role]);

        $this->out->ok("Created user {$username} ({$role})");
        $this->out->box('Temporary password', $password);

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

        $username = $args[0] ?? $this->out->ask('Username');
        $user = $users->findByUsername($username);

        if ($user === null) {
            $this->out->fail("User not found: {$username}");

            return 1;
        }

        $password = $this->out->ask('New password (leave empty to have one generated)', hidden: true);
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

        $this->out->ok("Set a new password for {$username} (revoked {$removed} existing sessions)");

        if ($generated) {
            $this->out->box('New password', $password);
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

        $username = $args[0] ?? $this->out->ask('Username');
        $user = $users->findByUsername($username);

        if ($user === null) {
            $this->out->fail("User not found: {$username}");

            return 1;
        }

        $users->disableTotp((int) $user['id']);

        $app->audit()->write($app->systemActor('cli.disable_2fa'), 'user.disable_2fa', $username, 'ok', ['via' => 'cli']);

        $this->out->ok("Disabled 2FA for {$username}");
        $this->out->warn('The user should set up 2FA again as soon as they can log in');

        return 0;
    }

    /**
     * @return int
     */
    private function modeShow(): int
    {
        $config = App::boot()->config;

        $this->out->title('Operating mode');
        $this->out->item('Current', $config->mode->value, $config->mode->isProduction() ? '32' : '33');

        if (!$config->mode->isProduction()) {
            $this->out->item('Test prefix', $config->sandboxPrefix());
            $this->out->line();
            // ไม่ใช้ Mode::bannerText() — ข้อความนั้นเป็นของแถบเตือนบนหน้าเว็บซึ่งเป็นภาษาไทย
            // ส่วนบรรทัดนี้ออกทางเทอร์มินัลของเครื่องปลายทางที่แสดงภาษาไทยไม่ได้
            $this->out->warn('Commands in '.$config->mode->value.' mode do not affect the real server');
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
            $this->out->fail('Invalid mode, allowed: production, sandbox, dryrun');

            return 1;
        }

        $file = $app->config->sourceFile;
        if ($file === null) {
            $this->out->fail($this->missingConfigReason());

            return 1;
        }

        // เปลี่ยนเข้าสู่ production ต้องยืนยันสองชั้น เพราะคำสั่งจะเริ่มมีผลกับเครื่องจริง
        if ($mode->isProduction()) {
            $this->out->warn('production mode makes every command affect the real server immediately');
            if (!$this->out->confirm('Switch to production mode')) {
                return 1;
            }
            if ($this->out->ask('Type the word production to confirm') !== 'production') {
                $this->out->fail('Cancelled');

                return 1;
            }
        }

        $contents = (string) file_get_contents($file);
        $updated = preg_replace("/('mode'\s*=>\s*)'[^']*'/", "$1'".$mode->value."'", $contents, 1, $count);

        if ($updated === null || $count === 0) {
            $this->out->fail("Could not find the line 'mode' in the config file");

            return 1;
        }

        file_put_contents($file, $updated, LOCK_EX);

        $app->audit()->write($app->systemActor('cli.mode_set'), 'system.mode_changed', $mode->value, 'ok', [
            'from' => $app->config->mode->value,
            'to' => $mode->value
        ]);

        $this->out->ok('Switched to mode '.$mode->value);
        $this->out->warn('phpcp-agentd must be restarted for the new mode to take effect');

        return 0;
    }

    /**
     * @return int
     */
    private function sandboxSeed(): int
    {
        $app = App::boot();

        if ($app->config->mode->isProduction()) {
            $this->out->fail('Sample data cannot be loaded in production mode');

            return 1;
        }

        $count = (new Seeder($app))->run();

        $this->out->ok('Sample data loaded');
        foreach ($count as $label => $number) {
            $this->out->item($label, (string) $number.' items');
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
            $this->out->fail('Data cannot be cleared in production mode');

            return 1;
        }

        if (!$this->out->confirm('Clear all test data (except user accounts)')) {
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

        $this->out->ok("Cleared the test data ({$removed} rows) and reset the simulated service state");

        return 0;
    }

    /**
     * @return int
     */
    private function capabilities(): int
    {
        $registry = new CapabilityRegistry();

        $this->out->title('Agent capabilities ('.count($registry->names()).' total)');

        foreach ($registry->describe() as $name => $info) {
            $this->out->item(
                $name,
                sprintf('%-18s %s  %s', $info['permission'], $info['mutating'] ? '[mutating]' : '[read-only]', $info['summary']),
            );
        }

        $this->out->title('Self-protected resources');
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

        $this->out->title('Checking for a new release');
        $this->out->line();
        $this->out->line('  Installed version : '.PHPCP_VERSION);

        if (!$updater->isConfigured()) {
            $this->out->fail(
                'This build has no public key embedded, so package signatures cannot be verified',
            );
            $this->out->line();
            $this->out->line('  self-update is disabled on purpose, not by mistake - an updater that does not');
            $this->out->line('  verify signatures is the most direct root-level takeover path there is');
            $this->out->line('  Update by downloading from a trusted source and running ./install.sh again');
            $this->out->line();

            return 1;
        }

        $manifest = $this->option($args, '--manifest', '');

        if ($manifest === '') {
            $this->out->fail('No manifest URL configured yet - give one with --manifest=https://...');

            return 1;
        }

        $release = $updater->parseManifest($updater->fetch($manifest));

        $this->out->line('  Latest announced  : '.$release['version']);
        $this->out->line();

        try {
            $updater->assertUpgrade($release['version'], PHPCP_VERSION);
        } catch (\Throwable $e) {
            $this->out->ok($e->getMessage());

            return 0;
        }

        if (in_array('--check', $args, true)) {
            $this->out->warn('A new release is available - run again without --check to install it');

            return 0;
        }

        $archive = $updater->fetch($release['url']);

        // ตรวจลายเซ็นก่อนแตะไฟล์ใด ๆ บนดิสก์ ไม่ใช่หลังแตกไฟล์
        $updater->verify($archive, $release['signature'], $release['version'], PHPCP_VERSION);
        $this->out->ok('Signature is valid - the package is from the real publisher and was not modified in transit');

        $target = sys_get_temp_dir().'/phpcp-update-'.bin2hex(random_bytes(6)).'.tar.gz';
        file_put_contents($target, $archive);
        @chmod($target, 0600);

        $this->out->line();
        $this->out->ok('Downloaded and verified: '.$target);
        $this->out->line();
        $this->out->line('  The next step is manual on purpose, because replacing code that is currently running');
        $this->out->line('  should happen when the admin is ready to recover the system, not while a script is mid-run:');
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
            $this->out->ok("audit log is continuous across {$result['count']} entries, no sign of tampering");

            return 0;
        }

        $this->out->fail("audit log was modified - the chain breaks at id {$result['broken_at']}");

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
            $this->out->fail('The serve command is for development only, it must not be used in production mode');
            $this->out->info('On a real server use phpcp-web.service as described in ARCHITECTURE §5.2');

            return 1;
        }

        $host = $this->argValue($args, '--host') ?: '127.0.0.1';
        $port = (int) ($this->argValue($args, '--port') ?: '8080');
        $workers = max(2, (int) ($this->argValue($args, '--workers') ?: '6'));

        $docroot = PHPCP_ROOT.'/public';

        $this->out->title('Development web server');
        $this->out->item('Address', "http://{$host}:{$port}");
        $this->out->item('document root', $docroot);
        $this->out->item('Mode', $app->config->mode->value);
        $this->out->item('worker', (string) $workers);
        $this->out->line();
        $this->out->warn('For testing only, press Ctrl+C to stop');
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
        $this->out->fail("Unknown command: {$command}");
        $this->out->info('See all commands with `phpcp help`');

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
