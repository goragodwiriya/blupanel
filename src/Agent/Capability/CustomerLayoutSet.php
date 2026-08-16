<?php

declare(strict_types=1);

namespace Phpcp\Agent\Capability;

use Phpcp\Agent\Capability;
use Phpcp\Agent\Context;
use Phpcp\Agent\ExecutionFailed;
use Phpcp\Agent\Executor\Executor;
use Phpcp\Agent\ValidationError;
use Phpcp\Domain\Site;
use Phpcp\Domain\SiteLayout;
use Phpcp\Domain\SiteRepository;
use Phpcp\Domain\UserAccount;
use Phpcp\Driver\ConfigTransaction;
use Phpcp\Support\Validator;

/**
 * Switches a single account's file layout — **genuinely moves the files too, not just the setting**
 *
 * ## Why changing the setting alone wouldn't work
 *
 * A website's path is **computed** from the layout every time it's read,
 * never stored · changing `users.site_layout` alone would make the whole
 * system point at the new path immediately while the files are still at the
 * old one — a site currently being served would 404 the instant it's saved,
 * and the site's logs would go missing with no error message anywhere
 * explaining why.
 *
 * So this does all three things in a single command: move the files → change the setting → rewrite vhost/pool.
 *
 * ## Order and reversal
 *
 * Files are moved first, since that's the hardest step to reverse and its
 * outcome has to be known before touching anything else · every move is
 * recorded, and if any step fails, everything is moved back in reverse
 * order before the error is thrown · the web server's config files use
 * {@see ConfigTransaction}, which already restores the original file on its own.
 *
 * `rename()` within the same home is always on the same filesystem, so it's
 * an atomic move — never a copy-file-by-file that could get stuck halfway.
 *
 * ## What's still worth watching for, and telling the caller
 *
 * This account's sites go offline briefly while moving (a fraction of a
 * second per site), and **any path a customer's own script hardcoded as a
 * full path will break** — so this has to be a command an admin
 * deliberately clicks, never a side effect of saving some other form.
 */
final class CustomerLayoutSet extends CustomerCapability implements Capability
{
    public static function name(): string
    {
        return 'customer.layout_set';
    }

    public function permission(): string
    {
        return 'customer.manage';
    }

    public function isMutating(): bool
    {
        return true;
    }

    public function summary(): string
    {
        return "Switch a hosting account's file layout, moving files to match";
    }

    /**
     * @param array<string,mixed> $args
     * @return array<string,mixed>
     */
    public function validate(array $args): array
    {
        $layout = trim((string) ($args['layout'] ?? ''));

        // An empty value = follow the system default, which is itself a valid choice, not a missing value
        if ($layout !== '' && SiteLayout::tryFrom($layout) === null) {
            throw new ValidationError(
                'File layout must be phpcp, cpanel, or empty (follows the system default)',
            );
        }

        return [
            'user_id' => Validator::requireInt($args, 'user_id', 1),
            'layout' => $layout,
        ];
    }

