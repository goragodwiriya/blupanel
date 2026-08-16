<?php

declare(strict_types=1);

namespace Phpcp\Agent\Capability;

use Phpcp\Agent\Capability;
use Phpcp\Agent\Context;
use Phpcp\Agent\ExecutionFailed;
use Phpcp\Driver\SiteProvisioner;
use Phpcp\Agent\Executor\Executor;
use Phpcp\Agent\SelfProtection;
use Phpcp\Agent\ValidationError;
use Phpcp\Domain\Site;
use Phpcp\Support\Validator;

/**
 * Resets a website's file ownership back to the correct values — one site at a time
 *
 * ## Why this needs to exist
 *
 * File ownership goes wrong more easily than it might seem, and is the
 * number one cause of "uploaded a file and the site shows a 403" or "a
 * plugin can't update itself":
 *
 *   - unzipping a backup as root, so the whole set of files ends up owned by root
 *   - copying files in with scp/rsync as a different user
 *   - running composer or npm with sudo
 *   - migrating a site from an old machine that used a different set of uid numbers
 *   - a site created before the system fixed a group-ownership bug (used to
 *     be set to <user>:<user>, which left the web server unable to traverse
 *     the directory at all)
 *
 * ## What the correct value actually is
 *
 * Owner = that website's own user (PHP-FPM runs as this uid, so it must be able to write files)
 * Group = the web server's group (Apache/nginx must be able to read and traverse the directory)
 *
 * ## Scope
 *
 * Only ever works one website at a time — there is deliberately no "fix
 * every site at once" button · a chown that spans the whole machine is one
 * of the hardest kinds of mistake to recover from once it goes wrong, and
 * doing it one site at a time means seeing that it worked correctly before
 * moving on to the next one.
 */
final class SiteResetOwner extends SiteCapability implements Capability
{
    public static function name(): string
    {
        return 'site.reset_owner';
    }

    public function permission(): string
    {
        return 'site.edit';
    }

    public function isMutating(): bool
    {
        return true;
    }

    public function summary(): string
    {
        return "Reset a website's file ownership back to correct values";
    }

    public function validate(array $args): array
    {
        return [
            'site_id' => Validator::requireInt($args, 'site_id', 1),
            // Also fix permissions (0755/0644)? — kept separate from changing
            // ownership, since some sites deliberately set special
            // permissions on purpose, e.g. a script that needs to execute
            'fix_permissions' => (bool) ($args['fix_permissions'] ?? false),
        ];
    }

    public function run(array $args, Executor $executor, Context $context): array
    {
        $this->assertSiteAccess($context, $args['site_id']);

        $site = $this->loadSite($context, $args['site_id']);
        $root = $site->root();
        $resolved = $executor->path($root);

        // Guards against accidentally targeting the panel's own path, even if the database was tampered with to point there
        SelfProtection::assertPath($root);

        if (!$executor->exists($resolved)) {
            throw new ValidationError("Website directory not found: {$root}");
        }

        // Checked again after realpath — a symlink pointing outside the
        // website's own space would let chown -R follow it and change
        // ownership of files outside the intended scope
        $real = $executor->realPath($resolved);

        if ($real === null || $real !== rtrim($resolved, '/')) {
            throw new ValidationError(
                'The website path did not match what was expected after resolving symlinks — cancelled for safety',
            );
        }

        $owner = $site->systemUser();
        $group = $this->provisioner($context)->webserver()->runAsGroup();

        $before = $this->sample($executor, $site);

        /*
         * Every directory belonging to the site, not just `root()`
         *
         * Used to chown just `root()` alone, which worked fine when the
         * layout kept everything under a single folder · **in the cpanel
         * layout, `root()` is `.phpcp/<domain>`**, while the website's own
         * files live in `public_html` and logs live in `logs/<domain>` —
         * entirely different places — so the ownership-fix button reported
         * success while the files that were actually broken never got touched.
         *
         * Uses the same list used when the site was created
         * (SiteProvisioner::ownershipTargets), so "reset to correct values"
         * genuinely means the same values the system set at creation time.
         */
        foreach (SiteProvisioner::ownershipTargets($site) as $target) {
            $path = $executor->path($target);

            if (!$executor->exists($path)) {
                continue;
            }

            // -h changes the symlink itself, without following it to its target
            $result = $executor->exec(
                ['/usr/bin/chown', '-Rh', $owner . ':' . $group, $path],
                timeout: 300,
            );

            if (!$result->ok()) {
                throw new ExecutionFailed('Failed to change file ownership: ' . trim($result->stderr));
            }
        }

        $changedModes = 0;

        if ($args['fix_permissions']) {
            $changedModes = $this->resetModes($executor, $real);
        }

        $after = $this->sample($executor, $site);

        return [
            'site_id' => $site->id,
            'domain' => $site->domain,
            'owner' => $owner,
            'group' => $group,
            'path' => $root,
            'before' => $before,
            'after' => $after,
            'permissions_reset' => $args['fix_permissions'],
            'permission_passes' => $changedModes,
            'message' => sprintf(
                "Set %s's file ownership to %s:%s%s",
                $site->domain,
                $owner,
                $group,
                $args['fix_permissions']
                    ? ' and set directory permissions to 0750, files to 0640'
                    : '',
            ),
        ];
    }

    /**
     * Resets permissions back to the system's standard values
     *
     * 0750 / 0640, not the more familiar 0755 / 0644 — because each website
     * has its own user, and files are already set to the web server's group,
     * so granting "other" permission would mean letting *other websites'*
     * users read them, breaking the separation between sites.
     */
    private function resetModes(Executor $executor, string $root): int
    {
        $count = 0;

        foreach ([['-type', 'd', '0750'], ['-type', 'f', '0640']] as [$flag, $kind, $mode]) {
            $result = $executor->exec(
                ['/usr/bin/find', $root, $flag, $kind, '-exec', '/usr/bin/chmod', $mode, '{}', '+'],
                timeout: 300,
            );

            if ($result->ok()) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * The main directory's current owner — used to display a before/after view of what changed
     *
     * @return array<string,string>
     */
    private function sample(Executor $executor, Site $site): array
    {
        $out = [];

        foreach (['root' => $site->root(), 'public' => $site->docroot()] as $label => $path) {
            $resolved = $executor->path($path);

            if (!$executor->exists($resolved)) {
                continue;
            }

            $stat = $executor->stat($resolved);

            if ($stat === null) {
                continue;
            }

            $out[$label] = sprintf(
                '%s:%s %04o',
                self::nameOf('posix_getpwuid', (int) ($stat['uid'] ?? -1), 'name'),
                self::nameOf('posix_getgrgid', (int) ($stat['gid'] ?? -1), 'name'),
                ((int) ($stat['mode'] ?? 0)) & 0777,
            );
        }

        return $out;
    }

    /**
     * Resolves a uid/gid to a name; shows the raw number if it can't be resolved
     *
     * Failing to resolve is normal when a site was migrated from another
     * machine using a different set of uid numbers — which is one of the
     * main reasons this command exists in the first place, so showing the
     * raw number is more useful than showing a question mark.
     */
    private static function nameOf(string $function, int $id, string $key): string
    {
        if ($id < 0) {
            return '?';
        }

        if (!function_exists($function)) {
            return (string) $id;
        }

        $info = @$function($id);

        return is_array($info) && isset($info[$key]) ? (string) $info[$key] : (string) $id;
    }
}
