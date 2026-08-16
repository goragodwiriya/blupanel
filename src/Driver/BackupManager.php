<?php

declare(strict_types=1);

namespace Phpcp\Driver;

use Phpcp\Agent\ExecutionFailed;
use Phpcp\Agent\Executor\Executor;
use Phpcp\Agent\ValidationError;
use Phpcp\Domain\Site;
use Phpcp\Driver\Db\MariaDbManager;

/**
 * Create and restore backups — ARCHITECTURE §12
 *
 * Rules enforced here:
 *   1. Every backup file has a checksum and must pass verification before every restore.
 *      A partially corrupted file is more dangerous than no file at all, since restoring
 *      it yields half the data.
 *   2. Before a restore overwrites what's there, the existing state must be backed up
 *      first — a bad restore can still be undone.
 *   3. Extract to a temporary location first, then swap it into place — never touch the
 *      existing files until we're sure the extraction is complete.
 *
 * Rule 3 matters most: extracting directly over the existing files and failing partway
 * through leaves a site with a mix of old and new files, which is harder to fix than
 * losing all the files outright.
 */
final class BackupManager
{
    private const TAR = '/usr/bin/tar';

    /** The source site's manifest, at the root of the archive — see writeManifest() */
    public const MANIFEST = 'backup.json';

    /** Manifest format version — a destination that doesn't recognize it must reject, not guess */
    public const MANIFEST_SCHEMA = 1;

    /**
     * The temporary listing file `tar --index-file` writes while verifying an archive
     *
     * Always deleted before extraction, so it can never end up inside a restored site.
     */
    private const INDEX = '.tar-index';

    /**
     * Ceiling on the number of entries in an archive that will be restored
     *
     * Set deliberately high — a real site with `node_modules` or a full set of plugins
     * can normally have hundreds of thousands of files · this number is only a bound
     * against an unboundedly growing listing, not a policy on how many files a site
     * should have.
     */
    private const MAX_ENTRIES = 500_000;

    /**
     * Supported types — **site files and databases only**
     *
     * Used to include `config` (backing up `/etc/apache2`, `/etc/php`) and `full`
     * covering everything · both belong to the **machine**, not the customer, so they
     * have nowhere to live in a system where every backup file belongs to its data's
     * owner · the machine's own configuration is better backed up (and more reliably
     * restorable) with a VPS snapshot or git (PLAN-BACKUP-V2 §2 item B2).
     */
    public const TYPES = ['site', 'database'];

    /**
     * There is no longer a "system backup directory" — the destination always comes from
     * the data's owner
     *
     * This class used to hold a single shared path (`/var/lib/phpcp/backups`), which
     * meant every customer's backup files piled up together in the panel's own space ·
     * every method that writes a file now takes a `Site` and writes to
     * `$site->backupDir()`, which lives inside the owner's own home — there is no way to
     * call this and have it write outside the data owner's own home, even if the caller
     * makes a mistake.
     */
    public function __construct(
        private readonly MariaDbManager $databases = new MariaDbManager(),
    ) {
    }

    public static function assertType(string $type): string
    {
        if (!in_array($type, self::TYPES, true)) {
            throw new ValidationError('Invalid backup type');
        }

        return $type;
    }

    public static function typeLabel(string $type): string
    {
        return match ($type) {
            'site' => 'Site files',
            'database' => 'Database',
            default => $type,
        };
    }

