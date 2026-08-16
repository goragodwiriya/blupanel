<?php

declare(strict_types=1);

namespace Phpcp\Agent\Capability;

use Phpcp\Agent\Capability;
use Phpcp\Agent\Context;
use Phpcp\Agent\Executor\Executor;
use Phpcp\Domain\SecurityScore;
use Phpcp\Domain\SettingsRepository;
use Phpcp\Domain\ServiceCatalog;
use Phpcp\Domain\SiteRepository;
use Phpcp\Driver\Firewall\UfwDriver;
use Phpcp\Driver\Security\Fail2banManager;
use Phpcp\Driver\Ssl\CertbotManager;
use Phpcp\Driver\SshManager;

/**
 * Checks security across the whole machine — PROMPT.md's Security section
 *
 * Gathers genuine evidence from the system itself, never from a value the
 * panel merely remembers in its database, because the question that needs
 * answering is "how secure is the machine right now", not "how secure does
 * the panel think it is" — a value the panel once set might already have
 * been changed from the command line, bypassing the web page entirely.
 *
 * Every failed item must give advice that can genuinely be followed, per
 * PROMPT.md's requirement to "show actionable recommendations" — a warning
 * that states only the problem without saying where to fix it ends up
 * ignored eventually. An item with a screen responsible for it gets a
 * `fix_url` pointing there; an item only fixable on the machine itself
 * (e.g. file permissions) gives a command in `advice` instead.
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
        return "Check the server's security status";
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
            ...$this->accountChecks($executor, $context),
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
                'ufw is not installed — the machine accepts connections on every port with a service listening',
                'Install with apt install ufw, then set rules before turning it on',
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
                "Could not check the firewall's real status",
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
                sprintf('Firewall is off (%d rule(s) are set but have no effect yet)', count($status['rules'])),
                "Turn the firewall on — the system always opens this web page's port and SSH first",
                '/server/firewall',
            )];
        }

        $checks = [$this->check(
            'firewall.active',
            'Firewall',
            SecurityScore::PASS,
            20,
            sprintf('Turned on, enforcing %d rule(s)', count($status['rules'])),
        )];

        // Opening ports broadly to the internet is the most common risk after not having a firewall at all
        $panelPort = (string) $context->config->int('panel.port', 8443);
        $exposed = [];

        foreach ($status['rules'] as $rule) {
            if ($rule['action'] !== 'ALLOW' || $rule['source_spec'] !== '') {
                continue;
            }

            // Ports that shouldn't be open to everywhere — databases and the panel itself
            if (in_array($rule['port'], ['3306', '5432', '6379', '27017', '11211', $panelPort], true)) {
                $exposed[] = $rule['target'];
            }
        }

        if ($exposed !== []) {
            $checks[] = $this->check(
                'firewall.exposed',
                'Ports opened wider than necessary',
                SecurityScore::WARN,
                10,
                'Open to access from anywhere: ' . implode(', ', $exposed),
                'Restrict these rules\' sources down to only the IPs that genuinely need them — '
                . 'database and panel ports should never be open to the entire internet',
                '/server/firewall',
            );
        } else {
            $checks[] = $this->check(
                'firewall.exposed',
                'Ports opened wider than necessary',
                SecurityScore::PASS,
                10,
                'No database or panel port was found open to everywhere',
            );
        }

        return $checks;
    }

    /** @return list<array<string,mixed>> */
    private function sshChecks(Executor $executor): array
    {
        $manager = new SshManager();

        if (!$manager->isInstalled($executor)) {
            // No SSH isn't a security problem — if anything, it's one less way in
            return [$this->check('ssh.config', 'SSH', SecurityScore::PASS, 15, 'SSH is not installed on this machine')];
        }

        $values = $manager->read($executor);
        $problems = [];
        $status = SecurityScore::PASS;

        if ($values['PermitEmptyPasswords']['value'] === 'yes') {
            $problems[] = 'Empty passwords are permitted';
            $status = SecurityScore::FAIL;
        }

        if ($values['PermitRootLogin']['value'] === 'yes') {
            $problems[] = 'Root is permitted to log in with a password';
            $status = SecurityScore::FAIL;
        }

        if ($values['PasswordAuthentication']['value'] === 'yes' && $status !== SecurityScore::FAIL) {
            // Password login alone isn't outright dangerous if the password
            // is strong enough, but it's a constant target of automated
            // password guessing, so it counts as worth improving
            $problems[] = 'Password login is enabled';
            $status = SecurityScore::WARN;
        }

        if ($values['PubkeyAuthentication']['value'] === 'no') {
            $problems[] = 'Key-based login is disabled';
            $status = SecurityScore::FAIL;
        }

        return [$this->check(
            'ssh.config',
            'SSH',
            $status,
            15,
            $problems === []
                ? sprintf('Configured appropriately (port %s)', $values['Port']['value'])
                : implode(' · ', $problems),
            $problems === [] ? '' : 'Turn off PermitRootLogin and PermitEmptyPasswords, and use a key instead of a password',
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
            return [$this->check('ssl.coverage', 'SSL', SecurityScore::PASS, 15, 'No website exists on this machine yet')];
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
                'SSL certificate',
                SecurityScore::FAIL,
                15,
                'Certificate already expired or nearly expired: ' . implode(', ', $expiring),
                'Renew the certificate before users lose the ability to reach the site',
                '/ssl',
            ),
            $noCert !== [] => $this->check(
                'ssl.coverage',
                'SSL certificate',
                SecurityScore::FAIL,
                15,
                'Website(s) with no certificate yet: ' . implode(', ', $noCert),
                'Install a certificate for every website — data sent over plain HTTP can be intercepted entirely',
                '/ssl',
            ),
            default => $this->check(
                'ssl.coverage',
                'SSL certificate',
                SecurityScore::PASS,
                15,
                sprintf('All %d website(s) have a working certificate', count($sites)),
            ),
        };

        if ($notForced !== []) {
            $checks[] = $this->check(
                'ssl.forced',
                'Force HTTPS',
                SecurityScore::WARN,
                8,
                'Still reachable over HTTP: ' . implode(', ', $notForced),
                'Turn on "force HTTPS" to redirect every HTTP request to HTTPS',
                '/ssl',
            );
        } else {
            $checks[] = $this->check('ssl.forced', 'Force HTTPS', SecurityScore::PASS, 8, 'Every website already forces HTTPS');
        }

        $checks[] = $certbot->autoRenewActive($executor)
            ? $this->check('ssl.autorenew', 'Automatic renewal', SecurityScore::PASS, 7, 'certbot.timer is running')
            : $this->check(
                'ssl.autorenew',
                'Automatic renewal',
                SecurityScore::WARN,
                7,
                'certbot.timer is not turned on yet',
                'Turn it on with systemctl enable --now certbot.timer — otherwise certificates will expire in 90 days',
                '/ssl',
            );

        return $checks;
    }

    /** @return list<array<string,mixed>> */
    private function accountChecks(Executor $executor, Context $context): array
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
            ? $this->check('account.2fa', 'Two-factor authentication', SecurityScore::PASS, 12, sprintf('Turned on for all %d account(s)', $total))
            : $this->check(
                'account.2fa',
                'Two-factor authentication',
                $with2fa === 0 ? SecurityScore::FAIL : SecurityScore::WARN,
                12,
                sprintf('Turned on for %d of %d account(s)', $with2fa, $total),
                'Turn on 2FA for every account that can access the panel — a password alone cannot stop account takeover',
                '/account/password',
            )];

        // Repeated failed logins on an account are a sign someone is guessing passwords
        $failed = $this->failedLogins($context);

        $checks[] = match (true) {
            $failed['last_24h'] >= 50 => $this->check(
                'account.bruteforce',
                'Failed login attempts',
                SecurityScore::FAIL,
                8,
                sprintf('%d in the last 24 hours, from %d address(es)', $failed['last_24h'], $failed['sources']),
                'If this is external password guessing, restrict the source of the rule that opens '
                . "this web page's port down to only the IPs genuinely in use",
                '/server/firewall',
            ),
            $failed['last_24h'] >= 10 => $this->check(
                'account.bruteforce',
                'Failed login attempts',
                SecurityScore::WARN,
                8,
                sprintf('%d in the last 24 hours', $failed['last_24h']),
                "If this isn't a user's own typo, it means someone is guessing passwords — "
                . 'turn on 2FA for every account and restrict who can reach this web page',
                '/server/users',
            ),
            default => $this->check(
                'account.bruteforce',
                'Failed login attempts',
                SecurityScore::PASS,
                8,
                sprintf('%d in the last 24 hours — a normal level', $failed['last_24h']),
            ),
        };

        $checks[] = $this->panelJailCheck($executor, $context);

        return $checks;
    }

    /**
     * Is anything actually stopping someone who is guessing passwords right now?
     *
     * The check above counts how many attempts happened. This one answers a
     * different, more important question: **what happens after the count** — the
     * app's built-in account lockout only stops one account at a time, so someone
     * cycling through several usernames never hits any single account's ceiling, and
     * every attempt still costs one of the four PHP-FPM workers.
     *
     * Checked against fail2ban itself, not the stored setting — "set to on but the
     * jail isn't running" is worse than "off", because the admin believes they're
     * protected.
     *
     * @return array<string,mixed>
     */
    private function panelJailCheck(Executor $executor, Context $context): array
    {
        $enabled = (new SettingsRepository($context->db))->bool('security.panel_jail.enabled');
        $status = (new Fail2banManager($executor))->statusOf(Fail2banManager::PANEL_LOGIN_JAIL);

        if ($status['active']) {
            return $this->check(
                'account.panel_jail',
                'Login brute-force protection',
                SecurityScore::PASS,
                10,
                $status['banned'] > 0
                    ? sprintf('Active — currently banning %d addresses', $status['banned'])
                    : 'Active',
            );
        }

        if ($enabled) {
            return $this->check(
                'account.panel_jail',
                'Login brute-force protection',
                SecurityScore::FAIL,
                10,
                'Set to on, but fail2ban has not loaded this jail — nothing is protecting it right now',
                'Save the form again on the security page to rewrite the file, '
                . 'or check with `sudo fail2ban-client status ' . Fail2banManager::PANEL_LOGIN_JAIL . '`',
                '/server/security',
            );
        }

        return $this->check(
            'account.panel_jail',
            'Login brute-force protection',
            SecurityScore::WARN,
            10,
            'Not turned on — the app\'s account lockout only stops one account at a time, so cycling through several usernames is never caught',
            'Turn it on in the security page, and put your own IP in the exempt list first so you can\'t ban yourself',
            '/server/security',
        );
    }

    /** @return list<array<string,mixed>> */
    private function phpChecks(Context $context): array
    {
        $rows = $context->db->all('SELECT php_version, count(*) n FROM sites GROUP BY php_version');
        $outdated = [];

        foreach ($rows as $row) {
            if (in_array((string) $row['php_version'], ServiceCatalog::PHP_EOL_VERSIONS, true)) {
                $outdated[] = sprintf('PHP %s (%d site(s))', $row['php_version'], $row['n']);
            }
        }

        if ($rows === []) {
            return [];
        }

        return [$outdated === []
            ? $this->check('php.version', 'PHP version', SecurityScore::PASS, 10, 'Every website uses a version still receiving security updates')
            : $this->check(
                'php.version',
                'PHP version',
                SecurityScore::FAIL,
                10,
                'Using a version past end of support: ' . implode(', ', $outdated),
                'Move to a version still receiving updates — an end-of-life version will never get another vulnerability patch',
                '/php',
            )];
    }

    /**
     * File permissions opened too wide
     *
     * Only checks files the panel owns and knows the correct mode for —
     * never scans the whole machine, since a whole-machine scan is very
     * slow and produces so many false positives that nobody reads it.
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

            // Checks the "property" that matters, not a fixed mode number
            //
            // The installer deliberately sets config.php to root:phpcp 0640
            // — the web tier's user has to be able to read this file but
            // must not be able to edit it and isn't its owner, which is
            // actually stronger than a 0600 owned by the web user would be.
            // Comparing directly against 0600 would report the installer's
            // own correct setup as wrong, and the whole page's score would
            // lose all credibility.
            //
            // Two things are genuinely dangerous: another user on the machine can touch it, and the group can write to it
            $problems = [];

            if (($mode & 0007) !== 0) {
                $problems[] = 'Accessible to other users on the machine';
            }

            if (($mode & 0020) !== 0) {
                $problems[] = 'Editable by group members';
            }

            if ($problems !== []) {
                $bad[] = sprintf('%s (%04o — %s)', basename($path), $mode, implode(', ', $problems));
            }
        }

        if ($bad === []) {
            return [$this->check('file.permissions', 'Critical file permissions', SecurityScore::PASS, 10, "The panel's config file and database have correct permissions")];
        }

        return [$this->check(
            'file.permissions',
            'Critical file permissions',
            SecurityScore::FAIL,
            10,
            'Opened too wide: ' . implode(', ', $bad),
            "These files hold hashed passwords and an admin's session — "
            . 'no other user on the machine should be able to touch them at all — fix on the machine with chmod o=,g-w on the file(s) listed',
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
