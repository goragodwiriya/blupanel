<?php

declare (strict_types = 1);

namespace Phpcp\Driver;

use Phpcp\Agent\ExecutionFailed;
use Phpcp\Agent\Executor\Executor;
use Phpcp\Domain\Site;
use Phpcp\Driver\Php\FpmManager;
use Phpcp\Driver\WebServer\CustomConfig;
use Phpcp\Driver\WebServer\WebServerDriver;

/**
 * Every operating-system-side task for a single website — ARCHITECTURE §11
 *
 * Kept together here because every capability that touches a website
 * (create, delete, suspend, change PHP version) has to run the same set of
 * steps and must be able to revert the same way · scattering this across
 * each capability would mean sooner or later some path forgets to roll
 * back and leaves a stray config that breaks every site on the machine.
 */
final class SiteProvisioner
{
    /** The filesystem probe's result, per path — probing means genuinely creating a file, too costly to repeat for every site */
    private array $ownershipProbe = [];

    public function __construct(
        private readonly WebServerDriver $webserver,
        private readonly FpmManager $fpm,
        /** See Config::sharedOwner() — true when the filesystem can't retain file ownership */
        private readonly bool $sharedOwner = false,
    ) {
    }

    public function webserver(): WebServerDriver
    {
        return $this->webserver;
    }

    /**
     * @return mixed
     */
    public function fpm(): FpmManager
    {
        return $this->fpm;
    }

    /**
     * The user's system account — this task moved to AccountProvisioner since migration 0006
     *
     * @return array{uid:int,gid:int}
     */
    public function account(): AccountProvisioner
    {
        return new AccountProvisioner($this->webserver, $this->sharedOwner);
    }

    /** @return array{uid:int,gid:int} */
    public function lookupUser(Executor $executor, string $user): array
    {
        return $this->account()->lookup($executor, $user);
    }

    /**
     * Creates a website's directory structure with the correct permissions
     *
     * The user's home must already have been created by AccountProvisioner
     * — this only handles a single site's own folder under `<home>/domains/`.
     *
     * 750 permissions are what stops **a different customer** from reading
     * these files (SECURITY §2.6) — the web server can get in because it
     * shares the same group · sites belonging to the same customer can read
     * each other's files deliberately, since they're the same person's own property and share the same uid.
     */
    public function createDirectories(Executor $executor, Site $site): void
    {
        $executor->makeDirectory($executor->path($site->root()), 0750);

        foreach ([$site->docroot(), $site->logDir(), $site->tmpDir(), $site->backupDir()] as $dir) {
            $executor->makeDirectory($executor->path($dir), 0750);
        }

        // The generated vhost includes an `IncludeOptional` for the site's admin config directory.
        // Creating the site while that directory is still absent makes Apache reject the entire config
        // as soon as configtest runs, even though no custom config has been written yet.
        foreach (['apache', 'nginx'] as $service) {
            $dir = CustomConfig::siteDirectory($service, $site->domain);
            $executor->makeDirectory($executor->path($dir), 0755);
        }

        // Domain Pointer — the docroot points at a folder that already has code in it
        // A welcome file must never be dropped in here — the user's own code matters more than our welcome page
        if ($site->docrootOverride === '') {
            // A starter file, so opening the site shows it genuinely works, instead of Apache's own 403 page
            $index = $executor->path($site->docroot().'/index.php');
            if (!$executor->exists($index)) {
                $executor->writeFile($index, $this->welcomePage($site), 0640);
            }
        }

        $suspended = $executor->path($site->suspendedPage());
        if (!$executor->exists($suspended)) {
            $executor->writeFile($suspended, $this->suspendedPage($site), 0644);
        }

        $this->setOwnership($executor, $site);
    }

    /**
     * A file's owner is the website's own user, but the group is the web server's own group
     *
     * This used to be set to <user>:<user>, which looked safer, but left
     * Apache unable to traverse the website's directory at all (mode
     * 0750), so every static file answered 403 — including Let's Encrypt's
     * validation file, which also broke certificate renewal.
     *
     * This shape still stops sites from reading each other's files, since
     * each site's own PHP-FPM runs as its own uid, not www-data, with
     * open_basedir on top as another layer — all www-data can read is
     * static files, which is exactly the job it has to do anyway.
     *
     * The one exception: a filesystem that can't retain uid/gid at all
     * (NTFS/exFAT/FAT) — see assertOwnershipUnsupported() and SECURITY §2.6.
     */