    /**
     * Create a backup of a site's files — **the files the site actually serves, not its
     * status box**
     *
     * This used to back up `root()`, which worked back when there was a single layout:
     * `phpcp` kept everything under `<home>/domains/<domain>/`, with `public/` living
     * inside it, so backing up the box also caught the site's files.
     *
     * **The cpanel layout isn't like that** — `root()` is `<home>/.phpcp/<domain>`,
     * which only holds `__suspended.html`; the site's files live elsewhere, at
     * `<home>/public_html` · ever since cpanel became the standard layout (migration
     * 0020), the "Back up" button produced a file that **contained not a single site
     * file** while reporting success every time — a failure that could only be noticed
     * at restore time, which by definition is too late.
     *
     * `docroot()` also answers correctly when a site uses a Domain Pointer (pointing
     * outside its own home).
     *
     * `$owner` (`user:group`) is the owner the file must have after it's created ·
     * empty = skip (shared_owner mode, or a test without root).
     *
     * @return array{path:string,bytes:int,checksum:string}
     */
    public function backupSite(Executor $executor, Site $site, string $owner = ''): array
    {
        $dir = $this->prepareDir($executor, $site, $owner);
        $path = $this->pathFor($dir, $site->domain . '-files', 'tar.gz');
        $root = $executor->path($site->docroot());

        if (!$executor->exists($root)) {
            throw new ExecutionFailed("Site directory not found for {$site->domain}");
        }

        $manifest = $this->writeManifest($executor, $site, basename($root));

        try {
            // -C followed by the folder name — so files inside carry no full machine
            // path, and can be extracted anywhere without accidentally overwriting
            // something else.
            //
            // `-C` can be used multiple times in one command, each affecting only the
            // items that follow it — so the manifest ends up at the archive's root
            // alongside the site folder, not inside it.
            $result = $executor->exec([
                self::TAR,
                '--create', '--gzip',
                '--file', $executor->path($path),
                // **Every path handed to the real command must always go through
                // `path()`** — the logical path (`/home/...`) and the on-disk path
                // differ in sandbox mode · forget it in just this one spot and tar
                // can't find the manifest, failing the whole command even though
                // everything else is correct (found on a real machine during
                // PLAN-BACKUP-V2's first round of testing)
                '--directory', dirname($executor->path($manifest)), self::MANIFEST,
                '--directory', dirname($root),
                '--exclude', 'tmp',
                basename($root),
            ], timeout: 900);
        } finally {
            // Delete the whole temp directory, not just the file — otherwise empty
            // folders pile up over time.
            $executor->removePath(dirname($executor->path($manifest)));
        }

        if (!$result->ok()) {
            /*
             * **Clean up whatever tar left behind before it failed**
             *
             * tar creates the destination file from the very first second and writes
             * into it as it goes · failing partway through leaves a 20-byte `.tar.gz`
             * (an empty gzip stream) sitting in the customer's folder — listings read
             * straight from that folder, so it shows up right alongside a "restore"
             * button like a normal backup, eating into the customer's quota too · a
             * fake backup file that nobody knows is fake is the most dangerous thing
             * in this system.
             */
            $executor->removePath($executor->path($path));

            throw new ExecutionFailed('Failed to create backup file: ' . trim($result->stderr));
        }

        $this->handOver($executor, $path, $owner);

        return $this->describe($executor, $path);
    }

    /**
     * Prepare the account's backup folder and make sure it's **actually owned by the
     * customer**
     *
     * The agent runs as root · directories it creates are therefore owned by root, and
     * the customer can't open them over SFTP at all — despite this entire system having
     * been rebuilt so they can grab their own files · this folder may already exist from
     * provisioning time (`SiteLayout::requiredDirectories()`), but accounts created
     * before that change won't have it — so ownership must be set every time, not only
     * on first creation.
     */
    private function prepareDir(Executor $executor, Site $site, string $owner): string
    {
        $dir = $site->backupDir();

        $executor->makeDirectory($executor->path($dir), 0750);

        if ($owner !== '') {
            // Not -R, because the files inside already belong to the same customer,
            // and walking the whole folder on every backup would mean re-walking all
            // the old files for no benefit.
            $executor->exec(['/usr/bin/chown', $owner, $executor->path($dir)], timeout: 30);
        }

        return $dir;
    }

    /**
     * Hand a freshly created file over to its data's owner
     *
     * tar/mysqldump run as root produce a file owned root:root, mode 0600 · **the
     * customer can't download their own copy** and can't delete it themselves either,
     * which conflicts with this entire system's agreement (B1, B4: the file itself is
     * the source of truth, because the customer can delete it themselves).
     *
     * 0640, not 0644 — the group is the web server's group, while other users on the
     * machine have no business reading a file that has this customer's site and
     * database inside it.
     */
    private function handOver(Executor $executor, string $path, string $owner): void
    {
        if ($owner === '') {
            return;
        }

        $resolved = $executor->path($path);

        $executor->exec(['/usr/bin/chown', $owner, $resolved], timeout: 60);
        $executor->changeMode($resolved, 0640);
    }

