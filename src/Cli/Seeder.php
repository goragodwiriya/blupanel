<?php

declare (strict_types = 1);

namespace Phpcp\Cli;

use Phpcp\Domain\ScheduledJobRepository;
use Phpcp\Kernel\App;
use Phpcp\Kernel\Db;

/**
 * Sample data for sandbox mode — ARCHITECTURE §6.5
 *
 * The important part: this data flows through the exact same repositories
 * and screens as production, not mock data hardcoded into HTML the way the
 * old prototype did. What PROMPT.md asks for (a fully working Thai-market
 * demo) and a system that's genuinely usable in production therefore come
 * from the same code, differing only in the mode value
 */
final class Seeder
{
    /**
     * @param App $app
     */
    public function __construct(private readonly App $app)
    {
    }

    /** @return array<string,int> */
    public function run(): array
    {
        $db = $this->app->db();
        $this->reset();

        return $db->transaction(function (Db $db): array {
            $now = time();
            $day = 86400;

            // Hosting accounts — since migration 0005, a customer is a row in
            // users with role=webadmin, no longer a separate table · must be
            // created before websites, since every website needs an owner
            //
            // status = login privileges · service_status = the hosting service's status — these two axes are deliberately allowed to differ
            $accounts = [
                ['customer_a', 'บริษัท ABC จำกัด', 'contact@abc.co.th', 'active', 'active', $now + 180 * $day],
                ['customer_b', 'ร้านค้าออนไลน์ XYZ', 'owner@xyz.shop', 'active', 'active', $now + 5 * $day],
                ['customer_c', 'หจก. ไทยพาณิชย์', 'admin@thaipanich.co.th', 'disabled', 'suspended', $now + 30 * $day],
                ['customer_d', 'คุณสมชาย ใจดี', 'somchai@gmail.com', 'disabled', 'expired', $now - 10 * $day],
            ];

            $userIds = [];
            foreach ($accounts as $index => [$username, , $email, $status, $service, $expiryAt]) {
                $userIds[$username] = $db->insert('users', [
                    'username' => $username,
                    'password_hash' => password_hash('TempPass'.$index.'!', PASSWORD_DEFAULT),
                    'role' => 'webadmin',
                    'email' => $email,
                    'must_change_password' => 0,
                    'status' => $status,
                    'service_status' => $service,
                    'quota_domains' => 10,
                    'quota_subdomains' => 20,
                    'quota_aliases' => 50,
                    'quota_emails' => 100,
                    'quota_databases' => 10,
                    'quota_ftp_users' => 5,
                    'system_user' => $username,
                    'disk_quota_mb' => 10240,
                    'expiry_at' => $expiryAt,
                    'created_at' => $now - random_int(30, 200) * $day,
                    'updated_at' => $now - random_int(1, 30) * $day,
                ]);
            }

            // Each website's owner — a website not in this list belongs to the administrator
            $siteOwners = [
                'example.com' => 'customer_a',
                'blog.example.com' => 'customer_a',
                'shop.com' => 'customer_b',
                'legacy.example.com' => 'customer_c',
            ];

            $adminId = (int) $db->value("SELECT id FROM users WHERE role = 'superadmin' ORDER BY id LIMIT 1");

            $sites = [
                ['ร้านค้าออนไลน์หลัก', 'example.com', '8.4', 'forced', 'active', 2480, 240 * $day],
                ['ระบบเก่า (ยังไม่ย้าย)', 'legacy.example.com', '7.4', 'on', 'active', 860, 700 * $day],
                ['ร้านขายของ', 'shop.com', '8.4', 'forced', 'active', 5120, 180 * $day],
                ['เว็บสาธิต', 'demo.com', '8.3', 'on', 'active', 340, 90 * $day],
                ['บล็อกบริษัท', 'blog.example.com', '8.4', 'forced', 'active', 1220, 120 * $day],
                ['เว็บไซต์ทดสอบภายใน', 'staging.example.com', '8.4', 'off', 'suspended', 180, 45 * $day]
            ];

            $siteIds = [];
            foreach ($sites as $index => [, $domain, $php, $ssl, $status, $diskMb, $age]) {
                $siteIds[$domain] = $db->insert('sites', [
                    'primary_domain' => $domain,
                    'docroot' => '/srv/phpcp/users/'.($siteOwners[$domain] ?? 'admin').'/domains/'.$domain.'/public',
                    'php_version' => $php,
                    'ssl_mode' => $ssl,
                    'status' => $status,
                    'disk_used_mb' => $diskMb,
                    'owner_user_id' => $userIds[$siteOwners[$domain] ?? ''] ?? $adminId,
                    'created_at' => $now - $age,
                    'updated_at' => $now - random_int(0, 20 * $day)
                ]);
            }

            // Primary domain, subdomain, alias, and redirect — every type PROMPT.md requires
            $domains = [
                ['example.com', 'example.com', 'primary', null, null],
                ['example.com', 'www.example.com', 'alias', null, null],
                ['example.com', 'api.example.com', 'subdomain', null, null],
                ['example.com', 'old.example.com', 'redirect', 'https://example.com', 301],
                ['legacy.example.com', 'legacy.example.com', 'primary', null, null],
                ['shop.com', 'shop.com', 'primary', null, null],
                ['shop.com', 'www.shop.com', 'alias', null, null],
                ['demo.com', 'demo.com', 'primary', null, null],
                ['blog.example.com', 'blog.example.com', 'primary', null, null],
                ['staging.example.com', 'staging.example.com', 'primary', null, null]
            ];

            $domainIds = [];
            foreach ($domains as [$site, $domain, $type, $target, $code]) {
                $domainIds[$domain] = $db->insert('domains', [
                    'site_id' => $siteIds[$site],
                    'domain' => $domain,
                    'type' => $type,
                    'redirect_target' => $target,
                    'redirect_code' => $code,
                    'created_at' => $now - random_int(30, 200) * $day
                ]);
            }

            // All 6 DNS record types PROMPT.md requires
            $records = [
                ['example.com', 'A', '@', '203.0.113.10', 3600, null],
                ['example.com', 'AAAA', '@', '2001:db8::10', 3600, null],
                ['example.com', 'CNAME', 'www', 'example.com', 3600, null],
                ['example.com', 'MX', '@', 'mail.example.com', 3600, 10],
                ['example.com', 'TXT', '@', 'v=spf1 mx -all', 3600, null],
                ['example.com', 'CAA', '@', '0 issue "letsencrypt.org"', 3600, null],
                ['shop.com', 'A', '@', '203.0.113.11', 3600, null],
                ['shop.com', 'CNAME', 'www', 'shop.com', 3600, null],
                ['shop.com', 'MX', '@', 'mail.shop.com', 3600, 10]
            ];

            foreach ($records as [$domain, $type, $name, $value, $ttl, $priority]) {
                $db->insert('dns_records', [
                    'domain_id' => $domainIds[$domain],
                    'type' => $type,
                    'name' => $name,
                    'value' => $value,
                    'ttl' => $ttl,
                    'priority' => $priority
                ]);
            }

            // Certificates — includes one close to expiring, specifically for testing the warning
            $certificates = [
                ['example.com', "Let's Encrypt", 'valid', $now - 30 * $day, $now + 60 * $day, 1, null],
                ['shop.com', "Let's Encrypt", 'valid', $now - 20 * $day, $now + 70 * $day, 1, null],
                ['blog.example.com', "Let's Encrypt", 'expiring', $now - 84 * $day, $now + 6 * $day, 1, null],
                ['demo.com', 'Self-signed', 'valid', $now - 100 * $day, $now + 265 * $day, 0, null],
                ['legacy.example.com', "Let's Encrypt", 'error', $now - 120 * $day, $now - 5 * $day, 1,
                    'ตรวจสอบโดเมนไม่สำเร็จ: DNS ไม่ชี้มาที่เซิร์ฟเวอร์นี้']
            ];

            foreach ($certificates as [$domain, $issuer, $status, $from, $to, $auto, $error]) {
                $db->insert('certificates', [
                    'domain' => $domain,
                    'issuer' => $issuer,
                    'status' => $status,
                    'not_before' => $from,
                    'not_after' => $to,
                    'auto_renew' => $auto,
                    'last_renew_at' => $from,
                    'last_error' => $error
                ]);
            }

            $databases = [
                ['example_db', 'example.com', 248_000_000],
                ['shop_db', 'shop.com', 1_450_000_000],
                ['legacy_db', 'legacy.example.com', 92_000_000],
                ['blog_db', 'blog.example.com', 34_000_000],
                ['demo_db', 'demo.com', 8_400_000]
            ];

            foreach ($databases as $index => [$name, $site, $size]) {
                $dbId = $db->insert('databases_', [
                    'db_name' => $name,
                    'site_id' => $siteIds[$site],
                    'owner_user_id' => $userIds[$siteOwners[$site] ?? ''] ?? $adminId,
                    'size_bytes' => $size,
                    'created_at' => $now - (200 - $index * 20) * $day
                ]);

                $userId = $db->insert('db_users', [
                    'username' => str_replace('_db', '_user', $name),
                    'host' => 'localhost'
                ]);

                $db->insert('db_grants', [
                    'db_id' => $dbId,
                    'db_user_id' => $userId,
                    'privileges' => 'readwrite'
                ]);
            }

            $crons = [
                ['example.com', 'ล้างตะกร้าสินค้าค้าง', '*/15 * * * *', 'php /srv/phpcp/users/customer_a/domains/example.com/public/cron/cleanup.php', 1, 0],
                ['shop.com', 'ส่งอีเมลสรุปยอดขายรายวัน', '0 1 * * *', 'php /srv/phpcp/users/customer_b/domains/shop.com/public/artisan report:daily', 1, 0],
                ['blog.example.com', 'สร้าง sitemap', '30 2 * * 0', 'php /srv/phpcp/users/customer_a/domains/blog.example.com/public/sitemap.php', 1, 0],
                ['legacy.example.com', 'สำรองฐานข้อมูลเก่า', '0 3 * * *', '/usr/local/bin/legacy-backup.sh', 0, 1]
            ];

            foreach ($crons as [$site, $name, $schedule, $command, $enabled, $exit]) {
                $db->insert('cron_jobs', [
                    'site_id' => $siteIds[$site],
                    'name' => $name,
                    'schedule' => $schedule,
                    'command' => $command,
                    'enabled' => $enabled,
                    'last_run_at' => $now - random_int(600, 86400),
                    'last_exit_code' => $exit,
                    'created_at' => $now - 60 * $day
                ]);
            }

            /*
             * **No sample backup data anymore** (PLAN-BACKUP-V2 item B4)
             *
             * The backup file list is read from the real folder in the
             * customer's home, never from a table · a sample row with no
             * file genuinely present therefore never shows up anywhere, and
             * writing fake gigabyte-sized files into a user's home just so
             * the screen has something to display isn't something a sample-data
             * generator should do — clicking "create a backup" once gets something more real
             */

            // The system's own scheduled jobs — reset() deletes the whole
            // table, so every one of them must be filled back in, not just
            // expiry.check, or the test environment would have no
            // auto-rollback, which is exactly the thing most needing to be testable in sandbox mode
            $scheduledJobs = (new ScheduledJobRepository($db))->installDefaults();

            return [
                'Websites' => count($sites),
                'Domains' => count($domains),
                'DNS records' => count($records),
                'SSL Certificates' => count($certificates),
                'Databases' => count($databases),
                'Cron jobs' => count($crons),
                'Hosting accounts' => count($accounts),
                'Scheduled jobs' => count($scheduledJobs),
                'Log files' => $this->seedLogs()
            ];
        });
    }