    /**
     * @param array<string,mixed> $args
     * @return array<string,mixed>
     */
    public function run(array $args, Executor $executor, Context $context): array
    {
        $user = $this->loadHostingAccount($context, $args['user_id']);
        $owner = UserAccount::fromRow($user);

        $target = $args['layout'] === '' ? null : SiteLayout::from($args['layout']);
        $effective = $target ?? SiteLayout::systemDefault();

        if ($owner->layout() === $effective && ($owner->layout ?? null) === $target) {
            return [
                'user_id' => $owner->userId,
                'layout' => $args['layout'],
                'moved' => 0,
                'message' => 'This account already uses this layout — nothing to change',
            ];
        }

        $repository = new SiteRepository($context->db);
        $sites = $context->db->all(
            'SELECT id FROM sites WHERE owner_user_id = :u ORDER BY created_at, id',
            ['u' => $owner->userId],
        );

        // An account with no site yet has no files to move — just change the setting and finish
        if ($sites === []) {
            $this->save($context, $owner->userId, $args['layout']);

            return [
                'user_id' => $owner->userId,
                'layout' => $args['layout'],
                'moved' => 0,
                'message' => sprintf('Set file layout to %s (no website yet, so no files needed moving)', $effective->value),
            ];
        }

        $moved = $this->moveAll($executor, $repository, $owner, $effective, array_column($sites, 'id'));

        $this->save($context, $owner->userId, $args['layout']);

        // vhost/pool are rewritten after the setting is saved — the new
        // paths have to be read back from the database, not from this same
        // UserAccount instance, which still holds the old layout in memory
        $rebuilt = $this->rebuild($executor, $context, array_column($sites, 'id'));

        return [
            'user_id' => $owner->userId,
            'layout' => $args['layout'],
            'moved' => count($moved),
            'sites' => $rebuilt,
            'paths' => $moved,
            'message' => sprintf(
                'Switched file layout to %s · moved %d folder(s) and rewrote configuration for %d website(s)',
                $effective->value,
                count($moved),
                count($rebuilt),
            ),
        ];
    }

    /**
     * Moves every site's directory to the new layout · a failure anywhere moves everything back
     *
     * @param  list<int> $siteIds
     * @return list<array{from:string,to:string}>
     */
    private function moveAll(
        Executor $executor,
        SiteRepository $repository,
        UserAccount $owner,
        SiteLayout $target,
        array $siteIds,
    ): array {
        /** @var list<array{from:string,to:string}> $done */
        $done = [];

        try {
            foreach ($siteIds as $siteId) {
                $site = $repository->load((int) $siteId);

                foreach ($this->plan($owner, $target, $site) as $from => $to) {
                    if (!$executor->exists($executor->path($from)) || $from === $to) {
                        continue;
                    }

                    if ($executor->exists($executor->path($to))) {
                        /*
                         * An empty directory at the destination is leftover
                         * scaffolding from a previously failed run — this
                         * happens naturally, since `createDirectories()`
                         * already builds the new layout's skeleton ahead of
                         * time · rejecting all of these unconditionally
                         * would mean an admin has to ssh in and delete an
                         * empty folder by hand before they can even retry —
                         * work the system can do safely on its own.
                         *
                         * A destination that has **files inside it** is
                         * still rejected exactly as before — that's someone's
                         * actual data, and assuming it's safe to overwrite
                         * would mean deleting a customer's files with
                         * nobody having asked for that.
                         */
                        if ($this->isEmptyDir($executor, $to)) {
                            $executor->removePath($executor->path($to));
                        } else {
                            throw new ExecutionFailed(
                                "Destination {$to} already has files in it — cannot move, it would overwrite them\n\n"
                                . 'Check whether these are leftovers from a previous move, then move them out of the way before retrying',
                            );
                        }
                    }

                    $executor->makeDirectory($executor->path(dirname($to)), 0750);
                    $executor->rename($executor->path($from), $executor->path($to));

                    $done[] = ['from' => $from, 'to' => $to];
                }
            }
        } catch (\Throwable $e) {
            // Reverted in reverse order — every file has to be back in its original place before the error is thrown
            foreach (array_reverse($done) as $step) {
                $executor->rename($executor->path($step['to']), $executor->path($step['from']));
            }

            throw new ExecutionFailed(
                "Failed to move files, so everything was moved back to its original place — no site was damaged\n\n"
                . $e->getMessage(),
            );
        }

        return $done;
    }

