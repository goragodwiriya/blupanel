<?php

declare(strict_types=1);

namespace Phpcp\Driver\Ssh;

use Phpcp\Agent\Capability\ServiceProbe;
use Phpcp\Agent\ExecutionFailed;
use Phpcp\Agent\Executor\Executor;
use Phpcp\Agent\ValidationError;
use Phpcp\Domain\UserAccount;
use Phpcp\Driver\ConfigTransaction;
use Phpcp\Support\BinaryPath;

/**
 * Enable/disable SFTP file access for hosting accounts — PLAN-V2 Phase E4
 *
 * **One hosting account = one SFTP login** (see full reasoning in `db/migrations/0013_sftp_access.sql`)
 * No per-site sub-accounts, because every site belonging to the same user already shares one uid
 * since 0006
 *
 * **SFTP, not FTP — deliberately** — runs on OpenSSH, which is already installed and hardened.
 * No extra daemon to run, no extra port to open, encrypted end-to-end, and none of FTP's
 * passive port range problem that forces opening a wide port range on the firewall
 *
 * **Three layers that prevent this from becoming shell access:**
 *   1. `ForceCommand internal-sftp` — forces SFTP-only, even if the client requests a shell
 *   2. the account's shell stays `/usr/sbin/nologin` as-is (untouched) — a fallback layer
 *      in case the `Match` block ever disappears for any reason
 *   3. `ChrootDirectory` cages the account inside its own home, invisible even to the
 *      machine's own `/etc/passwd`
 *
 * **Why write a separate file under `sshd_config.d/` instead of touching the main
 * `sshd_config`:** the main file is the one thing that, if broken, locks everyone out of the
 * machine entirely ({@see \Phpcp\Driver\SshManager} therefore only permits editing 5 keys)
 * · a separate file can be deleted to instantly restore the previous state, and a broken
 * `Match` block only affects its own group
 *
 * **Trap to know about: `Match` scope leaks across files** — every directive following
 * `Match` stays in that scope indefinitely, including lines in **other files included
 * afterward** and in the main file, unless the scope is closed · the file this class writes
 * therefore always ends with `Match all` to return the scope to global — otherwise the rest
 * of the machine's settings would silently fall under the SFTP group's scope with nobody
 * noticing
 */
final class SftpAccessManager
{
    /** The file this class owns entirely — nothing else writes here */
    public const CONFIG_FILE = '/etc/ssh/sshd_config.d/phpcp-sftp.conf';

    /** The group `Match Group` matches — only accounts in this group can access SFTP */
    public const GROUP = 'phpcp-sftp';

    /** @var list<string> systemctl lives at /usr/bin on Debian/Ubuntu · /usr/sbin on some systems */
    public const SYSTEMCTL_PATHS = ['/usr/bin/systemctl', '/usr/sbin/systemctl'];

    private const GROUPADD = '/usr/sbin/groupadd';
    private const USERMOD = '/usr/sbin/usermod';
    private const GPASSWD = '/usr/bin/gpasswd';
    private const CHPASSWD = '/usr/sbin/chpasswd';
    private const SSHD = '/usr/sbin/sshd';
    private const MKDIR = '/usr/bin/mkdir';

    public function __construct(private readonly Executor $executor)
    {
    }

    /**
     * Enable SFTP for this account and set its password — idempotent (also used to change the password)
     *
     * @return array{username:string,chroot:string,group:string,config_written:bool}
     */
    public function enable(UserAccount $account, string $password): array
    {
        $this->assertPassword($password);

        $activation = $this->assertSshdRunning();
        $this->ensureGroup();
        $this->ensureConfig();

        // reload sits outside ensureConfig() deliberately — that method has an early return
        // when the file already matches. If reload were moved inside, the case "file write
        // succeeded but reload failed on a previous attempt" could never be fixed, because
        // retrying would skip the whole block · reload is cheap and side-effect-free, so
        // it's worth calling every time
        $this->reloadSshd($activation);

        // Add to the group before setting the password — if it fails partway through, an
        // account with a password but not in the group still can't access anything (shell
        // is nologin), which is safer than the reverse order
        $this->addToGroup($account);
        $this->setPassword($account, $password);

        return [
            'username' => $account->username,
            'chroot' => $account->home(),
            'group' => self::GROUP,
            'config_written' => true,
        ];
    }

