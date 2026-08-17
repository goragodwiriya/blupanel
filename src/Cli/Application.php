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
 * The `phpcp` command — the recovery tool for when the web page can't be reached (ARCHITECTURE §13)
 *
 * Must always exist, because the panel deliberately never lets its own
 * service be managed through the UI, and because a lost password/2FA needs a fix reachable at the machine itself
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

        // 1. Required PHP extensions
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

        // 3. Config file permissions (holds a secret, so must never be world-readable)
        //
        // The unreadable case must be checked first — sourceFile is null both
        // when "no file exists" and when "the file exists but permissions
        // aren't enough." Without separating these, doctor would stay
        // completely silent in the latter case, even though it's the reason the whole panel fell back to sandbox mode
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

        // 4. Database and migrations
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

            // 5. At least one administrator account must exist
            $users = new UserRepository($app->db());
            if ($users->countByRole(Permissions::SUPERADMIN) < 1) {
                $this->out->fail('No usable administrator account - run `phpcp user:create`');
                $problems++;
            } else {
                $this->out->ok('Administrator accounts: '.$users->countByRole(Permissions::SUPERADMIN));
            }

            // 6. The audit log's continuity
            $chain = $app->audit()->verifyChain();
            if ($chain['ok']) {
                $this->out->ok("audit log is continuous across {$chain['count']} entries");
            } else {
                $this->out->fail("audit log was modified - the chain breaks at id {$chain['broken_at']}");
                $problems++;
            }

            // 7. The timer — checked separately from other services because it fails completely silently
            //
            // Unlike the agent or the web server, which are immediately
            // visible when they go down: a scheduler that stops running
            // leaves the screen looking entirely normal, while SSH/firewall's
            // automatic rollback mechanism quietly stops working — an admin
            // only finds out when they change the SSH port incorrectly and nothing reverts it
            $problems += $this->checkScheduler($app);

            // 7.1 Whether each website's config file matches the selected web server
            $problems += $this->checkWebserverConfigs($app);

            // 7.2 Whether anything is genuinely listening on port 80
            $problems += $this->checkHttpPort();

            // 7.3 Whether the dev machine's http://localhost (if configured) genuinely works
            $problems += $this->checkLocalhostSite($config);
        }

        // 8. The Now.js files committed into the project (decision N8)
        $problems += $this->checkSpaBundle($config->paths->spa());

        // 9. The agent
        if ($app->agent()->isAvailable()) {
            $this->out->ok('agent responds');
        } else {
            $this->out->fail('agent does not respond');
            $problems++;
        }

        // 10. Mode and permissions must be consistent with each other
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
     * Every website's vhost files must match the configured web server mode
     *
     * **The condition this detects:** the mode was changed, and the
     * machine-level values got written (ports.conf, map), but the per-site
     * vhosts weren't written to match — happens when the switch failed
     * partway through, or someone edited the files by hand without running `sites:rebuild`
     *
     * What an admin sees is **every customer website answering 403/404**
     * while every panel screen still looks completely normal, with nothing
     * flagging the cause anywhere (genuinely hit this on 2026-08-11)
     *
     * @return int the number of problems found
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
     * Whether a web server is genuinely listening on port 80
     *
     * **The condition this detects:** apache2 and nginx are both fully up,
     * every website's vhost file is in place, but nothing is listening on
     * port 80 at all — caused by a mode switch that left `ports.conf` at
     * 127.0.0.1:8080 while every vhost still declares `*:80` (genuinely hit this on 2026-08-12)
     *
     * Everything an admin can see looks normal: `systemctl status` is green ·
     * configtest passes · every panel screen works, since the panel listens
     * on its own separate port · the only thing wrong is every website on the machine going silent at once
     *
     * @return int the number of problems found
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
     * A dev machine's http://localhost — only checked on a machine that has it configured
     *
     * A real production machine never sets `sites.localhost_docroot`, so
     * there's nothing to check and no line cluttering doctor's output
     *
     * @return int the number of problems found
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

        // The distro's standard pool — without it, a .php file answers 503
        // while a static file still works fine, the most deceptive symptom
        // possible, since the index.html homepage still opens
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

    /**
     * Checks the scheduler's health — returns the number of problems found
     *
     * The "stuck for more than 5 minutes" threshold comes from the real
     * running interval (every minute) plus enough slack for a long job like
     * disk.usage on a machine with many websites — stuck longer than this means it genuinely isn't running
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
     * Checks that the served Now.js files match what was committed — decision N8
     *
     * Now.js is external code this project doesn't write itself, and it runs
     * on the panel's own web page — a page that can control root · that makes
     * it the single most valuable target for a quiet on-machine file edit —
     * swapping just one file would be enough to steal an admin's session
     *
     * SHA256SUMS is generated at commit time and checked here, not when the
     * web page loads — the browser's own SRI only protects against a
     * man-in-the-middle, and does nothing at all if the file on disk itself
     * was edited, since the HTML page that declares the hash lives on that same disk and can be edited right along with it
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
            // Same shape as sha256sum: "<hash>  <filename>"
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
     * Fills in the scheduled jobs the system requires — called every time migrate or setup runs
     *
     * Deliberately lives here instead of in a migration file, because a
     * machine that's already installed has already run through every old
     * migration — a job added later (such as rollback.run in phase A1) would
     * never reach those machines at all if it were written into any one specific migration
     */
    private function installScheduledJobs(App $app): void
    {
        $added = (new ScheduledJobRepository($app->db()))->installDefaults();

        if ($added !== []) {
            $this->out->ok('Added scheduled jobs: '.implode(', ', $added));
        }
    }

    /**
     * The message explaining why there's no config — separates "no file exists" from "exists but unreadable"
     *
     * These two cases are fixed in completely different ways — telling an
     * admin to "copy config.example.php" when the file already exists would
     * send them down the wrong path and risk overwriting the existing config
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
     * Regenerates every website's vhost file from the current `webserver` setting
     *
     * Must be run after changing the `webserver` value in the config file —
     * the existing files are still in the previous server's format, and
     * simply changing the value makes nothing happen on its own
     *
     * Sent through the agent, same as the web page does — the CLI layer has
     * no permission to write /etc/apache2 or /etc/nginx itself, and value
     * checking plus the audit log both need to live in one single place across the whole system
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

        // Changing the listening port can't be done with a reload, by
        // Apache's own design — without saying so here, an admin would hit
        // nginx failing to start over a port conflict with no clue why
        if (($result['webserver'] ?? '') === 'nginx-proxy') {
            $this->out->warn('This mode moves Apache back to 127.0.0.1:8080 - it needs one restart, not just a reload');
            $this->out->line('  sudo systemctl restart apache2 && sudo systemctl start nginx');
        }

        // **Always states whether http://localhost is on or off** — never silent when it's off
        //
        // This value lives in the config file, and the easiest mistake is
        // putting it in the wrong block (it must be inside `sites`), which
        // reads as correct at a glance · without reporting it here, an admin
        // would only see "created a new file" and then hit a 404 in the browser with no clue at all
        $localhost = (string) ($result['localhost'] ?? '');

        if ($localhost === '') {
            $this->out->info('http://localhost: disabled - set sites.localhost_docroot in the sites block of config.php');
        } else {
            $this->out->ok('http://localhost serves ' . $localhost);
        }

        // The agent reads config.php once at boot — editing the file and
        // running rebuild right away would silently get the old value's
        // result · compares against what the agent genuinely sees and tells the admin to restart it
        if ($localhost !== $app->config->localhostDocroot()) {
            $this->out->warn(sprintf(
                'The agent still uses the old sites.localhost_docroot (%s) - restart it and run this again',
                ($result['localhost'] ?? '') === '' ? 'disabled' : (string) $result['localhost'],
            ));
            $this->out->line('  sudo systemctl restart phpcp-agentd && sudo phpcp sites:rebuild');
        }

        // The reverse switch also changes the listening port — ports.conf
        // was just rewritten to listen on 80, and without a restart Apache
        // stays stuck on 8080 with the whole machine still going silent the same way
        if (($result['webserver'] ?? '') === 'apache') {
            $this->out->warn('If you just switched back from nginx-proxy, one restart is required, not just a reload');
            $this->out->line('  sudo systemctl stop nginx && sudo systemctl restart apache2');
        }

        return 0;
    }

    /**
     * Enables/disables mail for a domain
     *
     * Sent through the agent, same as the web page does — the CLI layer has
     * no permission to write /etc/postfix itself, and value checking plus the audit log both need to live in one single place across the whole system
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

        // A password can be specified explicitly — used when migrating a
        // mailbox from elsewhere and the same password must be kept ·
        // unspecified = the system generates one and shows it once, which is the safer path
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
     * Changes the panel's own certificate from the command line
     *
     * **This path must always exist** — a broken certificate makes the
     * browser refuse the connection entirely, taking down the one web page
     * that could fix it along with it · `--self-signed` is the fallback that
     * still works even once everything else is broken
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
         * No rollback window is set when issued from the command line — that
         * mechanism exists to protect someone working through the web page
         * from being cut off from the machine itself · someone issuing this
         * from here is already on the machine and can revert it immediately —
         * an automatic rollback would just become a surprise with no benefit
         */
        return $this->runMailCapability('panel.cert_set', ['domain' => $domain, 'window' => 0]);
    }

    /**
     * Copies a freshly renewed certificate over to the panel — certbot calls this via its deploy hook
     *
     * Reads the config to find which domain is currently bound, then repeats
     * what was done before · if nothing is bound to any domain, this just
     * exits quietly with code 0, since a non-zero exit from the hook makes
     * certbot report the renewal as failed even though the new certificate came out fine
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

    /**
     * Calls a mail capability and reports the result the same way for every command
     *
     * @param array<string,mixed> $payload
     * @param string $secretKey the key in the result that must be shown inside a "shown only once" frame
     */
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

    /** The list of domains with mail enabled and all mailboxes — read-only, doesn't need the agent */
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
     * The first value that isn't an option flag — used for commands that take a single argument
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

            // Two separate axes: status controls login privileges · service_status controls the hosting service
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

        // Sent through the agent, same as the web page does — value checking
        // and the audit log stay in one place instead of two copies that gradually drift apart (PLAN-V2 A2)
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

        // Only sends the quotas that were specified — a value that wasn't given must not be touched
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

        // Changing the password must destroy every existing session
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

        // Switching into production requires two confirmations, since commands start genuinely affecting the real machine
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
     * Checks for and installs a new release
     *
     *   phpcp self-update --check                       Only checks, changes nothing
     *   phpcp self-update --manifest=https://.../x.json  Specifies the source explicitly
     *
     * Deliberately a command-line-only command, with no button on the web
     * page — changing the panel's own code while people are actively using it
     * should be a deliberate decision, made while an admin is at the machine
     * ready to recover, not a button that can be clicked by mistake
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

        // Verifies the signature before touching any file on disk, never after extracting it
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

        // php -S has a single worker by default, which doesn't work with SSE
        // at all: one /api/stream/metrics connection would hold the worker
        // hostage until every other page on the site fails to load ·
        // PHP_CLI_SERVER_WORKERS tells it to fork several (Linux only) ·
        // a real server uses phpcp-fpm, which already has pm.static configured, so this never comes up there
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

    /** Reads an option value in the form --key=value or --key value */
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
