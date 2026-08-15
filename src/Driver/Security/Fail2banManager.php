<?php

declare(strict_types=1);

namespace Phpcp\Driver\Security;

use Phpcp\Agent\ExecutionFailed;
use Phpcp\Agent\Executor\Executor;
use Phpcp\Agent\ValidationError;
use Phpcp\Domain\Site;
use Phpcp\Driver\ConfigTransaction;
use Phpcp\Support\BinaryPath;

/**
 * Per-website request rate limiting through fail2ban — PLAN-V2 phase E5
 *
 * **Why not a web server module** (full reasoning in
 * `db/migrations/0016_site_rate_limit.sql`): Apache has no built-in request-count
 * limiter — `mod_ratelimit` only caps bandwidth, and `mod_evasive` counts separately
 * per child process, so the real threshold ends up being the configured value times
 * the number of children. fail2ban already runs on the machine, and the same code
 * path works for both Apache and nginx.
 *
 * Files written (one set per site, named from `system_user`, which is already
 * pattern-validated):
 *
 *   /etc/fail2ban/filter.d/phpcp-<user>.conf   catches lines in the access log
 *   /etc/fail2ban/jail.d/phpcp-<user>.conf     the threshold and ban duration
 *
 * **One file per site, not a combined file** — deleting one site must never touch
 * another site's config. A combined file rewritten every time would break the whole
 * machine if a single write went wrong.
 */
final class Fail2banManager
{
    /** Checked in order — Debian/Ubuntu put it in /usr/bin, some distros in /usr/local/bin */
    private const CLIENT_PATHS = ['/usr/bin/fail2ban-client', '/usr/local/bin/fail2ban-client'];

    private const FILTER_DIR = '/etc/fail2ban/filter.d';
    private const JAIL_DIR = '/etc/fail2ban/jail.d';

    /** Prefixes every file the panel owns — keeps it off jails an admin wrote by hand */
    private const PREFIX = 'phpcp-';

    /**
     * The panel login page's own jail — one per machine, tied to no particular site
     *
     * Unlike the per-site jails, this one reads the **audit log**, not the access
     * log. Failed logins are recorded in the `audit_log` table, which fail2ban can't
     * read, but {@see \Phpcp\Security\AuditLog} also writes a JSON-lines copy of it —
     * that's the file this jail watches.
     */
    public const PANEL_LOGIN_JAIL = self::PREFIX . 'panel-login';

    /**
     * A jail's mode — what happens once someone crosses the threshold
     *
     *   off     no jail exists on the machine at all — nothing checked, nothing spent
     *   notify  checks, then sends a message — **never touches the firewall**, the
     *           admin decides
     *   ban     checks, then tells the firewall to ban immediately
     *
     * `notify` exists because an automatic ban isn't right for every machine — a
     * customer where the whole organisation shares one outbound IP turns a single
     * ban into cutting off everyone. This mode gives the same information without
     * deciding for anyone.
     */
    public const MODE_OFF = 'off';
    public const MODE_NOTIFY = 'notify';
    public const MODE_BAN = 'ban';

    /**
     * Does the panel own this jail — judged purely from the prefix the panel itself sets
     *
     * Keeps a command from the web tier from touching a jail an admin wrote by hand
     * (the distro's `sshd` jail is the case that matters most) — the panel didn't
     * create it, so the panel shouldn't be the one tearing it down either.
     */
    public static function isOwnJail(string $jail): bool
    {
        return $jail !== self::PREFIX
            && str_starts_with($jail, self::PREFIX)
            && preg_match('/^[a-z0-9._-]+$/i', $jail) === 1;
    }

    /** @return list<string> */
    public static function modes(): array
    {
        return [self::MODE_OFF, self::MODE_NOTIFY, self::MODE_BAN];
    }

    /** The notify-only action file — written alongside any jail using notify mode */
    private const NOTIFY_ACTION = self::PREFIX . 'notify';
    private const ACTION_DIR = '/etc/fail2ban/action.d';

    /** Always exempt, whatever the user enters — reasoning in {@see jailContent()} */
    private const LOCAL_IPS = '127.0.0.1/8 ::1';