    /**
     * The source site's manifest — what makes a backup file "self-describing"
     *
     * A backup file shipped off to be stored on another machine is just a `.tar.gz` the
     * destination panel knows nothing about: not which domain it belongs to, when it was
     * created, or which machine it came from · that makes an off-machine copy **write-only,
     * never readable back**, which defeats the entire reason it exists.
     *
     * Stored **inside the archive itself**, not as a companion file, because a companion
     * file is easy to lose along the way (manual copies, moving storage, downloading
     * through the web UI), leaving an archive with no known origin.
     *
     * @return string path to the temporary file the caller must delete
     */
    private function writeManifest(Executor $executor, Site $site, string $directory): string
    {
        $manifest = [
            'schema' => self::MANIFEST_SCHEMA,
            'type' => 'site',
            'domain' => $site->domain,
            'system_user' => $site->systemUser(),
            'php_version' => $site->phpVersion,
            // The top-level folder name inside the archive — the destination uses this
            // to verify extraction landed in the right place
            'directory' => $directory,
            'docroot' => $site->docroot(),
            'layout' => $site->owner->layout()->value,
            'aliases' => $site->aliases,
            'hostname' => php_uname('n'),
            'created_at' => time(),
        ];

        // Its own directory, because `-C <dir> backup.json` requires the file's name
        // inside the archive to be plain `backup.json`, with no leading path.
        $directoryPath = $site->backupDir() . '/.manifest-' . bin2hex(random_bytes(6));

        $executor->makeDirectory($executor->path($directoryPath), 0700);
        $executor->writeFile(
            $executor->path($directoryPath . '/' . self::MANIFEST),
            (string) json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            0600,
        );

        return $directoryPath . '/' . self::MANIFEST;
    }

    /**
     * Read the manifest out without extracting the whole archive
     *
     * The destination needs to know which domain this file belongs to **before**
     * deciding where to overwrite it · extracting the whole archive just to read one
     * line means needing free space equal to the entire site's size.
     *
     * @return array<string,mixed>|null null = an older-generation backup file that has no manifest yet
     */
    public function readManifest(Executor $executor, string $archive): ?array
    {
        $result = $executor->exec([
            self::TAR,
            '--extract', '--gzip', '--to-stdout',
            '--file', $executor->path($archive),
            self::MANIFEST,
        ], timeout: 60);

        if (!$result->ok()) {
            return null;
        }

        $manifest = json_decode(trim($result->stdout), true);

        return is_array($manifest) ? $manifest : null;
    }

    /**
     * Back up one site's database, into that site's owner's own home
     *
     * The filename starts with the domain, like the site-files backup, since both live
     * in the same folder · followed by the database name because a single site can have
     * multiple databases — a file that can't be told apart from another is a file the
     * customer has to guess about at restore time.
     *
     * @return array{path:string,bytes:int,checksum:string}
     */
    public function backupDatabase(Executor $executor, Site $site, string $database, string $owner = ''): array
    {
        $dir = $this->prepareDir($executor, $site, $owner);
        // .sql.gz, not raw .sql — SQL text compresses roughly 5-10x, and this file
        // already counts toward the customer's quota (B9).
        $path = $this->pathFor($dir, $site->domain . '-db-' . $database, 'sql.gz');

        $this->databases->dump($executor, $database, $path);
        $this->handOver($executor, $path, $owner);

        return $this->describe($executor, $path);
    }