    /**
     * Deletes the vhosts, FPM pools, and website homes the panel created in sandbox mode
     *
     * Only deletes files starting with phpcp-, which are only ever files the
     * panel itself wrote — a file an admin wrote by hand is never touched
     */
    private function removeGeneratedConfigs(): void
    {
        $prefix = rtrim($this->app->config->sandboxPrefix(), '/');

        foreach (glob($prefix.'/etc/apache2/sites-enabled/phpcp-*.conf') ?: [] as $file) {
            @unlink($file);
        }

        foreach (glob($prefix.'/etc/php/*/fpm/pool.d/phpcp-*.conf') ?: [] as $file) {
            @unlink($file);
        }

        foreach (glob($prefix.'/srv/phpcp/users/*', GLOB_ONLYDIR) ?: [] as $dir) {
            $this->removeTree($dir);
        }
    }

    /**
     * @param string $path
     */
    private function removeTree(string $path): void
    {
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($items as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }

        @rmdir($path);
    }

    /**
     * Generates sample log files under the sandbox's prefix
     *
     * Needs something genuine to read, or the Logs page could never be
     * tested at all — and because it lives under the prefix, it never touches even one file in the machine's own /var/log
     */
    private function seedLogs(): int
    {
        $prefix = rtrim($this->app->config->sandboxPrefix(), '/');
        $now = time();

        $ips = ['203.0.113.42', '198.51.100.17', '203.0.113.9', '192.0.2.88', '198.51.100.230'];
        $paths = ['/', '/index.php', '/wp-login.php', '/api/orders', '/assets/app.css',
            '/products/1024', '/checkout', '/admin', '/.env', '/sitemap.xml'];
        $agents = [
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/131.0 Safari/537.36',
            'Mozilla/5.0 (iPhone; CPU iPhone OS 18_2) Safari/605.1.15',
            'Googlebot/2.1 (+http://www.google.com/bot.html)',
            'curl/8.5.0'
        ];

        // Access log in the combined format, the same as a real Apache
        $access = [];
        for ($i = 400; $i > 0; $i--) {
            $status = match (true) {
                $i % 47 === 0 => 500,
                $i % 23 === 0 => 404,
                $i % 31 === 0 => 403,
                $i % 13 === 0 => 302,
                default => 200,
            };

            $access[] = sprintf(
                '%s - - [%s] "GET %s HTTP/1.1" %d %d "-" "%s"',
                $ips[$i % count($ips)],
                date('d/M/Y:H:i:s O', $now - $i * 37),
                $paths[$i % count($paths)],
                $status,
                random_int(180, 48000),
                $agents[$i % count($agents)],
            );
        }

        $error = [];
        $errorTemplates = [
            '[php:error] [pid %d] [client %s:%d] PHP Fatal error: Uncaught TypeError in /srv/phpcp/sites/legacy.example.com/public/lib/cart.php:88',
            '[php:warn] [pid %d] [client %s:%d] PHP Warning: Undefined array key "qty" in /srv/phpcp/sites/shop.com/public/checkout.php on line 142',
            '[proxy_fcgi:error] [pid %d] [client %s:%d] AH01067: Failed to read FastCGI header',
            '[mpm_event:notice] [pid %d] AH00489: Apache/2.4.58 (Ubuntu) configured -- resuming normal operations',
            '[core:notice] [pid %d] AH00094: Command line: /usr/sbin/apache2'
        ];

        for ($i = 120; $i > 0; $i--) {
            $template = $errorTemplates[$i % count($errorTemplates)];
            $line = str_contains($template, 'client')
                ? sprintf($template, random_int(1000, 9999), $ips[$i % count($ips)], random_int(30000, 60000))
                : sprintf($template, random_int(1000, 9999));

            $error[] = sprintf('[%s] %s', date('D M d H:i:s.u Y', $now - $i * 211), $line);
        }

        $php = [];
        for ($i = 80; $i > 0; $i--) {
            $php[] = sprintf(
                '[%s] %s: [pool %s] %s',
                date('d-M-Y H:i:s', $now - $i * 419),
                $i % 9 === 0 ? 'WARNING' : 'NOTICE',
                ['example.com', 'shop.com', 'blog.example.com'][$i % 3],
                $i % 9 === 0
                    ? 'child 2841 said into stderr: "PHP message: memory limit reached"'
                    : 'fpm is running, pid '.random_int(800, 4000),
            );
        }

        $mysql = [];
        for ($i = 60; $i > 0; $i--) {
            $mysql[] = sprintf(
                '%s %d [%s] %s',
                date('Y-m-d H:i:s', $now - $i * 733),
                random_int(0, 40),
                $i % 11 === 0 ? 'Warning' : 'Note',
                $i % 11 === 0
                    ? "Aborted connection 5821 to db: 'shop_db' user: 'shop_user' (Got timeout reading communication packets)"
                    : 'InnoDB: Buffer pool(s) load completed',
            );
        }

        $syslog = [];
        $auth = [];
        for ($i = 150; $i > 0; $i--) {
            $stamp = date('M j H:i:s', $now - $i * 517);

            $syslog[] = sprintf(
                '%s %s %s: %s',
                $stamp,
                'goragod',
                ['systemd[1]', 'cron[884]', 'kernel', 'CRON[9021]'][$i % 4],
                [
                    'Started Daily apt download activities.',
                    '(root) CMD (test -x /usr/sbin/anacron)',
                    'Finished Rotate log files.',
                    'Reached target Multi-User System.'
                ][$i % 4],
            );

            // auth.log — includes both successful sign-ins and failed attempts
            $auth[] = $i % 7 === 0
                ? sprintf('%s goragod sshd[%d]: Failed password for invalid user admin from %s port %d ssh2',
                $stamp, random_int(1000, 9999), $ips[$i % count($ips)], random_int(30000, 60000))
                : sprintf('%s goragod sudo: pam_unix(sudo:session): session opened for user root(uid=0) by poo(uid=1000)', $stamp);
        }

        $files = [
            '/var/log/apache2/access.log' => $access,
            '/var/log/apache2/error.log' => $error,
            '/var/log/php8.4-fpm.log' => $php,
            '/var/log/mysql/error.log' => $mysql,
            '/var/log/syslog' => $syslog,
            '/var/log/auth.log' => $auth
        ];

        $written = 0;
        foreach ($files as $path => $lines) {
            $target = $prefix.$path;
            $dir = dirname($target);

            if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
                continue;
            }

            if (@file_put_contents($target, implode("\n", $lines)."\n") !== false) {
                $written++;
            }
        }