    /**
     * Addresses never to ban, no matter the jail — one machine-wide list
     *
     * **Exists because one IP doesn't always stand for one person.** A customer that
     * is a school shares one outbound IP for the whole school — one student's
     * infected machine scanning automatically would lock out the entire school, and
     * because fail2ban commands the firewall, which knows nothing about vhosts, it
     * would lock them out of every other customer's site on the same machine too.
     *
     * **Why this has to be a shared list, not repeated per jail** — the exemptions
     * used to live in two separate places (the per-site `site_rate_limits` table and
     * the login jail's own settings). Registering one school meant chasing down every
     * spot, and **any jail created afterward would never know about it at all**. This
     * is injected into every file this class writes, so registering it once keeps it
     * safe for every jail forever, including ones not yet written.
     */
    private string $neverBan = '';

    /**
     * The command notify mode's action calls — the caller supplies the real path
     *
     * Defaults to the standard path for a normal install, but a caller holding a
     * `Paths` instance should always pass the correct value for that machine
     * ({@see \Phpcp\Kernel\Paths::binary()}).
     */
    private string $alertBinary = '/usr/share/phpcp/bin/phpcp-alert';

    public function __construct(private readonly Executor $executor)
    {
    }

    /**
     * Sets the machine-wide never-ban list — value comes from `security.never_ban_ips`
     *
     * Separate from the constructor because some callers have no database at hand
     * (rendering file content to compare against drift, for instance) — not setting
     * it still works, it just adds no extra exemptions.
     */
    public function withNeverBan(string $ips): self
    {
        $this->neverBan = trim($ips);

        return $this;
    }

    /** The real path to `phpcp-alert` on this machine — used by notify mode's action */
    public function withAlertBinary(string $path): self
    {
        if (trim($path) !== '') {
            $this->alertBinary = trim($path);
        }

        return $this;
    }

    /**
     * A jail's `action` line for the chosen mode
     *
     * `%(action_)s` is fail2ban's standard action, the one that commands the
     * firewall. Notify mode uses our own action instead, which has no command that
     * touches the firewall at all.
     *
     * **One percent sign, not two** — this value is passed into `sprintf` as an
     * *argument*, not as part of the format, so `%%` is never reduced to `%` for it.
     * Write two here and the file ends up with `%%(action_)s`, which fail2ban's
     * config parser reads as literal text rather than an action name, breaking that
     * jail entirely. (Inside an actual `sprintf` format string it does need two — that
     * difference is what makes this easy to get wrong when the line is pulled out
     * into its own method.)
     */
    private function actionLine(string $mode): string
    {
        return $mode === self::MODE_NOTIFY
            ? 'action   = ' . self::NOTIFY_ACTION
            : 'action   = %(action_)s';
    }

    /**
     * Content of the "notify only" action
     *
     * No `actionstart`/`actionstop`/`actionunban` because there's nothing to
     * prepare or undo — it never creates a chain and never touches the firewall.
     * `actionban` alone is the whole thing it does.
     *
     * Calls `phpcp-alert`, a program deliberately kept narrow already: send a
     * message and stop, touching nothing else on the system. fail2ban runs as root,
     * so it can call it directly.
     */
    private function notifyActionContent(): string
    {
        return sprintf(<<<'CONF'
            # Generated by phpcp — do not edit by hand. Rewritten every time settings are saved from the panel.
            #
            # An action that **never touches the firewall** — used by jails in "notify only"
            # mode. The admin reads the message and decides whether to ban.
            [Definition]
            actionstart =
            actionstop =
            actioncheck =
            actionban = %s jail-hit "<name>" "<ip>" "<failures>"
            actionunban =

            [Init]
            name = default
            CONF, $this->alertBinary) . "\n";
    }

    /**
     * Writes the notify-mode action file — always, not only when that mode is in use
     *
     * **Why always:** the jail file and the action file are committed together. If it
     * were only written while in notify mode, an admin switching one jail back to ban
     * could leave the action file orphaned, or have it deleted while another jail
     * still depends on it. A file nothing references does nothing; a file missing
     * while something references it stops fail2ban from starting at all.
     */
    private function writeNotifyAction(ConfigTransaction $tx, string $mode): void
    {
        $tx->write(self::ACTION_DIR . '/' . self::NOTIFY_ACTION . '.conf', $this->notifyActionContent(), 0644);
    }

