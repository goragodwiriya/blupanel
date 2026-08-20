<?php

declare(strict_types=1);

namespace Phpcp\Domain;

use Phpcp\Kernel\App;
use Phpcp\Security\Permissions;

/**
 * The two emails the panel sends to a hosting customer, assembled from real state
 *
 * ## Why the body is built here and not in the agent
 *
 * Everything in a welcome email is already in the panel's own database —
 * websites, databases, mailboxes, quotas, the expiry date. The web tier is
 * where those repositories live, so composing here means no capability has to
 * grow a second copy of "what does this account actually have".
 *
 * The agent's job is the part only it can do: running `sendmail`. It also
 * re-reads the recipient address from the users table itself rather than
 * trusting the one sent to it, so this layer cannot be used to mail a stranger
 * ({@see \Phpcp\Agent\Capability\MailUserNotice}).
 *
 * ## Plain text, deliberately
 *
 * Same choice as {@see \Phpcp\Driver\Notify\EmailNotifier}. A hosting welcome
 * message is a list of values to be copied into other programs — an HTML mail
 * makes those harder to select, not easier, and a mail client that renders it
 * as a wall of quoted-printable is worse than a message that always looks the
 * same everywhere.
 *
 * ## Nothing secret is included unless the caller passes it in
 *
 * Database and mailbox passwords are never in here: the panel keeps only
 * hashes of them, so it could not include them even if that were wise. The one
 * credential that can appear is a panel password the caller just generated,
 * and only when the caller explicitly hands it over.
 */
final class UserNotice
{
    public const WELCOME = 'welcome';
    public const PASSWORD_RESET = 'password_reset';

    /** @return array<string,string> kind => the name shown in the UI */
    public static function kinds(): array
    {
        return [
            self::WELCOME => 'Welcome email — the account\'s full hosting details',
            self::PASSWORD_RESET => 'New password — resets it and sends the new one',
        ];
    }

    public static function isValidKind(string $kind): bool
    {
        return array_key_exists($kind, self::kinds());
    }

    /**
     * @param array{host?:string,port?:int,panel_url?:string} $machine values that
     *        can only be learned from the machine itself — the caller fetches
     *        them once through the agent and passes them in, so this class stays
     *        free of agent calls and can be exercised without one
     */
    public function __construct(
        private readonly App $app,
        private readonly array $machine = [],
    ) {
    }

    /**
     * The finished message
     *
     * @param array<string,mixed> $user a row from the users table
     * @return array{subject:string,body:string}
     */
    public function build(string $kind, array $user, string $password = ''): array
    {
        return $kind === self::PASSWORD_RESET
            ? $this->passwordReset($user, $password)
            : $this->welcome($user, $password);
    }

    /**
     * @param array<string,mixed> $user
     * @return array{subject:string,body:string}
     */
    private function passwordReset(array $user, string $password): array
    {
        $lines = [
            $this->t('Hello {user},', ['user' => (string) $user['username']]),
            '',
            $this->t('The password for your hosting control panel has been reset.'),
            '',
            $this->pair('Control panel', $this->panelUrl()),
            $this->pair('Username', (string) $user['username']),
        ];

        if ($password !== '') {
            $lines[] = $this->pair('New password', $password);
            $lines[] = '';
            $lines[] = $this->t('You will be asked to choose your own password the first time you sign in with this one.');
        } else {
            $lines[] = '';
            $lines[] = $this->t('Your new password was given to you separately.');
        }

        $lines[] = '';
        $lines[] = $this->t('If you did not ask for this, tell your hosting provider straight away — the previous password no longer works.');

        return [
            'subject' => $this->t('Your hosting password has been reset'),
            'body' => implode("\n", $lines),
        ];
    }

