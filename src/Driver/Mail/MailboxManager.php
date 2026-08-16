<?php

declare(strict_types=1);

namespace Phpcp\Driver\Mail;

use Phpcp\Agent\ExecutionFailed;
use Phpcp\Agent\Executor\Executor;
use Phpcp\Driver\ConfigTransaction;
use Phpcp\Driver\Template;
use Phpcp\Driver\WebServer\CustomConfig;

/**
 * Real mailboxes on the machine — PLAN-MAIL phase M1
 *
 * ## The system's shape
 *
 * ```
 *   internet ──25──► Postfix ──LMTP──► Dovecot ──► Maildir (owned entirely by vmail)
 *            ──587/465──► SASL auth at Dovecot
 *            ──993/995──────────────► Dovecot
 * ```
 *
 * ## Why lookup tables are files the system writes itself, not the daemon reading the database directly
 *
 * Both Postfix and Dovecot can read SQLite (with an extra package
 * installed), but doing that would mean two programs outside this system
 * permanently hold the panel's own database file open — colliding with two
 * things this whole system holds to throughout: **the agent is the sole
 * writer of config files**, and **every change is fully reversible through
 * ConfigTransaction**.
 *
 * So the whole set of files is rewritten every time something changes, and
 * `postfix check` plus `doveconf -n` are what decide whether to commit or
 * revert — exactly like a vhost.
 *
 * ## What must never go wrong in this file
 *
 *   - **A trailing slash on a Maildir path** — without it, Postfix writes a single mbox file instead
 *   - **An empty table must still write the file** — a domain whose mail
 *     was turned off must genuinely vanish from the file, not linger because "there's nothing to write"
 *   - **A password hash is generated with doveadm**, never PHP's own
 *     `password_hash()` — the format has to be Dovecot's own
 *     ({ARGON2ID}...), or login fails with no message explaining why
 */
final class MailboxManager
{
    /** The system account owning every mail file — has no shell, and isn't tied to any one individual mailbox */
    public const VMAIL_USER = 'vmail';

    /** Where every mailbox is stored — `<domain>/<name>/` */
    public const MAIL_ROOT = '/srv/phpcp/mail';

    private const POSTFIX_DIR = '/etc/postfix';
    private const VMAILBOX = self::POSTFIX_DIR . '/vmailbox';
    private const VALIAS = self::POSTFIX_DIR . '/valias';
    private const VDOMAINS = self::POSTFIX_DIR . '/vdomains';

    /**
     * A config file the panel owns entirely
     *
     * Left public because `mail.cert` uses this file's modification time as
     * the marker for "when the daemon was last told about the certificate"
     * (see MailCertificate::changedSince).
     */
    public const DOVECOT_CONF = '/etc/dovecot/conf.d/99-phpcp.conf';
    private const DOVECOT_USERS = '/etc/dovecot/phpcp-users';

    private const POSTMAP = '/usr/sbin/postmap';
    private const DOVEADM = '/usr/bin/doveadm';
    private const DOVECONF = '/usr/bin/doveconf';

    public function __construct(private readonly Template $templates)
    {
    }

    /** Has Dovecot been installed yet? — without it, nothing at all can be done with mailboxes */
    public function isInstalled(Executor $executor): bool
    {
        return $executor->isSimulated() || $executor->exists($executor->path('/etc/dovecot'));
    }

    /**
     * Rewrites the entire set of lookup tables from the data given
     *
     * A caller always sends "a machine-wide view", never just what
     * changed — writing the whole file this way means there's no way for
     * an orphaned row of an already-deleted mailbox to remain, a bug
     * especially dangerous for mail (a deleted mailbox that keeps receiving mail).
     *
     * @param list<string>                                                    $domains domains with mail turned on
     * @param list<array{address:string,maildir:string,password:string,quota_mb:int}> $boxes
     * @param list<array{source:string,destination:string}>                   $aliases
     * @param array{cert?:string,key?:string}                                 $tls the mail hostname's certificate
     * @return array{domains:int,mailboxes:int,aliases:int}
     */
    public function apply(Executor $executor, array $domains, array $boxes, array $aliases, array $tls = []): array
    {
        $transaction = new ConfigTransaction($executor);

        $transaction->write(self::VDOMAINS, $this->renderDomains($domains), 0644);
        $transaction->write(self::VMAILBOX, $this->renderMailboxes($boxes), 0644);
        $transaction->write(self::VALIAS, $this->renderAliases($aliases, $boxes), 0644);

        // This file holds every mailbox's password hash — the dovecot group can read it, no other user may touch it
        $transaction->write(self::DOVECOT_USERS, $this->renderDovecotUsers($boxes), 0640);

        $certificate = MailCertificate::pathsOrDefault(
            (string) ($tls['cert'] ?? ''),
            (string) ($tls['key'] ?? ''),
        );

        $transaction->write(self::DOVECOT_CONF, $this->templates->render('dovecot/99-phpcp.conf.tpl', [
            'MAIL_ROOT' => self::MAIL_ROOT,
            'VMAIL_USER' => self::VMAIL_USER,
            'USERS_FILE' => self::DOVECOT_USERS,
            // The admin's own directory — read last by Dovecot, so its values win over the ones above
            'CUSTOM_DIR' => $executor->path(CustomConfig::serviceDirectory('dovecot')),
            'TLS_CERT' => $certificate['cert'],
            'TLS_KEY' => $certificate['key'],
            'GENERATED_AT' => date('Y-m-d H:i:s'),
        ]), 0644);

        $transaction->commit(fn (): array => $this->testConfig($executor));

        /*
         * **The users file must belong to the dovecot group**, never root:root
         *
         * Owned by root at 0640 means Dovecot's own auth process (running
         * as the dovecot user) can't open the file at all · the resulting
         * symptom is "Temporary authentication failure" at login, and
         * inbound mail gets bounced because userdb can't be found — neither
         * message contains the word "permission" anywhere (genuinely found while testing M1).
         */
        $executor->exec(
            ['/bin/chown', 'root:dovecot', $executor->path(self::DOVECOT_USERS)],
            timeout: 15,
        );

        // The .db file Postfix genuinely reads must be built after commit —
        // building it before would mean a rollback restores the original
        // text file while the .db stays the new one, silently splitting the two apart
        foreach ([self::VMAILBOX, self::VALIAS, self::VDOMAINS] as $file) {
            $this->postmap($executor, $file);
        }

        $this->reload($executor);

        return ['domains' => count($domains), 'mailboxes' => count($boxes), 'aliases' => count($aliases)];
    }

