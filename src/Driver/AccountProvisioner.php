<?php

declare(strict_types=1);

namespace Phpcp\Driver;

use Phpcp\Agent\ExecutionFailed;
use Phpcp\Agent\Executor\Executor;
use Phpcp\Domain\UserAccount;

/**
 * Creates and tears down a hosting user's system account
 *
 * Kept separate from SiteProvisioner since migration 0006, because a
 * system account no longer ties to a website — it ties to a user: one user
 * = one uid = one home = many sites.
 *
 * An account is created lazily, only when the first site is created — a
 * server admin who has never hosted a site therefore has no Linux account
 * sitting around as pointless attack surface.
 */
final class AccountProvisioner
{
    public function __construct(
        private readonly WebServer\WebServerDriver $webserver,
        private readonly bool $sharedOwner = false,
    ) {
    }

    /**
     * Creates the account and home, ready to use — idempotent, no side effect if it already exists
     *
     * @return array{uid:int,gid:int}
     */
    public function ensure(Executor $executor, UserAccount $account): array
    {
        $this->assertNameAvailable($executor, $account);

        $result = $executor->exec([
            '/usr/sbin/useradd',
            '--system',
            '--no-create-home',
            '--home-dir', $account->home(),
            '--shell', '/usr/sbin/nologin',
            '--comment', self::comment($account->username),
            $account->username,
        ], timeout: 20);

        // exit 9 = the user already exists, which is fine (e.g. creating the
        // same person's second site, or retrying after a previous run failed partway)
        if (!$result->ok() && $result->exitCode !== 9) {
            throw new ExecutionFailed(
                'Failed to create system account: '.trim($result->stderr),
                $result->exitCode,
                $result->stderr,
            );
        }

        $this->createHome($executor, $account);

        return $this->lookup($executor, $account->username);
    }

    /**
     * Is this name still free, or is it an account the panel created itself?
     *
     * **The genuinely trustworthy gate for the username rule** — a
     * hardcoded denylist like `UserRepository::RESERVED_USERNAMES` will
     * always miss an account an admin creates later by hand, and letting
     * that slip through would `chown -R` a site's files to an existing
     * unrelated uid, letting that person read and edit a customer's files entirely.
     *
     * Decided from **the signature the panel stamps into the comment
     * field** at `useradd` time, never from the name, and never from the home path.
     *
     * This used to compare the home directory, which worked back when
     * homes lived at `/srv/phpcp/users` — a system account could never have
     * a home in there · **but since homes moved to `/home`, that comparison
     * flips backward instantly**: a system user named `deploy` who already
     * had a home at `/home/deploy` would exactly "match" what the panel
     * computed, and the panel would treat it as its own account → `chown
     * -R` that person's files to www-data and take over their home as a site's storage.
     *
     * The comment field is stronger evidence, because the panel writes it
     * itself at creation time, and there's no reason an account an admin created by hand would ever carry it.
     */
    private function assertNameAvailable(Executor $executor, UserAccount $account): void
    {
        $entry = $executor->exec(['/usr/bin/getent', 'passwd', $account->username], timeout: 10);

        if (!$entry->ok()) {
            return; // Not found = the name is free
        }

        // Shape: name:x:uid:gid:comment:home:shell
        $fields = explode(':', trim($entry->output()));
        $comment = $fields[4] ?? '';
        $home = $fields[5] ?? '';

        if ($comment === self::comment($account->username)) {
            return; // The panel's own account, created in an earlier pass
        }

        throw new ExecutionFailed(
            "The name {$account->username} is already in use as a system account (home at {$home}) — "
            .'the username has to change before a site can be created',
        );
    }

    /**
     * The signature stamped in /etc/passwd's comment field — proof this account was created by the panel itself
     *
     * Must remain constant forever — changing this text would mean every
     * previously-created account is instantly seen as belonging to someone
     * else, and that same customer's second site could never be created again.
     */
    public static function comment(string $username): string
    {
        return 'phpcp hosting account '.$username;
    }

    /**
     * A user's home layout
     *
     * `0711` at the parent level (`/srv/phpcp/users`) matters more than it
     * looks: a user can traverse into their own home, but can't `ls` to
     * list every home there — so a customer never even learns how many
     * other customers exist on this machine, let alone their names.
     */
    private function createHome(Executor $executor, UserAccount $account): void
    {
        $executor->makeDirectory($executor->path(\Phpcp\Kernel\Paths::usersDir()), 0711);
        $executor->makeDirectory($executor->path($account->home()), 0750);

        foreach ([$account->domainsDir(), $account->logDir()] as $dir) {
            $executor->makeDirectory($executor->path($dir), 0750);
        }

        // tmp and .ssh must never be readable by the web server's group —
        // PHP sessions live in tmp, and reading another site's session file is instant account takeover
        $executor->makeDirectory($executor->path($account->tmpDir()), 0700);
        $executor->makeDirectory($executor->path($account->sshDir()), 0700);

        $this->setOwnership($executor, $account);
    }

    /**
     * The owner is the user, the group is the web server's own group
     *
     * The same reasoning SiteProvisioner explains: if the group were set to
     * the user's own, the web server couldn't traverse a 0750 directory at
     * all, and every static file would answer 403 — including Let's
     * Encrypt's validation file, which would also break certificate renewal.
     */
    private function setOwnership(Executor $executor, UserAccount $account): void
    {
        if ($this->sharedOwner) {
            return; // The filesystem can't retain ownership — SiteProvisioner already proved this
        }

        $executor->exec([
            '/usr/bin/chown',
            '-R',
            $account->username.':'.$this->webserver->runAsGroup(),
            $executor->path($account->home()),
        ], timeout: 60);

        // tmp and .ssh must belong entirely to the user, never to the web server's group
        //
        // **A note for SFTP (phase E4):** the topmost home directory used
        // to be changed to root, to satisfy OpenSSH's `ChrootDirectory`
        // requirement, and that turned out to stop www-data from
        // traversing through to the docroot at all (the whole site answers
        // 403) · the correct fix is to chroot at the **parent** directory,
        // which is already root:root 0711 — so the user's own home never
        // needs to change at all · see the full explanation at
        // SftpAccessManager::configContent()
        foreach ([$account->tmpDir(), $account->sshDir()] as $private) {
            $executor->exec([
                '/usr/bin/chown',
                '-R',
                $account->username.':'.$account->username,
                $executor->path($private),
            ], timeout: 30);
        }
    }

    /**
     * Deletes the account and its entire home — only ever called once a user has no site left
     *
     * Never uses `userdel --remove`, since that deletes everything under
     * the home, including anything an admin might have mounted there · the caller decides what to do with the files.
     */
    public function remove(Executor $executor, UserAccount $account): void
    {
        // A failure here is fine — the site was already deleted, and a leftover account does no harm,
        // while throwing here would make the user think deletion failed when it actually already succeeded
        $executor->exec(['/usr/sbin/userdel', $account->username], timeout: 20);
    }

    /** @return array{uid:int,gid:int} */
    public function lookup(Executor $executor, string $user): array
    {
        $uid = $executor->exec(['/usr/bin/id', '-u', $user], timeout: 10);
        $gid = $executor->exec(['/usr/bin/id', '-g', $user], timeout: 10);

        if (!$uid->ok() || !$gid->ok()) {
            throw new ExecutionFailed("Failed to read the uid of user {$user}");
        }

        return ['uid' => (int) $uid->output(), 'gid' => (int) $gid->output()];
    }
}