    /**
     * Combines every exemption that applies to one jail
     *
     * Ordered from "never ban, full stop" down to "the admin's own entry" — localhost
     * always comes first.
     */
    private function ignoreList(string $extra): string
    {
        return trim(preg_replace(
            '/\s+/',
            ' ',
            self::LOCAL_IPS . ' ' . $this->neverBan . ' ' . trim($extra),
        ) ?? self::LOCAL_IPS);
    }

    /**
     * Turns on or adjusts one site's rate limit
     *
     * @param array{max_requests:int,window_seconds:int,ban_seconds:int,ignore_ips:string} $settings
     */
    public function apply(Site $site, array $settings): void
    {
        $this->assertRunning();

        $name = $this->jailName($site);

        $tx = new ConfigTransaction($this->executor);
        $this->writeNotifyAction($tx, (string) ($settings['mode'] ?? self::MODE_BAN));
        $tx->write($this->filterPath($name), $this->filterContent(), 0644);
        $tx->write($this->jailPath($name), $this->jailContent($name, $site, $settings), 0644);

        // Confirm fail2ban can actually read what was just written before letting it
        // stand — a broken regex stops fail2ban from starting at all next time, which
        // means the SSH jail guarding against password guessing disappears with it.
        //
        // **Must be `-t`, the program's own flag, not `--test get <jail> ...`** — that
        // form was tried and failed every time (`NOK: ('phpcp-xxx',)`) because `get`
        // asks **the already-running server** what a jail's value is, and it doesn't
        // yet know about a jail that was just written to disk and not reloaded. `-t`
        // reads the whole config straight from disk instead, which is exactly what's
        // needed here.
        $tx->commit(function (): array {
            $test = $this->executor->exec([$this->client(), '-t'], timeout: 30);

            return [
                $test->ok(),
                // `-t` validates config for **the whole machine** — a failure could
                // come from some other jail that was already broken, not the file
                // just written. The admin needs to know that, or they'll go hunting
                // for a mistake in a file that was correct all along.
                "fail2ban's config check failed (checks the whole machine, not only this file)\n\n"
                . trim($test->stderr ?: $test->output()),
            ];
        });

        $this->stopJail($name);
        $this->reload();
    }

    /** Turns off one site's rate limit — deletes both files and has fail2ban forget the jail */
    public function remove(Site $site): void
    {
        $name = $this->jailName($site);

        if (!$this->executor->exists($this->executor->path($this->jailPath($name)))) {
            return;   // Never turned on — not an error
        }

        $this->assertRunning();

        $tx = new ConfigTransaction($this->executor);
        $tx->delete($this->jailPath($name));
        $tx->delete($this->filterPath($name));
        $tx->commitWithoutValidation();

        $this->stopJail($name);
        $this->reload();
    }

    /**
     * Turns on or adjusts login brute-force protection for the panel's own login page
     *
     * **Why this exists despite the app already having account lockout** — lockout
     * only stops one account at a time; someone cycling through `admin`, `root`,
     * `administrator` is never caught by it at all, and every attempt still costs one
     * of only four PHP-FPM workers. A ban at the firewall cuts them off before they
     * ever reach PHP.
     *
     * @param string $auditLogPath the text copy of the audit log ({@see \Phpcp\Kernel\Paths::logFile()})
     * @param array{max_retry:int,find_seconds:int,ban_seconds:int,ignore_ips:string} $settings
     */
    public function applyPanelLogin(string $auditLogPath, array $settings): void
    {
        $this->assertRunning();

        $tx = new ConfigTransaction($this->executor);
        $this->writeNotifyAction($tx, (string) ($settings['mode'] ?? self::MODE_BAN));
        $tx->write($this->filterPath(self::PANEL_LOGIN_JAIL), $this->panelLoginFilter(), 0644);
        $tx->write($this->jailPath(self::PANEL_LOGIN_JAIL), $this->panelLoginJail($auditLogPath, $settings), 0644);

        $tx->commit(function (): array {
            $test = $this->executor->exec([$this->client(), '-t'], timeout: 30);

            return [
                $test->ok(),
                "fail2ban's config check failed (checks the whole machine, not only this file)\n\n"
                . trim($test->stderr ?: $test->output()),
            ];
        });

        $this->stopJail(self::PANEL_LOGIN_JAIL);
        $this->reload();
    }