    /**
     * Old path => new path for a single site
     *
     * A site with a Domain Pointer set doesn't move its docroot, since its
     * files sit outside its home deliberately, on the admin's own choice —
     * but its logs and backups still need to move to match the new layout.
     *
     * @return array<string,string>
     */
    private function plan(UserAccount $owner, SiteLayout $target, Site $site): array
    {
        $home = $owner->home();
        $domain = $site->domain;
        $from = $owner->layout();
        $isMain = $owner->isMainDomain($domain);

        $children = [
            $from->logDir($home, $domain) => $target->logDir($home, $domain),
            $from->backupDir($home, $domain) => $target->backupDir($home, $domain),
        ];

        if ($site->docrootOverride === '') {
            $children[$from->docroot($home, $domain, $isMain)] = $target->docroot($home, $domain, $isMain);
        }

        $stateFrom = $from->stateDir($home, $domain);
        $stateTo = $target->stateDir($home, $domain);

        /*
         * stateDir's move order flips depending on which side it's the "parent" of
         *
         * The phpcp layout places log/backup/docroot **underneath**
         * stateDir, while cpanel places them separately · the two
         * directions therefore need opposite orders, and using a single
         * fixed order always breaks one direction or the other:
         *
         *   phpcp → cpanel  move the children out first, then the now-empty parent
         *                   (moving the parent first = the already-computed children point at a location that no longer exists)
         *
         *   cpanel → phpcp  move the parent first, so the children have somewhere to land
         *                   (moving the children first = the system creates
         *                   the parent folder on its own to hold them, then
         *                   the real parent move gets rejected because the
         *                   destination already exists — found on the real
         *                   server while reverting a move, 2026-08-13)
         */
        $stateIsParentOfTarget = self::isUnder(reset($children) ?: '', $stateTo);

        return $stateIsParentOfTarget
            ? [$stateFrom => $stateTo] + $children
            : $children + [$stateFrom => $stateTo];
    }

    /**
     * Is this directory genuinely empty? — empty = safe to delete without damaging anything
     *
     * Counts hidden files too · this project's `listDirectory()` already
     * excludes `.` and `..` from its result, so an empty list genuinely
     * means empty, not empty because of a filtering mistake.
     */
    private function isEmptyDir(Executor $executor, string $path): bool
    {
        try {
            return $executor->listDirectory($executor->path($path)) === [];
        } catch (\Throwable) {
            // Unreadable = unknown what's inside, so it must not be deleted
            return false;
        }
    }

    /** Is $path under $parent? (compared as paths, not as text) */
    private static function isUnder(string $path, string $parent): bool
    {
        $parent = rtrim($parent, '/');

        return $parent !== '' && str_starts_with(rtrim($path, '/').'/', $parent.'/');
    }

    /**
     * @param  list<int> $siteIds
     * @return list<string>
     */
    private function rebuild(Executor $executor, Context $context, array $siteIds): array
    {
        $repository = new SiteRepository($context->db);
        $provisioner = SiteCapability::provisionerFor($context);
        $transaction = new ConfigTransaction($executor);
        $rebuilt = [];
        $last = null;

        foreach ($siteIds as $siteId) {
            $site = $repository->load((int) $siteId);

            $provisioner->createDirectories($executor, $site);
            $provisioner->stageConfigs(
                $transaction,
                $site,
                $executor,
                $repository->pointerDocrootsOwnedBy($site->owner->userId, $site->phpVersion),
            );
            $provisioner->setOwnership($executor, $site);

            $rebuilt[] = $site->domain;
            $last = $site;
        }

        if ($last !== null) {
            $transaction->commit(static fn (): array => $provisioner->validate($executor, $last));
            $provisioner->reload($executor, $last);
        }

        // The path stored in the column must match the new reality, or the screen ends up reporting a lie
        foreach ($siteIds as $siteId) {
            $site = $repository->load((int) $siteId);
            $context->db->update('sites', ['docroot' => $site->docroot(), 'updated_at' => time()], ['id' => (int) $siteId]);
        }

        return $rebuilt;
    }

    private function save(Context $context, int $userId, string $layout): void
    {
        $context->db->update(
            'users',
            ['site_layout' => $layout, 'updated_at' => time()],
            ['id' => $userId],
        );
    }
}