    /**
     * Restore a site's files from a backup
     *
     * Steps: verify checksum → verify the entries inside → back up the current state →
     * extract to a temp location → swap into place
     *
     * ## The restored files are always treated as files "the customer wrote themselves"
     *
     * Ever since the backup folder moved into the customer's own home (PLAN-BACKUP-V2),
     * an archive that arrives here **is data an attacker can fully control** — they can
     * plant the file themselves over SFTP, the `backup.json` inside it can be
     * hand-written too, and the checksum is computed fresh straight from that same file
     * ({@see \Phpcp\Agent\Capability\BackupRestore}) · the checksum and manifest checks
     * therefore only prove "the file didn't change along the way," not that its contents
     * can be trusted.
     *
     * The consequence is that the extraction step has to treat the archive as hostile:
     *
     *   1. **Extract with the site owner's privileges, not root's** — this used to run
     *      tar as root inside a directory that lives in the customer's own home, which
     *      they can delete and recreate themselves · simply deleting `$staging` and
     *      dropping a symlink in its place, right before tar starts, would get root to
     *      write a file anywhere at all on the machine · dropping privileges first means
     *      the worst possible mistake stays confined to that one customer's own
     *      scope — which is somewhere they can already write to anyway.
     *   2. **`--no-same-owner --no-same-permissions`** — tar run as root treats both as
     *      implicitly on · an archive that supplies a file with uid 0, mode 4755, would
     *      then get a setuid-root shell planted in the site · the `chown -Rh` that
     *      follows happens to strip the setuid bit, but **never runs at all when
     *      `$owner` is empty** (shared_owner mode).
     *   3. **Verify the entry list before touching disk** — see {@see assertSafeEntries()}
     *
     * @return array{restored:string,safety:string,entries:int}
     */
    public function restoreSite(
        Executor $executor,
        Site $site,
        string $archive,
        string $checksum,
        string $owner = '',
    ): array {
        $this->assertIntact($executor, $archive, $checksum);

        $root = $executor->path($site->docroot());
        $staging = $root . '.restore-' . bin2hex(random_bytes(4));

        /*
         * 0700 while verifying, then opened up to 0750 before extraction
         *
         * The archive's entry listing gets written in here · during the window when
         * it's been written but not yet fully read and verified, nobody but root may
         * touch it — otherwise the verification step would be verifying a file its own
         * subject can still edit while it's being checked.
         */
        $executor->makeDirectory($staging, 0700);

        try {
            // Step 1 — verify the entry list before doing anything else at all.
            //
            // Before creating the safety backup too, because a safety backup of a large
            // site costs real time and real customer quota · creating one just for an
            // archive that's going to be rejected anyway squeezes their space for
            // nothing.
            $this->assertSafeEntries($executor, $archive, $staging . '/' . self::INDEX);

            // Step 2 — back up the current state first, so a bad restore can still be
            // undone. (The safety backup belongs to the customer like any other file —
            // pass $owner along to it too.)
            $safety = $this->backupSite($executor, $site, $owner);

            if ($safety['path'] === $archive) {
                throw new ExecutionFailed('The safety backup collides with the source file — cancelling the restore to avoid data loss');
            }

            // Verify again "after" creating the safety backup, and before extraction.
            //
            // Between those two moments, files get written into the backup directory —
            // if anything touched the source file in between, we need to know before
            // overwriting the real data, not after it's already gone.
            //
            // This check also closes the gap between "verify entry list" and "extract"
            // — a file swapped out after passing the entry-list check would have a
            // checksum that no longer matches here.
            $this->assertIntact($executor, $archive, $checksum);

            // Step 3 — extract to a temp location, still not touching the existing files.
            $executor->changeMode($staging, 0750);
            $this->extractInto($executor, $site, $archive, $staging, $owner);

            $entries = count($executor->listDirectory($staging));

            if ($entries === 0) {
                throw new ExecutionFailed('Backup file contains no data — cancelling the restore');
            }

            /*
             * Set ownership **before** the swap, not after — a site must never be live
             * for even one second with the wrong owner.
             *
             * Still necessary even though extraction already ran with the customer's
             * own privileges, because the **group** must be the web server's group, not
             * the user's primary group (full reasoning in
             * `SiteProvisioner::ownershipTargets()`) · and `$staging` itself is still
             * owned by root, since the agent is the one that created it.
             *
             * `-h` changes the symlink itself, without following it to change its
             * target outside our scope — the same reason `assertSafeEntries()` rejects
             * a hardlink pointing outside the archive: `-h` stops symlinks, but can't
             * stop hardlinks at all, because a hardlink has no separate "link" to change
             * apart from the real file.
             *
             * Empty means shared_owner mode, where the filesystem can't track ownership
             * at all anyway.
             */
            if ($owner !== '') {
                $chown = $executor->exec(['/usr/bin/chown', '-Rh', $owner, $staging], timeout: 300);

                if (!$chown->ok()) {
                    throw new ExecutionFailed(
                        'Failed to set ownership on the restored files, so cancelling before touching the existing state: ' . trim($chown->stderr),
                    );
                }
            }
        } catch (\Throwable $e) {
            // Nothing existing has been touched yet at this point — just discard the
            // temp location and stop.
            $executor->removePath($staging);

            throw $e;
        }

        // Step 4 — swap into place: move the old state out first, then move the new one in.
        $retired = $root . '.old-' . bin2hex(random_bytes(4));

        $executor->rename($root, $retired);

        try {
            $executor->rename($staging, $root);
        } catch (\Throwable $e) {
            // The swap failed — put the previous state back immediately.
            $executor->rename($retired, $root);
            $executor->removePath($staging);

            throw new ExecutionFailed('Failed to swap in the restored files, so the previous state has been restored: ' . $e->getMessage());
        }

        $executor->removePath($retired);

        return [
            'restored' => $site->docroot(),
            'safety' => $safety['path'],
            'entries' => $entries,
        ];
    }