    /** Turns off login brute-force protection */
    public function removePanelLogin(): void
    {
        if (!$this->executor->exists($this->executor->path($this->jailPath(self::PANEL_LOGIN_JAIL)))) {
            return;
        }

        $this->assertRunning();

        $tx = new ConfigTransaction($this->executor);
        $tx->delete($this->jailPath(self::PANEL_LOGIN_JAIL));
        $tx->delete($this->filterPath(self::PANEL_LOGIN_JAIL));
        $tx->commitWithoutValidation();

        $this->stopJail(self::PANEL_LOGIN_JAIL);
        $this->reload();
    }

    /**
     * The real status from fail2ban — how many IPs are banned right now
     *
     * Read from fail2ban itself, not the panel's database, because the two can
     * disagree: an admin might run `fail2ban-client unban` by hand from the command
     * line, or fail2ban might not have loaded that jail because a file was wrong. The
     * screen has to say what's actually true on the machine.
     *
     * @return array{active:bool,banned:int,total_banned:int,failed:int}
     */
    public function status(Site $site): array
    {
        return $this->statusOf($this->jailName($site));
    }

    /**
     * Status of a jail named directly — same as {@see self::status()} but not tied to a site
     *
     * @return array{active:bool,banned:int,total_banned:int,failed:int}
     */
    public function statusOf(string $jail): array
    {
        $result = $this->executor->exec([$this->client(), 'status', $jail], timeout: 15);

        if (!$result->ok()) {
            // The jail doesn't exist = never turned on, not an error
            return ['active' => false, 'banned' => 0, 'total_banned' => 0, 'failed' => 0];
        }

        $output = $result->output();

        return [
            'active' => true,
            'banned' => $this->number($output, 'Currently banned'),
            'total_banned' => $this->number($output, 'Total banned'),
            'failed' => $this->number($output, 'Currently failed'),
        ];
    }

    /**
     * Unbans one IP from one site
     *
     * Has to exist because bad bans happen for real and reach the whole machine
     * (fail2ban commands the firewall, which knows nothing about vhosts) — without a
     * way to unban from the web UI, an admin who banned themselves couldn't reach the
     * panel at all and would have to find some other way onto the machine.
     */
    public function unban(Site $site, string $ip): void
    {
        $this->unbanFrom($this->jailName($site), $ip);
    }

    /** Unbans one IP from a jail named directly */
    public function unbanFrom(string $jail, string $ip): void
    {
        $this->assertIp($ip);

        $result = $this->executor->exec(
            [$this->client(), 'set', $jail, 'unbanip', $ip],
            timeout: 15,
        );

        if (!$result->ok()) {
            throw new ExecutionFailed('Failed to unban IP: ' . trim($result->stderr ?: $result->output()));
        }
    }

    /** @return list<string> IPs currently banned */
    public function bannedIps(Site $site): array
    {
        return $this->bannedIpsOf($this->jailName($site));
    }

    /** @return list<string> IPs currently banned in a jail named directly */
    public function bannedIpsOf(string $jail): array
    {
        $result = $this->executor->exec(
            [$this->client(), 'get', $jail, 'banip'],
            timeout: 15,
        );

        if (!$result->ok()) {
            return [];
        }

        return array_values(array_filter(preg_split('/\s+/', trim($result->output())) ?: []));
    }

    /**
     * One site's jail name
     *
     * Built from `system_user` because it's already pattern-validated to be safe as
     * a filename (see `Site::assertSystemUser`) — unlike the domain name, which has
     * dots in it and can exceed length limits.
     */
    public function jailName(Site $site): string
    {
        return self::PREFIX . $site->systemUser();
    }

