<?php

declare(strict_types=1);

namespace Phpcp\Agent\Capability;

use Phpcp\Agent\Capability;
use Phpcp\Agent\Context;
use Phpcp\Agent\Executor\Executor;
use Phpcp\Agent\ValidationError;
use Phpcp\Domain\PhpSettings;
use Phpcp\Domain\Site;
use Phpcp\Domain\SiteRepository;
use Phpcp\Domain\UserAccount;
use Phpcp\Driver\ConfigTransaction;
use Phpcp\Driver\SiteProvisioner;
use Phpcp\Support\Validator;

/**
 * Sets one hosting account's PHP values — and rewrites its pools so they take effect now
 *
 * ## Why saving the value is only half the job
 *
 * These values live in an FPM pool file, not in the database that serves them.
 * Writing the column alone would leave the screen showing 512M while every site
 * on the account still runs at 64M, with nothing anywhere to say the two
 * disagree — the same failure mode as changing `site_layout` without moving the
 * files ({@see CustomerLayoutSet}). So this saves, rewrites every pool the
 * account has, validates, and reloads, in that order, as one command.
 *
 * ## Why the vhost is rewritten too
 *
 * On nginx, `client_max_body_size` has to be at least as large as
 * `post_max_size`, or nginx answers 413 before the request reaches PHP — the
 * upload fails and the PHP log has nothing in it, because PHP was never asked.
 * The vhost is generated from the same values, so it is rewritten in the same
 * transaction rather than left for the next unrelated site edit to fix.
 *
 * ## What happens when the new values do not survive validation
 *
 * The config files roll back on their own ({@see ConfigTransaction}), and the
 * columns are put back by hand here. Leaving the row updated after the files
 * reverted would be the worst of the two outcomes: the screen would report the
 * new value as saved while nothing on the machine is running it.
 */
final class CustomerPhpSet extends CustomerCapability implements Capability
{
    public static function name(): string
    {
        return 'customer.php_set';
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
        return "Set a hosting account's PHP values";
    }

    /**
     * @param array<string,mixed> $args
     * @return array<string,mixed>
     */
    public function validate(array $args): array
    {
        $clean = ['user_id' => Validator::requireInt($args, 'user_id', 1)];
        $given = 0;

        foreach (array_keys(PhpSettings::FIELDS) as $field) {
            // Absent = not sent, so unchanged · this form is a PATCH, not a
            // full replacement, so an admin can change one value from a script
            // without having to send the other eleven correctly
            if (!array_key_exists($field, $args) || $args[$field] === null) {
                continue;
            }

            $clean[$field] = PhpSettings::assertValue($field, $args[$field]);
            $given++;
        }

        if ($given === 0) {
            throw new ValidationError('At least one PHP value to change must be specified');
        }

        return $clean;
    }

    /**
     * @param array<string,mixed> $args
     * @return array<string,mixed>
     */
    public function run(array $args, Executor $executor, Context $context): array
    {
        $user = $this->loadHostingAccount($context, $args['user_id']);
        $owner = UserAccount::fromRow($user);
        $before = $owner->php;

        $after = PhpSettings::fromArray($args, $before);
        $after->assertConsistent();

        $changes = $after->diff($before);

        if ($changes === []) {
            return [
                'user_id' => $owner->userId,
                'username' => $owner->username,
                'php' => $after->toArray(),
                'changes' => [],
                'sites' => [],
                'message' => 'These are already the values in use — nothing to change',
            ];
        }

        $this->save($context, $owner->userId, $after);

        try {
            $rebuilt = $this->rebuild($executor, $context, $owner->userId);
        } catch (\Throwable $e) {
            // The files are already back to what they were · the row has to
            // follow, or the screen reports a value nothing is running
            $this->save($context, $owner->userId, $before);

            throw $e;
        }

        // The audit entry is written by Dispatcher around this call (ARCHITECTURE §4.1) —
        // `changes` is what makes it readable a month later
        return [
            'user_id' => $owner->userId,
            'username' => $owner->username,
            'php' => $after->toArray(),
            'changes' => $changes,
            'sites' => $rebuilt,
            'message' => $rebuilt === []
                ? sprintf('Saved PHP values for %s (no website yet, so nothing needed rewriting)', $owner->username)
                : sprintf(
                    'Saved PHP values for %s and rewrote the configuration of %d website(s)',
                    $owner->username,
                    count($rebuilt),
                ),
        ];
    }

    /**
     * Rewrites every pool and vhost this account has, then reloads what read them
     *
     * **Every PHP version the account uses is reloaded, not just one.** An
     * account with sites on 8.2 and 8.4 has two pool files; reloading only the
     * version of whichever site happened to be last would leave the other one
     * serving the old values with no sign that it had been missed.
     *
     * @return list<string> the domains whose files were rewritten
     */
    private function rebuild(Executor $executor, Context $context, int $userId): array
    {
        $repository = new SiteRepository($context->db);
        $siteIds = $repository->idsOwnedBy($userId);

        if ($siteIds === []) {
            return [];
        }

        $provisioner = SiteCapability::provisionerFor($context);
        $transaction = new ConfigTransaction($executor);

        /** @var array<string,Site> $perVersion one site per PHP version, to reload each pool exactly once */
        $perVersion = [];
        $rebuilt = [];

        foreach ($siteIds as $siteId) {
            $site = $repository->load((int) $siteId);

            if ($site === null) {
                continue;
            }

            $provisioner->stageConfigs(
                $transaction,
                $site,
                $executor,
                $repository->pointerDocrootsOwnedBy($userId, $site->phpVersion),
            );

            $perVersion[$site->phpVersion] = $site;
            $rebuilt[] = $site->domain;
        }

        if ($perVersion === []) {
            return [];
        }

        $transaction->commit(fn (): array => $this->validateAll($provisioner, $executor, $perVersion));

        foreach ($perVersion as $version => $site) {
            $provisioner->fpm()->reload($executor, $version);
        }

        $provisioner->webserver()->reload($executor);

        return $rebuilt;
    }

    /**
     * Every pool this account touches, plus the web server, checked before anything reloads
     *
     * @param array<string,Site> $perVersion
     * @return array{0:bool,1:string}
     */
    private function validateAll(SiteProvisioner $provisioner, Executor $executor, array $perVersion): array
    {
        foreach (array_keys($perVersion) as $version) {
            [$ok, $output] = $provisioner->fpm()->testConfig($executor, $version);

            if (!$ok) {
                return [false, "PHP-FPM {$version} configuration failed validation:\n" . $output];
            }
        }

        [$ok, $output] = $provisioner->webserver()->testConfig($executor);

        if (!$ok) {
            return [false, "Web server configuration failed validation:\n" . $output];
        }

        return [true, trim($output)];
    }

    private function save(Context $context, int $userId, PhpSettings $php): void
    {
        $context->db->update(
            'users',
            $php->toColumns() + ['updated_at' => time()],
            ['id' => $userId],
        );
    }
}