        $this->seedPhpTree($prefix);
        $this->seedApacheTree($prefix);

        return $written;
    }

    /**
     * The sandbox's Apache config skeleton — lets `apache2 -t` genuinely check the generated vhosts
     *
     * This is what lets sandbox mode still check the part that breaks most
     * often, with real tooling, without touching the machine's own /etc/apache2 at all (ARCHITECTURE §6.3)
     */
    private function seedApacheTree(string $prefix): void
    {
        $root = $prefix.'/etc/apache2';

        foreach (['sites-enabled', 'sites-available', 'logs', 'run'] as $dir) {
            @mkdir($root.'/'.$dir, 0750, true);
        }

        $modules = is_dir('/usr/lib/apache2/modules')
            ? '/usr/lib/apache2/modules'
            : '/usr/lib64/httpd/modules';

        $template = new \Phpcp\Driver\Template($this->app->config->paths->templates());

        @file_put_contents(
            $root.'/apache2.conf',
            $template->render('apache/sandbox-apache2.conf.tpl', [
                'ROOT' => $root,
                'MODULES' => $modules,
                'HTTP_PORT' => 8180// A placeholder port, never genuinely listened on — just here to make the config valid
            ]),
        );

        // The socket address the vhost refers to — doesn't need to genuinely exist, since -t doesn't check it
        @mkdir($prefix.'/run/php', 0755, true);
        @mkdir($prefix.'/srv/phpcp/users', 0711, true);
        @mkdir($prefix.'/var/lib/phpcp/trash', 0750, true);
    }

    /**
     * Simulates the /etc/php structure for the versions "installed" in the sandbox
     *
     * Needed because the sandbox maps /etc/php to the prefix — without
     * generating this, the server overview page would report no PHP
     * installed at all, contradicting the PHP-FPM list showing it as
     * running — the test environment has to be internally consistent throughout, not half-real, half-fake
     */
    private function seedPhpTree(string $prefix): void
    {
        // Matches the version SandboxState sets as PHP-FPM's default
        $common = [
            'ctype', 'curl', 'dom', 'fileinfo', 'filter', 'gd', 'iconv', 'intl', 'json',
            'mbstring', 'mysqlnd', 'opcache', 'openssl', 'pdo', 'pdo_mysql', 'phar',
            'posix', 'readline', 'session', 'simplexml', 'sodium', 'xml', 'zip'
        ];

        foreach (['7.4', '8.3', '8.4', '8.5'] as $version) {
            foreach (['fpm/pool.d', 'fpm/conf.d', 'cli/conf.d'] as $subdir) {
                @mkdir($prefix.'/etc/php/'.$version.'/'.$subdir, 0750, true);
            }

            // Extension files in the same shape Debian/Ubuntu genuinely uses
            // (20-name.ini), so the PHP page reads the extension list the same way it would on a real machine
            $extensions = $version === '7.4'
                ? array_diff($common, ['sodium', 'intl']) // The older version genuinely has fewer, matching reality
                : $common;

            foreach ($extensions as $index => $extension) {
                @file_put_contents(
                    sprintf('%s/etc/php/%s/fpm/conf.d/%02d-%s.ini', $prefix, $version, 20 + ($index % 10), $extension),
                    "; sandbox: simulates the extension's enabling file\nextension={$extension}.so\n",
                );
            }
        }
    }

    /** Clears sample data, but never touches user accounts or the audit log */
    public function reset(): int
    {
        $db = $this->app->db();
        $removed = 0;

        // Also deletes config files the panel had generated, or an orphaned
        // vhost pointing at a website no longer in the database would be
        // left behind — the test environment has to stay internally consistent
        $this->removeGeneratedConfigs();

        // Ordered from child tables to parent tables — even with ON DELETE CASCADE, an explicit order is clearer
        foreach (['db_grants', 'db_users', 'databases_', 'dns_records', 'domains', 'cron_jobs', 'backups', 'certificates', 'sites', 'expiry_notifications', 'scheduled_jobs'] as $table) {
            $removed += $db->run("DELETE FROM {$table}")->rowCount();
        }

        // Sample hosting accounts must always be deleted *after* sites — the
        // database refuses to delete a user who's still a website's owner
        // (trigger trg_users_delete_requires_no_sites), to prevent an ownerless website still running on the machine
        $removed += $db->run(
            "DELETE FROM users WHERE role = 'webadmin'
             AND username IN ('customer_a', 'customer_b', 'customer_c', 'customer_d')",
        )->rowCount();

        return $removed;
    }
}