    /**
     * Creates a mailbox's Maildir folder, with the correct owner
     *
     * 0700, because one mailbox's own content must not be readable by
     * another process on the same machine · every mailbox is owned by
     * vmail identically — separating access between mailboxes is Dovecot's
     * own job, restricting each session to that mailbox's own home.
     */
    public function createMaildir(Executor $executor, string $maildir): void
    {
        $path = $executor->path(rtrim($maildir, '/'));

        $executor->makeDirectory($path, 0700);

        /*
         * **The domain's own folder must also have its owner changed, not just the mailbox's**
         *
         * `mkdir -p` creates the domain's folder owned by root when the
         * first mailbox is created · Dovecot runs as vmail, so it can't
         * traverse into it, and inbound mail gets bounced with "Temporary
         * internal error" — which contains no word "permission" at all
         * (genuinely found while testing M1).
         */
        $owner = self::VMAIL_USER . ':' . self::VMAIL_USER;

        $executor->exec(['/bin/chown', $owner, dirname($path)], timeout: 15);
        $executor->exec(['/bin/chown', '-R', $owner, $path], timeout: 30);
    }

    /** Deletes a mailbox from disk — only ever called after its database row is deleted and the maps are rewritten */
    public function removeMaildir(Executor $executor, string $maildir): void
    {
        $path = rtrim($maildir, '/');

        // Guards against the kind of mistake that deletes the whole machine — the path must live under the real mail store only
        if (!str_starts_with($path, self::MAIL_ROOT . '/') || str_contains($path, '..')) {
            throw new ExecutionFailed('Invalid mailbox path: ' . $path);
        }

        $executor->exec(['/bin/rm', '-rf', $executor->path($path)], timeout: 60);
    }

    /**
     * Deletes an entire domain's mail folder — used when a domain or a website is deleted
     *
     * A database row already disappears on its own via `ON DELETE CASCADE`,
     * but **the file on disk does not follow it** · a mailbox deleted along
     * with its domain would leave a customer's mail sitting on the machine
     * forever with nothing referring to it anymore — consuming disk space
     * indefinitely, and personal data left behind with nobody aware it's still there.
     */
    public function removeDomainDir(Executor $executor, string $domain): void
    {
        // The domain name already passed a Validator on every path here,
        // but this is guarded again anyway, since a mistake here means rm -rf in the wrong place
        if ($domain === '' || str_contains($domain, '/') || str_contains($domain, '..')) {
            throw new ExecutionFailed('Invalid domain name: ' . $domain);
        }

        $path = self::MAIL_ROOT . '/' . $domain;

        if ($executor->exists($executor->path($path))) {
            $executor->exec(['/bin/rm', '-rf', $executor->path($path)], timeout: 120);
        }
    }

    /**
     * Hashes a password with Dovecot's own tool
     *
     * Must be `doveadm pw`, never PHP's own `password_hash()` — Dovecot
     * checks a password by a scheme-name prefix
     * (`{ARGON2ID}$argon2id$...`) · dropping a PHP-generated hash straight
     * in would be unreadable to it, rejecting every login with nothing explaining why.
     */
    public function hashPassword(Executor $executor, string $plain): string
    {
        $result = $executor->exec(
            [$executor->path(self::DOVEADM), 'pw', '-s', 'ARGON2ID', '-p', $plain],
            timeout: 30,
        );

        if (!$result->ok() || trim($result->stdout) === '') {
            throw new ExecutionFailed('Failed to generate a password hash: ' . trim($result->stderr));
        }

        return trim($result->stdout);
    }