    /**
     * Disable SFTP — remove from the group and lock the password
     *
     * Doesn't delete the config file because other accounts may still use it · doesn't
     * delete the system account because the user's site still needs to keep running under
     * that uid
     */
    public function disable(UserAccount $account): array
    {
        $this->removeFromGroup($account);
        $this->lockPassword($account);

        return ['username' => $account->username, 'group' => self::GROUP];
    }

    /**
     * sshd must already be running before SFTP can be enabled — SFTP runs on sshd, not its
     * own daemon
     *
     * **Found through testing on a real server (2026-08-10):** on a machine where sshd
     * wasn't running, `sshd -t` failed with *"Missing privilege separation directory:
     * /run/sshd"*, because `/run/sshd` is only created by systemd when the service starts
     * (`RuntimeDirectory=sshd`) · the result was that the admin saw "the generated
     * configuration failed validation," which pointed in the wrong direction, even though
     * the config phpcp wrote was correct on every line — the real cause must be reported
     * before ever touching the file
     *
     * @return array{unit:string,socket:bool} the unit found running, and whether it came from a socket
     */
    private function assertSshdRunning(): array
    {
        // Unit name differs by distro: Debian/Ubuntu use `ssh`, RHEL uses `sshd`
        foreach (['ssh', 'sshd'] as $unit) {
            $status = ServiceProbe::read($this->executor, $unit);

            if ($status['running'] ?? false) {
                // Socket activation is the default on Ubuntu 22.10+ — the `.service` unit
                // staying inactive the whole time is normal · see ServiceProbe::withSocketActivation()
                return ['unit' => $unit, 'socket' => ($status['activation'] ?? '') === 'socket'];
            }
        }

        throw new ExecutionFailed(
            "Can't enable SFTP because the SSH service isn't running — SFTP runs on sshd, not a separate service\n\n"
            . "Enable it first with:\n"
            . "    sudo systemctl enable --now ssh\n\n"
            . 'then try enabling SFTP again',
        );
    }

    /**
     * `/run/sshd` must exist before `sshd -t` will pass
     *
     * It's the privilege separation directory that systemd normally creates via
     * `RuntimeDirectory=sshd` when `ssh.service` starts · **on machines using socket
     * activation, that service never runs as a long-lived process**, so the directory can
     * disappear at any time, and `sshd -t` fails with *"Missing privilege separation
     * directory"* even though the config file is correct on every line
     *
     * Create it ourselves, idempotently, before testing — systemd takes over once the
     * service is actually running (0755 root:root is the same permission systemd itself
     * sets, nothing relaxed here)
     */
    private function ensureRuntimeDir(): void
    {
        $this->executor->exec([self::MKDIR, '-m', '0755', '-p', '/run/sshd'], timeout: 10);
    }

    /** The group must exist before `Match Group` means anything — created lazily on first use */
    private function ensureGroup(): void
    {
        $result = $this->executor->exec([self::GROUPADD, '--system', '-f', self::GROUP], timeout: 15);

        // -f = already existing counts as success · exit 9 is older groupadd's own "already exists"
        if (!$result->ok() && $result->exitCode !== 9) {
            throw new ExecutionFailed('Failed to create group ' . self::GROUP . ': ' . trim($result->stderr));
        }
    }

    /**
     * Write the config file and verify it with `sshd -t` before it's allowed to stick around
     *
     * Goes through {@see ConfigTransaction} so the previous file is restored automatically
     * if `sshd -t` fails — a broken sshd file left in place means sshd can't restart next
     * time, and then nobody can get into the machine at all
     */
    private function ensureConfig(): void
    {
        if ($this->executor->exists($this->executor->path(self::CONFIG_FILE))
            && $this->executor->readFile($this->executor->path(self::CONFIG_FILE)) === $this->configContent()) {
            return;   // Already matches what's wanted — no need to touch sshd at all
        }

        $this->assertIncludeSupported();

        $tx = new ConfigTransaction($this->executor);
        $tx->write(self::CONFIG_FILE, $this->configContent(), 0644);

        $tx->commit(function (): array {
            $this->ensureRuntimeDir();

            $test = $this->executor->exec([self::SSHD, '-t'], timeout: 20);

            return [$test->ok(), $this->explainSshdTest(trim($test->output() . $test->stderr))];
        });

    }