    /**
     * Every directory this site owns — shared between provisioning and fixing ownership
     *
     * **Must stay the same set in both places** · when a site is created,
     * ownership is set on every directory, but `site.reset_owner` used to
     * chown just `root()` alone, which in the cpanel layout is
     * `.phpcp/<domain>` — never touching `public_html`, the place where the
     * ownership problem genuinely happens — so the reset button reported
     * success while the site stayed just as broken.
     *
     * @return list<string>
     */
    public static function ownershipTargets(Site $site): array
    {
        $root = rtrim($site->root(), '/');
        $targets = [$root];

        /*
         * A site directory living **outside** root() needs its own separate chown
         *
         * The phpcp layout keeps everything under one folder
         * (`<home>/domains/<domain>/`) — a single `chown -R` there already
         * covers docroot, logs, and backups all at once, so this loop adds
         * nothing at all — the existing behavior doesn't shift by a single command.
         *
         * **The cpanel layout is nothing like that**: docroot lives at
         * `<home>/public_html` and logs live at `<home>/logs/<domain>`,
         * neither of which sits under root() at all · without collecting
         * these separately, a site's files would stay owned by root, and a
         * customer couldn't upload anything via SFTP even though the screen said every step of site creation succeeded.
         */
        $layoutDirs = $site->owner->layout()->requiredDirectories(
            $site->owner->home(),
            $site->domain,
            $site->owner->isMainDomain($site->domain),
        );

        foreach (array_keys($layoutDirs) as $dir) {
            $dir = rtrim($dir, '/');

            if ($dir !== $root && !str_starts_with($dir.'/', $root.'/')) {
                $targets[] = $dir;
            }
        }

        // A docroot pointing outside the home needs its own separate chown — chown -R at the home never reaches it
        if ($site->docrootOverride !== '') {
            $targets[] = $site->docrootOverride;
        }

        $targets = array_values(array_unique($targets));

        return $targets;
    }

    /**
     * @param Executor $executor
     * @param Site $site
     * @return null
     */
    public function setOwnership(Executor $executor, Site $site): void
    {
        if ($this->sharedOwner) {
            // fail-closed — skipping chown is allowed only once it's proven the filesystem genuinely can't do it
            $this->assertOwnershipUnsupported($executor, $site);

            return;
        }

        $owner = $site->systemUser().':'.$this->webserver->runAsGroup();

        $targets = self::ownershipTargets($site);

        foreach ($targets as $target) {
            $executor->exec([
                '/usr/bin/chown',
                '-R',
                $owner,
                $executor->path($target)
            ], timeout: 60);
        }
    }

    /**
     * Proves the filesystem holding the website "genuinely can't retain file ownership" before chown is skipped
     *
     * This is the entire point of shared_owner mode — never, under any
     * circumstance, change this to "try chown, skip it if it fails",
     * because on a real server a temporary chown failure (disk full,
     * quota, SELinux) would silently turn off the separation between
     * sites, more dangerous than not having this mode at all.
     *
     * The check is a genuine test, never a guess based on filesystem type —
     * a test file is written, chown'd, and its owner read back · if the
     * owner genuinely changed, the filesystem can do it, and shared_owner
     * must not be turned on — no list of filesystem types to keep maintaining forever.
     */
    private function assertOwnershipUnsupported(Executor $executor, Site $site): void
    {
        $root = $executor->path($site->root());

        if (isset($this->ownershipProbe[$root])) {
            return;
        }

        $probe = $root.'/.phpcp-ownership-probe';

        try {
            $executor->writeFile($probe, "phpcp ownership probe\n", 0600);
            $executor->exec(['/usr/bin/chown', $site->systemUser(), $probe], timeout: 15);

            $stat = $executor->stat($probe);
            $expected = $this->lookupUser($executor, $site->systemUser());

            if ($stat !== null && $stat['uid'] === $expected['uid']) {
                throw new ExecutionFailed(
                    "sites.shared_owner is turned on, but {$site->root()} sits on a filesystem that can retain file ownership normally\n\n"
                    ."This mode exists only for a filesystem that can't retain uid/gid (NTFS, exFAT, FAT)\n"
                    .'This machine can separate permissions between sites normally, so sites.shared_owner must be set to false',
                );
            }
        } finally {
            $executor->removePath($probe);
        }

        $this->ownershipProbe[$root] = true;
    }

    /**
     * Writes a website's entire set of config files into a transaction (not yet committed)
     */
    /**
     * @param list<string> $poolExtraPaths the Domain Pointer of every site sharing this same pool
     */
    public function stageConfigs(
        ConfigTransaction $tx,
        Site $site,
        Executor $executor,
        array $poolExtraPaths = [],
    ): void {
        // Must come before writing files, never after — the configtest
        // that follows would fail immediately if a module the vhost's own
        // directives need isn't turned on yet
        $this->webserver->ensureModules($executor, $site->usesSsl());

        $tx->write(
            $site->fpmPoolFile(),
            $this->fpm->renderPool($site, $this->webserver->runAsUser(), $executor, $poolExtraPaths),
            0644,
        );

        $this->stageVhost($tx, $site, $executor);
    }

    /**
     * Writes a website's config files into a transaction — every file this site needs to have
     *
     * Kept as its own method because there are six callers (creating a
     * site · adding/removing/setting domains · suspending/resuming), and
     * since nginx-proxy mode arrived, the file count per site is no longer
     * always one · leaving each caller to loop on its own would mean
     * sooner or later one of them forgets to write the backend layer's file.
     */
    public function stageVhost(ConfigTransaction $tx, Site $site, Executor $executor): void
    {
        foreach ($this->webserver->vhostFiles($site, $executor) as $path => $contents) {
            $tx->write($path, $contents, 0644);
        }
    }

