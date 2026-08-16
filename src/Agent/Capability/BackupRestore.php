<?php

declare(strict_types=1);

namespace Phpcp\Agent\Capability;

use Phpcp\Agent\Capability;
use Phpcp\Agent\Context;
use Phpcp\Agent\Executor\Executor;
use Phpcp\Agent\ValidationError;
use Phpcp\Domain\BackupFiles;
use Phpcp\Driver\BackupManager;
use Phpcp\Support\Validator;

/**
 * Restores a website from a file in its owner's backup folder — the riskiest
 * operation in this system
 *
 * Risky because it "overwrites something in active use" with old data. The
 * measures taken:
 *   1. Check the checksum first — a partially corrupted file is more dangerous than no file at all
 *   2. Automatically back up the current state before overwriting — a mistaken restore can still be reverted
 *   3. Extract into a temporary location, then swap — a failure partway through leaves no mixed-up files
 *   4. The domain name must be typed to confirm — guards against clicking the wrong row
 *
 * ## Accepts a filename, not a row id (PLAN-BACKUP-V2 item 4.5)
 *
 * Used to accept `backup_id`, then read the path and checksum from a table ·
 * that row could drift at any time, since a customer can delete their own file
 * over SFTP — the restore button would then point at something that no longer
 * existed · now it points directly at a file in the owner's folder, and **the
 * checksum is computed from that file right at that moment**.
 *
 * A freshly computed checksum is still fully useful: `restoreSite()` checks it
 * again after creating the safety backup and before extracting — that check
 * catches the case where the file was edited between those two moments, the
 * only window where the system writes anything into that same folder.
 */
final class BackupRestore extends BackupCapability implements Capability
{
    public static function name(): string
    {
        return 'backup.restore';
    }

    public function permission(): string
    {
        return 'backup.restore';
    }

    public function isMutating(): bool
    {
        return true;
    }

    public function summary(): string
    {
        return 'Restore website files from the owner\'s backup (always checks the checksum and backs up the original first)';
    }

    public function validate(array $args): array
    {
        return [
            'site_id' => Validator::requireInt($args, 'site_id', 1),
            'file' => BackupFiles::assertName(Validator::requireString($args, 'file', 255)),
            'confirm' => Validator::requireString($args, 'confirm', 253),
        ];
    }

    public function run(array $args, Executor $executor, Context $context): array
    {
        $site = $this->siteFor($context, $args['site_id']);

        if (BackupFiles::typeOf($args['file']) !== 'site') {
            // Restoring a database follows a very different process (the site
            // has to stop, tables cleared, then everything reimported) — doing
            // it halfway is more dangerous than not doing it · a customer can
            // already download their own .sql.gz file and import it through phpMyAdmin
            throw new ValidationError(
                'Only website files can be restored right now — download a database file to import it through phpMyAdmin instead',
            );
        }

        if ($args['confirm'] !== $site->domain) {
            throw new ValidationError('The confirmation domain name does not match the target website — cancelled for safety');
        }

        $archive = BackupFiles::resolve($site->owner, $args['file']);

        $this->assertFileExists($executor, $archive);
        $this->assertBelongsTo($executor, $archive, $site->domain);

        /*
         * The owner the restored files must belong to — not sending this means
         * the restored files end up owned by root (the directory) and by the
         * source's original uid (the files inside), which breaks instantly when
         * restoring across machines · see the full reasoning in BackupManager::restoreSite()
         */
        $owner = self::ownerString($context, $site->owner);

        $checksum = @hash_file('sha256', $executor->path($archive));

        if ($checksum === false) {
            throw new ValidationError('Failed to read the backup file to verify it');
        }

        $result = (new BackupManager())->restoreSite($executor, $site, $archive, $checksum, $owner);

        return [
            'site_id' => $site->id,
            'domain' => $site->domain,
            'file' => $args['file'],
            'entries' => $result['entries'],
            'safety_file' => basename($result['safety']),
            'message' => sprintf(
                'Restored %s from %s (%d item(s)) — the pre-restore state was backed up to %s',
                $site->domain,
                $args['file'],
                $result['entries'],
                basename($result['safety']),
            ),
        ];
    }

    /**
     * Does this file genuinely belong to this domain — asks the manifest inside the file, never guesses from the name
     *
     * **This check can't be skipped, ever since the folder belongs to the
     * customer** — they can rename files themselves and copy files in from
     * elsewhere · a name starting with `shop.example.com-files-` is therefore no
     * promise that what's inside actually belongs to shop.example.com ·
     * restoring the wrong one means overwriting a live site with another site's
     * files, recoverable only from the safety backup.
     *
     * An older file with no manifest at all (`readManifest()` returns null) is
     * rejected — "can't tell who it belongs to" must never be read as "assume it
     * belongs to whichever site the user currently has open".
     */
    private function assertBelongsTo(Executor $executor, string $archive, string $domain): void
    {
        $manifest = (new BackupManager())->readManifest($executor, $archive);

        if ($manifest === null) {
            throw new ValidationError(
                'This file has no ' . BackupManager::MANIFEST . ' inside it, so which website it backs up cannot be determined'
                . ' — it cannot be restored, since it might overwrite this site with another site\'s files',
            );
        }

        $inside = (string) ($manifest['domain'] ?? '');

        if ($inside !== $domain) {
            throw new ValidationError(sprintf(
                'This file is a backup of %s, not %s — restoring across sites is not possible through this page',
                $inside === '' ? 'an undetermined website' : $inside,
                $domain,
            ));
        }
    }
}