    /**
     * sshd must re-read the config, otherwise the file just written has no effect at all
     *
     * **Found through testing on a real server (2026-08-10):** a comment here used to claim
     * "new connections read the new config on their own" — which is **wrong**. sshd reads
     * `sshd_config` only at start, then forks child processes from that in-memory image to
     * handle each connection · the result was that a freshly written `Match Group` block was
     * completely ignored: the user got the `nologin` shell instead of `internal-sftp`, the
     * sftp client saw the "This account is currently not available" text as a broken
     * protocol response and reported *"Received message too long"* — pointing entirely in
     * the wrong direction from the real cause
     *
     * Uses `reload` (SIGHUP), not `restart` — re-reads the config without dropping active
     * connections, which matters a lot since the admin may be ssh'd in through that very
     * same session
     *
     * **Except on machines using socket activation** (the default on Ubuntu 22.10+), where
     * there's no long-lived sshd process to reload at all — `systemd` spins up a fresh
     * `ssh@<n>.service` per connection, so each one naturally reads `sshd_config` fresh
     * already · running `systemctl reload ssh.service` on such a machine always fails
     * ("Job type reload is not applicable"), which would turn into an error message the
     * admin chases without anything actually improving
     *
     * @param array{unit:string,socket:bool} $activation result from assertSshdRunning()
     */
    private function reloadSshd(array $activation): void
    {
        if ($activation['socket']) {
            return;   // The next connection already gets the new config — nothing to wake up
        }

        $systemctl = BinaryPath::resolve($this->executor, self::SYSTEMCTL_PATHS, 'systemd');

        foreach (['ssh', 'sshd'] as $unit) {
            $result = $this->executor->exec([$systemctl, 'reload', $unit], timeout: 20);

            if ($result->ok()) {
                return;
            }
        }

        throw new ExecutionFailed(
            "Config file written, but telling sshd to reload it failed\n\n"
            . "The file on disk is already correct — run `sudo systemctl reload ssh` yourself "
            . 'and SFTP will work immediately without needing to be reconfigured',
        );
    }

    /**
     * Translate the `sshd -t` result into something actionable, instead of just passing the
     * raw message along
     *
     * The "Missing privilege separation directory" message points at the environment (sshd
     * isn't running), not at the config file that was just written — without translating it,
     * the admin would go chasing a bug in a file that's already correct
     */
    private function explainSshdTest(string $raw): string
    {
        if (str_contains($raw, 'Missing privilege separation directory')) {
            return $raw . "\n\nThis message means /run/sshd doesn't exist, which happens when the "
                . "SSH service isn't running (systemd only creates this directory at start)\n"
                . 'It is not a problem with the config file the system just wrote — enable the service with `sudo systemctl enable --now ssh` and try again';
        }

        return 'sshd -t: ' . $raw;
    }

    /**
     * The main `sshd_config` needs an `Include` line before it will read the file we write
     *
     * Ubuntu 22.04+ / Debian 12+ ship it by default, but a hand-configured machine might not
     * have it — if the file were written and just left there with nobody reading it, the
     * admin would see "SFTP enabled successfully" but be unable to actually log in, with no
     * way to find out why — this must be reported clearly now instead of failing silently
     */
    private function assertIncludeSupported(): void
    {
        $main = $this->executor->path(\Phpcp\Driver\SshManager::CONFIG);

        if (!$this->executor->exists($main)) {
            throw new ExecutionFailed('Cannot find ' . \Phpcp\Driver\SshManager::CONFIG . ' — this machine may not have OpenSSH server installed');
        }

        $content = $this->executor->readFile($main);

        // Matches `Include /etc/ssh/sshd_config.d/*.conf` regardless of whitespace or case
        if (preg_match('/^\s*Include\s+.*sshd_config\.d/mi', $content) !== 1) {
            throw new ExecutionFailed(
                "This machine's sshd_config has no Include line for /etc/ssh/sshd_config.d/\n\n"
                . "Add this line at the **top** of /etc/ssh/sshd_config and try again:\n"
                . "    Include /etc/ssh/sshd_config.d/*.conf\n\n"
                . 'It must be at the top because OpenSSH uses the first value it finds for each key',
            );
        }
    }