    /**
     * Validates both sides' config before committing
     *
     * @return array{0:bool,1:string}
     */
    public function testConfig(Executor $executor): array
    {
        $postfix = $executor->exec([$executor->path('/usr/sbin/postfix'), 'check'], timeout: 20);

        if (!$postfix->ok()) {
            return [false, 'Postfix: ' . trim($postfix->stderr ?: $postfix->stdout)];
        }

        $dovecot = $executor->exec([$executor->path(self::DOVECONF), '-n'], timeout: 20);

        if (!$dovecot->ok()) {
            return [false, 'Dovecot: ' . trim($dovecot->stderr ?: $dovecot->stdout)];
        }

        return [true, ''];
    }

    /**
     * Tells both daemons to re-read their config — **using their own commands, never `systemctl restart`**
     *
     * `postfix reload` and `doveadm reload` finish in a fraction of a
     * second and don't cut any connection in progress · `systemctl
     * restart` stops the service and starts it again, meaning a window
     * where inbound mail is rejected, and on a machine with slow startup
     * this can take longer than the agent is willing to wait (genuinely
     * found while testing: every mail command answered "agent did not respond within 30 seconds").
     *
     * If the daemon isn't running at all yet, reload fails — this falls back to telling systemctl to start it.
     */
    public function reload(Executor $executor): void
    {
        $commands = [
            'postfix' => [$executor->path('/usr/sbin/postfix'), 'reload'],
            'dovecot' => [$executor->path(self::DOVEADM), 'reload'],
        ];

        foreach ($commands as $unit => $command) {
            if ($executor->exec($command, timeout: 15)->ok()) {
                continue;
            }

            $executor->exec(
                [$executor->path('/usr/bin/systemctl'), 'reload-or-restart', $unit],
                timeout: 20,
            );
        }
    }

    /** @param list<string> $domains */
    private function renderDomains(array $domains): string
    {
        $lines = [$this->header('Domains this machine accepts mail for')];

        foreach ($domains as $domain) {
            // The right-hand value has no meaning — Postfix only checks whether the key exists
            $lines[] = $domain . ' OK';
        }

        return implode("\n", $lines) . "\n";
    }

    /** @param list<array{address:string,maildir:string,password:string,quota_mb:int}> $boxes */
    private function renderMailboxes(array $boxes): string
    {
        $lines = [$this->header('Email → Maildir path (the trailing slash is what marks it as Maildir, not mbox)')];

        foreach ($boxes as $box) {
            $lines[] = $box['address'] . ' ' . $box['maildir'];
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * @param list<array{source:string,destination:string}> $aliases
     * @param list<array{address:string,maildir:string,password:string,quota_mb:int}> $boxes
     */
    private function renderAliases(array $aliases, array $boxes): string
    {
        $lines = [$this->header('Addresses forwarded elsewhere · `@domain` is that domain\'s catch-all')];

        /*
         * **Every mailbox needs a line pointing back at itself first** — not redundant
         *
         * Postfix expands a virtual alias **repeatedly until nothing
         * matches anymore** · a domain with a catch-all (`@example.com`)
         * would swallow every real mailbox along with it: mail to `sales@`
         * gets rewritten to `somchai@` by an alias, then `somchai@` gets
         * rewritten again by the catch-all, ending up in the catch-all's
         * own mailbox instead — the real mailbox never receives a single message.
         *
         * A line pointing back at itself stops that second round of expansion right there.
         *
         * Only ever discovered by genuinely sending mail — every config
         * file was correct and `postmap -q` answered correctly too, but
         * mail still landed in the wrong mailbox (PLAN-MAIL M2, 2026-08-12).
         */
        foreach ($boxes as $box) {
            $lines[] = $box['address'] . ' ' . $box['address'];
        }

        foreach ($aliases as $alias) {
            $lines[] = $alias['source'] . ' ' . $alias['destination'];
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * Dovecot's own users file — passwd-file format
     *
     * `user:hash::::::userdb_quota_rule=*:bytes=N`
     * The empty fields are uid/gid/gecos/home/shell, unused because every
     * mailbox runs identically as vmail's own uid (set in the config file, not per line).
     *
     * @param list<array{address:string,maildir:string,password:string,quota_mb:int}> $boxes
     */
    private function renderDovecotUsers(array $boxes): string
    {
        $lines = [$this->header('Mailboxes and their password hashes — this file is a secret, 0640')];

        foreach ($boxes as $box) {
            $lines[] = sprintf(
                '%s:%s::::::userdb_quota_rule=*:bytes=%dM',
                $box['address'],
                $box['password'],
                max(1, $box['quota_mb']),
            );
        }

        return implode("\n", $lines) . "\n";
    }

    private function header(string $what): string
    {
        return "# Generated by PHP Server Control Panel — do not edit by hand\n"
            . '# ' . $what . "\n"
            . '# Generated at ' . date('Y-m-d H:i:s') . "\n";
    }

    private function postmap(Executor $executor, string $file): void
    {
        $result = $executor->exec([$executor->path(self::POSTMAP), $executor->path($file)], timeout: 30);

        if (!$result->ok()) {
            throw new ExecutionFailed('Failed to build table ' . basename($file) . ': ' . trim($result->stderr));
        }
    }
}