    /**
     * @param array<string,mixed> $user
     * @return array{subject:string,body:string}
     */
    private function welcome(array $user, string $password): array
    {
        $id = (int) $user['id'];
        $username = (string) $user['username'];

        $lines = [
            $this->t('Hello {user},', ['user' => $username]),
            '',
            $this->t('Your hosting account is ready. Everything you need in order to start is below — keep this message, it is the only place all of it appears together.'),
        ];

        $lines = array_merge(
            $lines,
            $this->panelSection($username, $password),
            $this->sitesSection($id),
            $this->sftpSection($user),
            $this->databaseSection($id),
            $this->mailSection($id),
            $this->dnsSection(),
            $this->quotaSection($user),
        );

        $lines[] = '';
        $lines[] = $this->t('Passwords for databases and mailboxes are shown once when each is created and are never stored in readable form — ask your hosting provider to set a new one if any has been lost.');

        return [
            'subject' => $this->t('Your hosting account is ready — {user}', ['user' => $username]),
            'body' => implode("\n", $lines),
        ];
    }

    /**
     * @return list<string>
     */
    private function panelSection(string $username, string $password): array
    {
        $lines = [
            ...$this->heading('Control panel'),
            $this->pair('Address', $this->panelUrl()),
            $this->pair('Username', $username),
        ];

        if ($password !== '') {
            $lines[] = $this->pair('Password', $password);
            $lines[] = $this->t('You will be asked to choose your own password the first time you sign in.');
        }

        return $lines;
    }

    /** @return list<string> */
    private function sitesSection(int $userId): array
    {
        $sites = $this->app->db()->all(
            'SELECT primary_domain, docroot, php_version, status FROM sites WHERE owner_user_id = :u ORDER BY primary_domain',
            ['u' => $userId],
        );

        if ($sites === []) {
            return [];
        }

        $lines = $this->heading('Websites');

        foreach ($sites as $site) {
            $lines[] = '  ' . $site['primary_domain'];
            // The upload folder is the single most-asked question after an
            // account is handed over — an SFTP client opens somewhere else entirely
            $lines[] = '    ' . $this->pair('Upload files to', (string) $site['docroot']);
            $lines[] = '    ' . $this->pair('PHP version', (string) $site['php_version']);
        }

        return $lines;
    }

    /**
     * @param array<string,mixed> $user
     * @return list<string>
     */
    private function sftpSection(array $user): array
    {
        if ((int) ($user['sftp_enabled'] ?? 0) !== 1) {
            return [];
        }

        $host = (string) ($this->machine['host'] ?? '');
        $port = (int) ($this->machine['port'] ?? 0);

        // No host means the agent could not be asked · saying "SFTP is on, work
        // the address out yourself" is worse than leaving the section out and
        // letting the panel's own SFTP page answer it
        if ($host === '' || $port <= 0) {
            return [];
        }

        $login = trim((string) ($user['system_user'] ?? '')) !== ''
            ? (string) $user['system_user']
            : (string) $user['username'];

        return [
            ...$this->heading('File upload (SFTP)'),
            // Named outright, because a customer told only "port 22" reaches for
            // an FTP client and gets an error that reads like a broken account
            $this->pair('Protocol', 'SFTP (SSH File Transfer Protocol)'),
            $this->pair('Host', $host),
            $this->pair('Port', (string) $port),
            $this->pair('Username', $login),
            $this->t('The password is the one you were given for this account.'),
        ];
    }

    /** @return list<string> */
    private function databaseSection(int $userId): array
    {
        $databases = $this->app->db()->all(
            'SELECT d.db_name
               FROM databases_ d
               JOIN sites s ON s.id = d.site_id
              WHERE s.owner_user_id = :u
              ORDER BY d.db_name',
            ['u' => $userId],
        );

        if ($databases === []) {
            return [];
        }

        $lines = [
            ...$this->heading('Databases'),
            $this->pair('Host', 'localhost'),
            $this->pair('Port', '3306'),
        ];

        foreach ($databases as $database) {
            $lines[] = '  ' . $database['db_name'];
        }

        $lines[] = $this->t('Each database has its own user — connect with that one, never with the phpMyAdmin login.');

        return $lines;
    }