    /**
     * Extract the archive to a temp location, using the site owner's privileges
     *
     * `$owner` empty = shared_owner mode, or a test without root · there, the
     * customer's system account may not actually exist on the machine, and dropping
     * privileges would drop to a nonexistent account, failing the restore even though
     * nothing is actually wrong — use the agent's own privileges instead (`asUser(null)`
     * = run directly), and rely on `--no-same-*` as the guard instead.
     *
     * **`backup.json` must never leak into the docroot** — it would be served
     * immediately at `https://<domain>/backup.json`, along with the system username,
     * on-disk file paths, and the source machine's hostname.
     *
     * `--strip-components 1` already happens to strip out the single top-level entry in
     * practice, but that's a side effect of counting path components, not a guarantee ·
     * `--exclude` is written explicitly so security doesn't depend on tar's undocumented
     * behavior.
     */
    private function extractInto(
        Executor $executor,
        Site $site,
        string $archive,
        string $staging,
        string $owner,
    ): void {
        $argv = [
            self::TAR,
            '--extract', '--gzip',
            '--file', $executor->path($archive),
            '--directory', $staging,
            '--exclude', self::MANIFEST,
            '--strip-components', '1',
            '--no-same-owner', '--no-same-permissions',
        ];

        $result = $executor->asUser(
            $owner !== '' ? $site->systemUser() : null,
            static function () use ($executor, $argv): array {
                $run = $executor->exec($argv, timeout: 900);

                return ['ok' => $run->ok(), 'stderr' => trim($run->stderr)];
            },
        );

        if (($result['ok'] ?? false) !== true) {
            throw new ExecutionFailed('Failed to extract the backup file: ' . (string) ($result['stderr'] ?? ''));
        }
    }