    /**
     * Validates both the web server's and FPM's config, then reloads
     *
     * Always validated before reloading — this is the step that stops a
     * bad vhost from taking down every site on the machine at once (ARCHITECTURE §10).
     *
     * @return array{0:bool,1:string}
     */
    public function validate(Executor $executor, Site $site): array
    {
        [$fpmOk, $fpmOut] = $this->fpm->testConfig($executor, $site->phpVersion);
        if (!$fpmOk) {
            return [false, "PHP-FPM {$site->phpVersion} configuration failed validation:\n".$fpmOut];
        }

        [$webOk, $webOut] = $this->webserver->testConfig($executor);
        if (!$webOk) {
            return [false, "Web server configuration failed validation:\n".$webOut];
        }

        return [true, trim($webOut)];
    }

    /** Reloads both services after validation has passed */
    public function reload(Executor $executor, Site $site, ?string $alsoPhpVersion = null): void
    {
        $this->fpm->reload($executor, $site->phpVersion);

        // When changing PHP version, the old version must also be reloaded so its old pool goes away
        if ($alsoPhpVersion !== null && $alsoPhpVersion !== $site->phpVersion) {
            $this->fpm->reload($executor, $alsoPhpVersion);
        }

        $this->webserver->reload($executor);
    }

    /**
     * Deletes a website's config files (used when a site is deleted)
     *
     * **A pool is shared with the same owner's other sites** since
     * migration 0006, so only a version the owner genuinely isn't using
     * anymore can be deleted · deleting every version the old way would
     * mean deleting one site instantly takes down every sibling site
     * belonging to that same customer.
     *
     * @param list<string> $allPhpVersions every version the system knows about
     * @param list<string> $versionsStillUsed versions the owner still uses after this site is deleted
     */
    public function stageRemoval(
        ConfigTransaction $tx,
        Site $site,
        array $allPhpVersions,
        array $versionsStillUsed = [],
    ): void {
        foreach ($this->webserver->vhostPaths($site) as $path) {
            $tx->delete($path);
        }

        // Deletes the pool of every version no longer in use, in case a past version switch left a stray file behind
        foreach ($allPhpVersions as $version) {
            if (in_array($version, $versionsStillUsed, true)) {
                continue;
            }

            $tx->delete($site->fpmPoolFileFor($version));
        }
    }

    /**
     * @param Site $site
     *
     * NOTE: this generated page's Thai text is deliberately kept — it is
     * content shown to a hosted site's own end visitors (product content
     * for this panel's Thai hosting market), not panel UI or a code
     * comment, so it falls outside this sweep's "English code and
     * comments" scope. Same for suspendedPage() below.
     */
    private function welcomePage(Site $site): string
    {
        $domain = htmlspecialchars($site->domain, ENT_QUOTES, 'UTF-8');

        return <<<HTML
            <!doctype html>
            <html lang="th">
            <meta charset="utf-8">
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <title>{$domain}</title>
            <body style="font-family:system-ui,-apple-system,'Segoe UI',sans-serif;max-width:640px;margin:4rem auto;padding:0 1.5rem;line-height:1.7;color:#0f172a">
              <h1 style="font-size:1.4rem;margin-bottom:.25rem">{$domain}</h1>
              <hr style="border:none;border-top:1px solid #e2e8f0;margin:1.5rem 0">
              <p>เว็บไซต์นี้ถูกสร้างเรียบร้อยแล้ว และกำลังทำงานด้วย PHP <?= PHP_VERSION ?></p>
              <p style="color:#64748b;font-size:.9rem">
                อัปโหลดไฟล์ของคุณไปที่ <code>public/</code> เพื่อแทนที่หน้านี้
              </p>
            </body>
            </html>

            HTML;
    }

    /**
     * @param Site $site
     */
    private function suspendedPage(Site $site): string
    {
        $domain = htmlspecialchars($site->domain, ENT_QUOTES, 'UTF-8');

        return <<<HTML
            <!doctype html>
            <html lang="th">
            <meta charset="utf-8">
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <title>เว็บไซต์ถูกระงับชั่วคราว</title>
            <body style="font-family:system-ui,-apple-system,'Segoe UI',sans-serif;max-width:520px;margin:5rem auto;padding:0 1.5rem;text-align:center;line-height:1.7;color:#0f172a">
              <h1 style="font-size:1.3rem">เว็บไซต์ถูกระงับชั่วคราว</h1>
              <p style="color:#64748b">{$domain} ไม่สามารถให้บริการได้ในขณะนี้</p>
              <p style="color:#94a3b8;font-size:.9rem">กรุณาติดต่อผู้ดูแลระบบ</p>
            </body>
            </html>

            HTML;
    }
}