    /** @return list<string> */
    private function mailSection(int $userId): array
    {
        $mailboxes = (new MailboxRepository($this->app->db()))->listMailboxes($userId);

        if ($mailboxes === []) {
            return [];
        }

        $host = trim((new SettingsRepository($this->app->db()))->get('mail.hostname'));

        if ($host === '') {
            return [];
        }

        $lines = [
            ...$this->heading('Email'),
            $this->pair('Incoming (IMAP)', $host . ':993 · SSL/TLS'),
            $this->pair('Incoming (POP3)', $host . ':995 · SSL/TLS'),
            $this->pair('Outgoing (SMTP)', $host . ':587 · STARTTLS'),
            $this->t('The username is always the full email address, and sending requires authentication.'),
            '',
        ];

        foreach ($mailboxes as $mailbox) {
            $lines[] = '  ' . $mailbox['local_part'] . '@' . $mailbox['domain'];
        }

        return $lines;
    }

    /** @return list<string> */
    private function dnsSection(): array
    {
        $nameservers = array_values(array_filter(array_map(
            trim(...),
            explode(',', (new SettingsRepository($this->app->db()))->get('dns.nameservers')),
        )));

        if ($nameservers === []) {
            return [];
        }

        return [
            ...$this->heading('DNS'),
            $this->t('Point your domain at these nameservers at the registrar you bought it from:'),
            ...array_map(static fn (string $ns): string => '  ' . $ns, $nameservers),
        ];
    }

    /**
     * @param array<string,mixed> $user
     * @return list<string>
     */
    private function quotaSection(array $user): array
    {
        $summary = (new QuotaChecker(new UserRepository($this->app->db())))->summary((int) $user['id']);

        if ($summary === null) {
            return [];
        }

        $lines = $this->heading('What this package includes');

        foreach ($summary as $quota) {
            // A toggle ("SFTP: 1 of 1") is a number with no meaning — the same
            // rule the quota screen follows, kept in one place in `Quota`
            if ($quota['toggle'] ?? false) {
                $lines[] = $this->pair(
                    (string) $quota['label'],
                    $quota['enabled'] ? $this->t('Included') : $this->t('Not included'),
                );

                continue;
            }

            $lines[] = $this->pair(
                (string) $quota['label'],
                $quota['unlimited'] ? $this->t('Unlimited') : (string) $quota['limit'],
            );
        }

        $expiry = (int) ($user['expiry_at'] ?? 0);

        if ($expiry > 0) {
            $lines[] = $this->pair('Valid until', date('d/m/Y', $expiry));
        }

        return $lines;
    }

    /**
     * Where the customer signs in
     *
     * `panel.base_url` wins when the admin has set one — a machine behind a
     * proxy, or one reached by a real hostname rather than an IP, has an
     * address no amount of looking at the machine itself would reveal.
     */
    private function panelUrl(): string
    {
        $configured = trim((string) $this->app->config->string('panel.base_url'));

        if ($configured !== '') {
            return rtrim($configured, '/') . '/app';
        }

        $host = (string) ($this->machine['host'] ?? '');
        $port = $this->app->config->int('panel.port', 8443);

        return $host === ''
            // Better an honest gap than an address that goes nowhere — the
            // person sending this can paste the right one in before it goes out
            ? $this->t('ask your hosting provider')
            : sprintf('https://%s:%d/app', $host, $port);
    }

    /** @return list<string> */
    private function heading(string $title): array
    {
        return ['', '── ' . $this->t($title) . ' ──'];
    }

    private function pair(string $label, string $value): string
    {
        return $this->t($label) . ': ' . $value;
    }

    /** @param array<string,mixed> $params */
    private function t(string $key, array $params = []): string
    {
        return $this->app->t($key, $params);
    }

    /**
     * Only a hosting account ever receives one of these
     *
     * An admin account has no websites, no quota and no SFTP login — a welcome
     * email to one would be a list of empty sections, and the panel would be
     * mailing an administrator their own password for no reason.
     *
     * @param array<string,mixed> $user
     */
    public static function appliesTo(array $user): bool
    {
        return ($user['role'] ?? '') === Permissions::WEBADMIN;
    }
}