    /**
     * Verify the entries inside an archive before touching disk — the guard `FileUnzip`
     * has always had, and this class never did
     *
     * ## Why go through a file, not stdout
     *
     * `exec()` truncates stdout at 1 MB (`RealExecutor::MAX_OUTPUT_BYTES`) · a real
     * site's entry listing normally runs longer than that (roughly 80 bytes per entry =
     * exceeded at around ten thousand entries) · a check that reads from stdout would
     * therefore only verify the head and silently let the rest pass through — meaning a
     * malicious archive only needs to be long enough to walk straight past the guard ·
     * `--index-file` has tar write to a file itself, and reading it line by line has no
     * such ceiling.
     *
     * ## What's rejected, and what isn't
     *
     *   - **Names starting with `/` or containing `..`** — write outside the temp
     *     location · this guard is real and actually catches things: tar only strips
     *     `../` at the **very front** of a name, while
     *     `public_html/../../../etc/cron.d/pwn` reaches us with the whole name intact.
     *   - **device/fifo/socket** — a site has no reason to ever contain one of these.
     *   - **A hardlink pointing outside the archive** — especially dangerous because the
     *     `chown -Rh` that follows changes the ownership of the **real underlying
     *     target file** to the customer · a hardlink pointing at `/etc/shadow` would
     *     therefore hand that entire file over to them.
     *
     *     **But this guard is a second net, not the layer stopping this today** — GNU
     *     tar 1.35 already resolves a hardlink's target before we ever see it:
     *     `../../../etc/shadow`, `/etc/shadow`, and
     *     `public_html/../../../../etc/shadow` are all collapsed down to `etc/shadow`
     *     as early as `--list` (verified against a real tar binary) · the guard stays
     *     because that collapsing is a tool behavior we don't control, not a contract.
     *
     * **Symlinks are not rejected**, even though an audit report suggested rejecting
     * them — real sites commonly contain symlinks (Laravel's `public/storage`,
     * `node_modules/.bin/*`), and rejecting the whole archive over one would make the
     * restore button unusable for most sites · it's safe because of three stacked
     * layers: tar 1.35 refuses to write through a symlink member that's inside the same
     * archive (verified), extraction runs with the customer's privileges, not root's,
     * and `chown -h` doesn't follow symlinks · a symlink pointing outside the home after
     * a restore is something the customer could already create themselves over SFTP
     * anyway, so it's not a privilege the restore added.
     *
     * @return int the total number of entries in the archive
     */
    private function assertSafeEntries(Executor $executor, string $archive, string $indexFile): int
    {
        // Simulated mode doesn't run real tar, so there's no listing to read.
        if ($executor->isSimulated()) {
            return 0;
        }

        $list = $executor->exec([
            self::TAR,
            '--list', '--verbose', '--gzip',
            '--file', $executor->path($archive),
            '--index-file', $indexFile,
        ], timeout: 300);

        if (!$list->ok()) {
            throw new ExecutionFailed('Could not read the entry list in the backup file: ' . trim($list->stderr));
        }

        // Read straight off disk, the same way assertIntact() calls hash_file() — this
        // file was just written by the agent itself, into its own directory, not a path
        // received from the user.
        $handle = @fopen($indexFile, 'rb');

        if ($handle === false) {
            throw new ExecutionFailed('Could not read the backup file\'s entry listing, so the restore was not performed');
        }

        $count = 0;

        try {
            while (($line = fgets($handle)) !== false) {
                $line = rtrim($line, "\r\n");

                if ($line === '') {
                    continue;
                }

                if (++$count > self::MAX_ENTRIES) {
                    throw new ExecutionFailed(sprintf(
                        'The backup file has more than %s entries, which exceeds what the system can restore automatically',
                        number_format(self::MAX_ENTRIES),
                    ));
                }

                self::assertSafeEntry($line);
            }
        } finally {
            fclose($handle);
        }

        // Always deleted before extraction, never after — otherwise it becomes just
        // another file inside the restored site.
        $executor->removePath($indexFile);

        return $count;
    }

    /**
     * Verify a single entry from `tar --list --verbose`'s listing
     *
     * The format GNU tar prints (confirmed against tar 1.35 on a real machine):
     *
     *     drwxr-xr-x poo/poo    0 2026-08-14 23:05 site/
     *     -rw-r--r-- poo/poo    6 2026-08-14 23:05 site/real.txt
     *     lrwxrwxrwx poo/poo    0 2026-08-14 23:05 site/evil -> /etc/cron.d
     *     hrw-r--r-- poo/poo    0 2026-08-14 23:05 site/hard link to site/real.txt
     *
     * A line that can't be parsed is rejected, not skipped — a guard that shrugs at
     * something it doesn't understand and lets it through isn't a guard.
     */
    private static function assertSafeEntry(string $line): void
    {
        if (preg_match('/^(.)\S{9}\s+\S+\s+\S+\s+\S+\s+\S+\s+(.+)$/', $line, $matches) !== 1) {
            throw new ExecutionFailed('Could not parse an entry in the backup file, so the restore was not performed: ' . $line);
        }

        [$type, $name] = [$matches[1], $matches[2]];

        // Strip the link target off the name · take the **rightmost** separator,
        // because a real filename can itself contain the text " -> " — the one tar
        // appends is always exactly one and always at the very end.
        $separator = match ($type) {
            'l' => ' -> ',
            'h' => ' link to ',
            default => '',
        };

        $target = '';

        if ($separator !== '') {
            $at = strrpos($name, $separator);

            if ($at === false) {
                throw new ExecutionFailed('A link entry in the backup file has no target, so the restore was not performed: ' . $line);
            }

            $target = substr($name, $at + strlen($separator));
            $name = substr($name, 0, $at);
        }

        if (!in_array($type, ['-', 'd', 'l', 'h'], true)) {
            throw new ExecutionFailed(
                'The backup file has an entry that is not a file, directory, or link (' . $name . '), so the restore was not performed',
            );
        }

        self::assertContained($name, 'points outside the destination directory');

        if ($type === 'h') {
            self::assertContained($target, 'is a hardlink pointing outside the backup file');
        }
    }