    /**
     * Config file content — always constant, doesn't depend on any specific user
     *
     * Uses `%u` so OpenSSH substitutes the username itself, so the file never needs to be
     * rewritten when an account is added (and no customer username ever appears in the
     * system's config file)
     */
    private function configContent(): string
    {
        $usersDir = rtrim(\Phpcp\Kernel\Paths::usersDir(), '/');

        return <<<CONF
            # Managed automatically by phpcp — do not edit by hand (PLAN-V2 Phase E4)
            # Enable/disable SFTP per account is done through the panel's user interface

            Match Group {$this->groupName()}
                # Chroot at the **parent** directory, not the user's home — deliberate, not sloppy
                #
                # OpenSSH requires ChrootDirectory to be owned by root and not writable by
                # group/other, but the user's home must be owned by the user (writable) and
                # its group must be www-data so the web server can traverse down to the
                # docroot — these two requirements directly conflict
                #
                # Forcing the home directory's owner to root would force a choice: the whole
                # site returns 403 (www-data can't traverse it), or other gets opened to r-x
                # (other customers can see each other's home directory layout — a drop in
                # the security tier forbidden by §7.1 item 2)
                #
                # The parent directory has been root:root 0711 since AccountProvisioner::createHome()
                # already, so it meets OpenSSH's requirement exactly with nothing to change
                # · 0711 also means a user running `ls /` sees nothing — they can't even
                # tell other customers exist on the machine
                ChrootDirectory {$usersDir}
                # Force SFTP only, even if a shell is requested · internal-sftp is required
                # for chroot, because an external sftp-server binary would sit outside the
                # chroot and be unreachable
                #
                # -d /%u drops the user straight into their own home right after login (path
                # relative to the chroot), so the user never needs to know they're under a
                # shared parent directory, and can't cd their way out anyway
                ForceCommand internal-sftp -d /%u
                # Cut off everything that isn't file transfer — prevents SFTP from becoming
                # a route into the internal network
                AllowTcpForwarding no
                AllowAgentForwarding no
                AllowStreamLocalForwarding no
                PermitTunnel no
                X11Forwarding no
                # Most customers use a password with their FTP client program — enabled
                # only for this group, doesn't affect the machine-wide PasswordAuthentication
                # setting the admin has configured
                PasswordAuthentication yes

            # Return scope to global — **do not delete this line**
            # Every directive following Match stays in that scope indefinitely, across
            # files too, unless closed — if left unclosed, the rest of the machine's
            # settings would silently fall under the SFTP group's scope with nobody noticing
            Match all

            CONF;
    }

    private function groupName(): string
    {
        return self::GROUP;
    }

    private function addToGroup(UserAccount $account): void
    {
        $result = $this->executor->exec(
            [self::USERMOD, '-a', '-G', self::GROUP, $account->username],
            timeout: 15,
        );

        if (!$result->ok()) {
            throw new ExecutionFailed('Failed to add account to the SFTP group: ' . trim($result->stderr));
        }
    }

    private function removeFromGroup(UserAccount $account): void
    {
        // Can fail if the account isn't in the group already — treated as the desired outcome (already disabled)
        $this->executor->exec([self::GPASSWD, '-d', $account->username, self::GROUP], timeout: 15);
    }

    /**
     * Set the password via `chpasswd`'s stdin, not an argument
     *
     * A password passed as an argument can be read from `/proc/<pid>/cmdline` by other
     * users on the same machine — the same reason `SshDestination` doesn't support
     * password auth
     */
    private function setPassword(UserAccount $account, string $password): void
    {
        $result = $this->executor->exec(
            [self::CHPASSWD],
            timeout: 15,
            stdin: $account->username . ':' . $password . "\n",
        );

        if (!$result->ok()) {
            throw new ExecutionFailed('Failed to set the SFTP password: ' . trim($result->stderr));
        }
    }

    /** A `!` prefix on the hash makes the password unusable without deleting the hash (can be re-enabled with a new password) */
    private function lockPassword(UserAccount $account): void
    {
        $this->executor->exec([self::USERMOD, '--lock', $account->username], timeout: 15);
    }

    /**
     * SFTP password rules — stricter than the default because this is an account with
     * direct file access, exposed to password login from the internet (unlike panel
     * accounts, which have their own rate limiting and 2FA — sshd offers neither of those)
     */
    private function assertPassword(string $password): void
    {
        if (mb_strlen($password) < 12) {
            throw new ValidationError('SFTP password must be at least 12 characters long');
        }

        // Control characters and : break the `user:password` format that chpasswd reads from stdin
        if (preg_match('/[\x00-\x1F\x7F:]/', $password) === 1) {
            throw new ValidationError('SFTP password must not contain a : character or control characters');
        }
    }
}