    /**
     * Filter content — catches **every request**, not only failed ones
     *
     * Unlike a typical fail2ban jail, which catches "wrong password" and counts
     * that, this one counts every request from one IP and leaves `maxretry`/`findtime`
     * in the jail file to decide whether that's too fast — which is what a rate limit
     * means.
     *
     * `<HOST>` is fail2ban's token standing for an IP (IPv4/IPv6 both) and must
     * always be the first group. The log format supported is `combined`, which is
     * what both Apache and nginx are configured to write on this panel.
     */
    private function filterContent(): string
    {
        return <<<'CONF'
            # Generated by phpcp — do not edit by hand. Rewritten every time settings are saved from the panel.
            #
            # Catches every request from one IP, and leaves maxretry/findtime in the jail file
            # to decide whether that's too fast — unlike a typical jail that only catches failed logins.
            [Definition]
            failregex = ^<HOST> -.*"(GET|POST|HEAD|PUT|DELETE|PATCH|OPTIONS).*"
            ignoreregex =
            # Set explicitly even though auto is already the default — otherwise fail2ban warns
            # on every reload that "'allowipv6' not defined", filling the journal with the same
            # line over and over and burying whatever the real error is (same reason agentd
            # disables pcre.jit)
            allowipv6 = auto
            datepattern = ^[^\[]*\[({DATE})
                          {^LN-BEG}
            CONF . "\n";
    }

    /**
     * One site's jail content
     *
     * @param array{max_requests:int,window_seconds:int,ban_seconds:int,ignore_ips:string} $settings
     */
    private function jailContent(string $name, Site $site, array $settings): string
    {
        // The log path must go through executor->path() so sandbox mode points at its own prefix
        $logPath = $this->executor->path($site->accessLog());

        // **localhost must never be banned, whatever the user enters** — three reasons:
        //   1. requests from the machine itself are health checks, the site's own cron,
        //      and the panel itself
        //   2. banning 127.0.0.1 cuts off the very panel page an admin would use to fix things
        //   3. no benefit either way: someone who can already send requests from
        //      localhost has already reached the machine
        //
        // Has to be added explicitly, because Debian's `jail.conf` **comments `ignoreip`
        // out** (`#ignoreip = 127.0.0.1/8 ::1`) — there's no default exemption at all
        $ignore = $this->ignoreList($settings['ignore_ips']);

        return sprintf(
            <<<'CONF'
                # Generated by phpcp for website %s — do not edit by hand
                #
                # The ban applies to **the whole machine**, not only this site, because fail2ban
                # commands the firewall, which knows nothing about vhosts — an IP banned for
                # hammering this site can't reach any other site on this machine either.
                [%s]
                enabled  = true
                filter   = %s
                # **Backend must be set explicitly** — Debian/Ubuntu set `backend = systemd` in
                # [DEFAULT] of /etc/fail2ban/jail.d/defaults-debian.conf, which makes fail2ban
                # read from the systemd journal instead and **ignore logpath entirely**. The jail
                # looks completely normal but never counts a single request, with nothing to say so.
                backend  = auto
                logpath  = %s
                # %d requests within %d seconds = over the threshold
                maxretry = %d
                findtime = %d
                bantime  = %d
                # No port specified, so every port gets banned — someone who got banned for
                # hammering this site shouldn't be able to talk to this machine at all
                %s
                %s
                CONF,
            $site->domain,
            $name,
            $name,
            $logPath,
            $settings['max_requests'],
            $settings['window_seconds'],
            $settings['max_requests'],
            $settings['window_seconds'],
            $settings['ban_seconds'],
            $this->actionLine((string) ($settings['mode'] ?? self::MODE_BAN)),
            $ignore === '' ? '' : 'ignoreip = ' . $ignore,
        ) . "\n";
    }

    /**
     * The login page's filter content — catches only **failed** authentication
     *
     * Unlike the per-site filter, which counts every request, here a successful
     * login must never be counted at all.
     *
     * ### Why the regex anchors on `"user_id":\d+,"ip":"`, not just `"ip":"`
     *
     * `actor` on the same line is **the username the caller typed in themselves**.
     * Someone who knows this log is being read could set their username to
     * `evil","ip":"9.9.9.9` to have fail2ban ban someone else's IP instead of their
     * own. `json_encode` already escapes quote marks to `\"`, so the forged text
     * wouldn't line up with `"ip":"` on its own anyway — but anchoring on `user_id`,
     * which is **a raw integer**, can't be forged at all, so this doesn't have to
     * lean on the details of that escaping.
     *
     * Proven with `fail2ban-regex` on the real machine: a line attempting this forgery
     * resolves to the caller's real IP, not the one planted in the username.
     *
     * `action` and `result` always come **after** `ip`, following the key order
     * `AuditLog::mirror()` writes — that order is part of the contract, not
     * incidental.
     */
    private function panelLoginFilter(): string
    {
        return <<<'CONF'
            # Generated by phpcp — do not edit by hand. Rewritten every time settings are saved from the panel.
            #
            # Catches failed logins and failed two-factor checks from the JSON copy of the audit log.
            # Anchors on "user_id":<number>,"ip":" because that number can't be forged through the username.
            [Definition]
            failregex = "user_id":\d+,"ip":"<HOST>",.*"action":"auth\.(?:login|2fa)",.*"result":"denied"
            ignoreregex =
            # Set explicitly even though auto is already the default — otherwise fail2ban warns
            # on every reload that "'allowipv6' not defined", filling the journal with the same
            # line over and over
            allowipv6 = auto
            datepattern = ^\{"ts":"({DATE})
                          {^LN-BEG}
            CONF . "\n";
    }

    /**
     * The login page's jail content
     *
     * @param array{max_retry:int,find_seconds:int,ban_seconds:int,ignore_ips:string} $settings
     */
    private function panelLoginJail(string $auditLogPath, array $settings): string
    {
        $ignore = $this->ignoreList($settings['ignore_ips']);

        return sprintf(
            <<<'CONF'
                # Generated by phpcp for the panel's own login page — do not edit by hand
                #
                # **The ban blocks every port, including the panel's own port** — an admin who
                # mistypes their password past the threshold can't reach the control panel until
                # the ban expires. If that happens to you, reach the machine over SSH and run:
                #   sudo fail2ban-client set %s unbanip <ip>
                # then put your own regular IP in the "exempt IPs" field so it doesn't happen again
                [%s]
                enabled  = true
                filter   = %s
                # Must be set explicitly — Debian/Ubuntu set backend = systemd in [DEFAULT], which
                # makes fail2ban read the journal and ignore logpath entirely. The jail looks
                # completely normal but never counts anything, with nothing to say so.
                backend  = auto
                logpath  = %s
                # %d failed logins within %d seconds = banned
                maxretry = %d
                findtime = %d
                bantime  = %d
                %s
                %s
                CONF,
            self::PANEL_LOGIN_JAIL,
            self::PANEL_LOGIN_JAIL,
            self::PANEL_LOGIN_JAIL,
            $auditLogPath,
            $settings['max_retry'],
            $settings['find_seconds'],
            $settings['max_retry'],
            $settings['find_seconds'],
            $settings['ban_seconds'],
            $this->actionLine((string) ($settings['mode'] ?? self::MODE_BAN)),
            $ignore === '' ? '' : 'ignoreip = ' . $ignore,
        ) . "\n";
    }

    private function filterPath(string $name): string
    {
        return self::FILTER_DIR . '/' . $name . '.conf';
    }

    private function jailPath(string $name): string
    {
        return self::JAIL_DIR . '/' . $name . '.conf';
    }

    private function client(): string
    {
        return BinaryPath::resolve($this->executor, self::CLIENT_PATHS, 'fail2ban');
    }

    /**
     * fail2ban has to be running before any file gets touched
     *
     * Skip this check and a write can succeed while reporting "rate limit turned on"
     * with nothing actually enforcing it — more dangerous than not having the feature
     * at all, because the admin believes the site is protected.
     */
    private function assertRunning(): void
    {
        $result = $this->executor->exec([$this->client(), 'ping'], timeout: 15);

        if (!$result->ok()) {
            throw new ExecutionFailed(
                "fail2ban is not running, so the rate limit can't be enforced yet\n\n"
                . "Check with `sudo systemctl status fail2ban` then run `sudo systemctl start fail2ban`\n"
                . 'If it is not installed yet: `sudo apt install fail2ban`',
            );
        }
    }

    /**
     * Stops one jail before having fail2ban reload its config
     *
     * **`reload` alone does not change a running jail's action** — measured on a real
     * machine: switching a jail's mode from notify to ban and running `reload` (both
     * the whole-service form and the per-jail form) left `fail2ban-client get <jail>
     * actions` answering "No actions for jail" — the jail kept counting hits, but
     * nothing at all was wired up to act on them.
     *
     * That means an admin switching from "notify" to "ban" would believe the machine
     * is now enforcing it, while nobody is actually being banned — the same class of
     * false security this system has been closing off throughout.
     *
     * `stop <jail>` followed by `reload` fixes it, and only touches that one jail,
     * unlike restarting the whole service, which clears the ban list of every jail,
     * including SSH's, which isn't ours to touch.
     *
     * Failing here is fine — a jail that never existed can't be stopped either, and
     * that's not an error.
     */
    private function stopJail(string $name): void
    {
        $this->executor->exec([$this->client(), 'stop', $name], timeout: 15);
    }

    /**
     * Tells fail2ban to reload its config
     *
     * Uses `reload`, not `restart` — restart clears every jail's ban list, including
     * the SSH jail that guards against password guessing. Someone actively hammering
     * SSH would get a free pardon every time anyone saves one site's settings.
     */
    private function reload(): void
    {
        $result = $this->executor->exec([$this->client(), 'reload'], timeout: 30);

        if (!$result->ok()) {
            throw new ExecutionFailed(
                'The config was written, but telling fail2ban to reload it failed: '
                . trim($result->stderr ?: $result->output())
                . "\n\nThe file on disk is correct — run `sudo fail2ban-client reload` yourself",
            );
        }
    }

    /** Is the fail2ban service answering — used to inform the screen, not as a guard like assertRunning() */
    public function isRunning(): bool
    {
        return $this->executor->exec([$this->client(), 'ping'], timeout: 10)->ok();
    }

    /**
     * Memory fail2ban is actually using right now, in MB — 0 when it isn't running
     *
     * Shown on screen next to the off switch, because "what do I get back by turning
     * this off" is a question an admin running a small machine actually asks — a
     * number read from the machine itself is more believable than one written in a
     * manual somewhere.
     */
    public function memoryUsageMb(): int
    {
        $result = $this->executor->exec(['/bin/ps', '-o', 'rss=', '-C', 'fail2ban-server'], timeout: 10);

        if (!$result->ok()) {
            return 0;
        }

        $kb = 0;
        foreach (preg_split('/\R/', trim($result->output())) ?: [] as $line) {
            $kb += (int) trim($line);
        }

        return (int) round($kb / 1024);
    }

    /** Pulls a number out of `fail2ban-client status` output, which is plain text */
    private function number(string $output, string $label): int
    {
        return preg_match('/' . preg_quote($label, '/') . ':\s*(\d+)/', $output, $m) === 1
            ? (int) $m[1]
            : 0;
    }

    /** An IP submitted from a form must be a real IP, not text that becomes some other argument */
    private function assertIp(string $ip): void
    {
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            throw new ValidationError('Invalid IP format');
        }
    }

    /**
     * Validates the exempt-IP list submitted from the panel
     *
     * Accepts single IPs and CIDR ranges, space- or comma-separated, and returns the
     * form fail2ban can read. A malformed value has to be rejected right where it's
     * entered, not later when fail2ban fails to start and takes the SSH jail down
     * with it.
     */
    public static function normalizeIgnoreList(string $raw): string
    {
        $items = preg_split('/[\s,]+/', trim($raw)) ?: [];
        $clean = [];

        foreach ($items as $item) {
            if ($item === '') {
                continue;
            }

            [$address, $bits] = array_pad(explode('/', $item, 2), 2, null);

            if (filter_var($address, FILTER_VALIDATE_IP) === false) {
                throw new ValidationError("Invalid exempt IP: {$item}");
            }

            if ($bits !== null) {
                $max = str_contains((string) $address, ':') ? 128 : 32;

                if (!ctype_digit($bits) || (int) $bits < 0 || (int) $bits > $max) {
                    throw new ValidationError("Invalid network size: {$item}");
                }
            }

            $clean[] = $item;
        }

        if (count($clean) > 64) {
            throw new ValidationError('At most 64 exempt IPs are allowed');
        }

        return implode(' ', $clean);
    }
}