    /** A path that always stays in bounds — doesn't start with `/` and has no `..` component */
    private static function assertContained(string $path, string $reason): void
    {
        if ($path === '' || str_starts_with($path, '/') || in_array('..', explode('/', $path), true)) {
            throw new ExecutionFailed(
                'This backup file has an entry that ' . $reason . ' (' . ($path === '' ? '(empty)' : $path) . '), so the restore was not performed',
            );
        }
    }

    /** Verify a backup file is still intact before it's used */
    public function assertIntact(Executor $executor, string $archive, string $checksum): void
    {
        $resolved = $executor->path($archive);

        if (!$executor->exists($resolved)) {
            throw new ValidationError('The specified backup file was not found');
        }

        if ($checksum === '') {
            throw new ValidationError('This backup file has no checksum recorded, so its integrity cannot be verified');
        }

        $actual = @hash_file('sha256', $resolved);

        if ($actual === false) {
            throw new ExecutionFailed('Could not read the backup file to verify it');
        }

        if (!hash_equals($checksum, $actual)) {
            throw new ValidationError(
                'The backup file does not match its recorded checksum — it may be corrupted or modified, so the restore was not performed',
            );
        }
    }

    /**
     * Delete one backup file · `$dir` is the only boundary deletion is permitted within
     *
     * The caller must always pass in **that file's own owner's** backup directory —
     * this guard is what prevents a delete request for one account from reaching
     * another account's files, even though both live under `/home` alike.
     */
    public function delete(Executor $executor, string $dir, string $archive): void
    {
        // Strip .. before comparing the prefix.
        //
        // A plain string comparison alone can be fooled by
        // /home/cust/backup/../.ssh/authorized_keys, which starts with exactly the
        // expected prefix but points outside the backup folder.
        if (preg_match('#(^|/)\.\.(/|$)#', $archive) === 1) {
            throw new ValidationError('Backup file path must not contain ..');
        }

        $resolved = $executor->path($archive);
        $expected = rtrim($executor->path($dir), '/');

        if (!str_starts_with($resolved, $expected . '/')) {
            throw new ValidationError('Only files inside the backup directory can be deleted');
        }

        // Verify again after resolving symlinks, for a file that actually exists —
        // a link pointing outside the backup directory can pass the string comparison
        // above.
        if ($executor->exists($resolved)) {
            $real = $executor->realPath($resolved);

            if ($real === null || !str_starts_with($real, $expected . '/')) {
                throw new ValidationError('This file points outside the backup directory, so it cannot be deleted through this system');
            }

            $executor->removePath($real);
        }
    }

    /** @return array{path:string,bytes:int,checksum:string} */
    private function describe(Executor $executor, string $path): array
    {
        $resolved = $executor->path($path);
        $stat = $executor->stat($resolved);

        if ($stat === null) {
            throw new ExecutionFailed('The backup file was created but could not be found');
        }

        $checksum = @hash_file('sha256', $resolved);

        return [
            'path' => $path,
            'bytes' => (int) $stat['size'],
            'checksum' => $checksum === false ? '' : $checksum,
        ];
    }

    /**
     * Backup filenames must never collide, under any circumstance
     *
     * This used to rely on second-level timestamps alone, which really can collide, and
     * once caused real data loss: during a restore, the system backs up the current
     * state first as a safety measure — if that file happened to get the same name as
     * the file being restored, it would overwrite the source, and the system would then
     * go on to extract "the state that had just been overwritten" back in place of the
     * original, reporting success the whole time.
     *
     * Appending a random value isn't cosmetic — it's what prevents data loss.
     */
    private function pathFor(string $dir, string $label, string $extension): string
    {
        $safe = preg_replace('/[^a-zA-Z0-9._-]/', '-', $label) ?? 'backup';

        return sprintf(
            '%s/%s-%s-%s.%s',
            rtrim($dir, '/'),
            $safe,
            date('Ymd-His'),
            bin2hex(random_bytes(3)),
            $extension,
        );
    }
}
