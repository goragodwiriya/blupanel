<?php

declare(strict_types=1);

namespace Phpcp\Agent\Capability;

use Phpcp\Agent\Capability;
use Phpcp\Agent\Context;
use Phpcp\Agent\Executor\Executor;
use Phpcp\Agent\ValidationError;
use Phpcp\Domain\Site;
use Phpcp\Domain\SiteRepository;

/**
 * Measures each website's real disk usage, then stores it in sites.disk_used_mb
 *
 * This column has existed since the very first migration, but nothing ever
 * computed it — the number on screen was always 0 on real machines, and the disk
 * quota (Phase E2) could never be enforced without this value.
 *
 * Marked "read-only" on purpose: it changes nothing on the machine at all. What
 * it writes is the measured value into the panel's own table, not an action that
 * needs to be reverted or investigated. A useful side effect is that it never
 * adds an audit log entry every 15 minutes forever, and it gets a real Executor
 * even in dryrun mode, so it still measures correctly.
 */
final class DiskUsage implements Capability
{
    private const DU = '/usr/bin/du';

    /** A large site takes a while, but still needs a ceiling, or the next cycle stacks up into a growing queue */
    private const TIMEOUT = 120;

    public static function name(): string
    {
        return 'disk.usage';
    }

    public function permission(): string
    {
        return 'site.view';
    }

    public function isMutating(): bool
    {
        return false;
    }

    public function summary(): string
    {
        return 'Compute website disk usage';
    }

    public function validate(array $args): array
    {
        if (!isset($args['site_id']) || $args['site_id'] === '' || $args['site_id'] === null) {
            return [];
        }

        if (!is_int($args['site_id']) && !(is_string($args['site_id']) && ctype_digit($args['site_id']))) {
            throw new ValidationError('Website id must be a number');
        }

        return ['site_id' => (int) $args['site_id']];
    }

    public function run(array $args, Executor $executor, Context $context): array
    {
        $repository = new SiteRepository($context->db);

        // Since migration 0006, disk quota moved to live on users — sites no
        // longer has a disk_quota_mb column (this query used to reference a
        // column that no longer existed, causing an SQL error every time the
        // scheduler called it — caught while writing Phase E2, which depends on this disk_used_mb value)
        $rows = isset($args['site_id'])
            ? $context->db->all('SELECT id FROM sites WHERE id = :id', ['id' => $args['site_id']])
            : $context->db->all('SELECT id FROM sites ORDER BY id');

        $sites = [];
        $failed = 0;
        $totalMb = 0;

        foreach ($rows as $row) {
            $site = $repository->load((int) $row['id']);

            if ($site === null) {
                continue;
            }

            try {
                $usedMb = $this->measure($executor, $site);
            } catch (\Throwable $e) {
                // One site that can't be measured (folder gone, system user deleted) must never fail the whole cycle
                $failed++;
                $sites[] = [
                    'site_id' => $site->id,
                    'domain' => $site->domain,
                    'error' => $e->getMessage(),
                ];

                continue;
            }

            $context->db->update(
                'sites',
                ['disk_used_mb' => $usedMb, 'updated_at' => time()],
                ['id' => $site->id],
            );

            $totalMb += $usedMb;
            $sites[] = [
                'site_id' => $site->id,
                'domain' => $site->domain,
                'disk_used_mb' => $usedMb,
            ];
        }

        $measured = count($sites) - $failed;

        return [
            'sites' => $sites,
            'measured' => $measured,
            'failed' => $failed,
            'total_mb' => $totalMb,
            // A site that couldn't be measured has to be in the summary message,
            // not hidden inside a sub-list nobody opens — a missing website
            // folder is a real problem someone needs to see, not just a missing number
            'message' => $failed === 0
                ? sprintf('Measured disk usage for %d website(s), %d MB total', $measured, $totalMb)
                : sprintf('Measured disk usage for %d website(s), %d MB total · %d website(s) could not be measured', $measured, $totalMb, $failed),
        ];
    }

    /**
     * A website's home's total size in MB
     *
     * Walks the files under the site owner's own privileges, per ARCHITECTURE
     * §4.4 — root never has to walk into a file tree the user controls
     * themselves, and `du` already doesn't follow symlinks by default, so it
     * can't be tricked into counting someone else's /var or /home through a link.
     */
    private function measure(Executor $executor, Site $site): int
    {
        $path = $executor->path($site->root());

        if (!$executor->exists($path)) {
            throw new \RuntimeException('The website\'s directory was not found');
        }

        $result = $executor->asUser($site->systemUser(), static function () use ($executor, $path): array {
            // -s a single summary total · -k in kilobytes (the same across every
            // distro, no need to guess the block size)
            // -x never crosses a filesystem — if something is mounted inside, it doesn't get double-counted
            $exec = $executor->exec([self::DU, '-sk', '-x', '--', $path], timeout: self::TIMEOUT);

            return ['ok' => $exec->ok(), 'out' => $exec->output(), 'err' => trim($exec->stderr)];
        });

        if (($result['ok'] ?? false) !== true) {
            throw new \RuntimeException(mb_substr((string) ($result['err'] ?? 'Failed to measure size'), 0, 200));
        }

        $out = (string) ($result['out'] ?? '');

        if (preg_match('/^(\d+)/', $out, $m) !== 1) {
            throw new \RuntimeException('Failed to read du\'s output');
        }

        return (int) ceil(((int) $m[1]) / 1024);
    }
}
