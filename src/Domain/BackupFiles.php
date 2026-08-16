<?php

declare(strict_types=1);

namespace Phpcp\Domain;

use Phpcp\Agent\Executor\Executor;
use Phpcp\Agent\ValidationError;

/**
 * One account's list of backup files — **read from the real folder, not from a table**
 *
 * ## Why the files themselves are the source of truth
 *
 * The `<home>/backup` folder is open to the customer through SFTP and the file
 * manager **deliberately** — they can download their own copy, and they can delete
 * it themselves · a table row recorded at creation time would go stale the instant
 * they delete a file: the screen would still show the entry, the restore button
 * would still be clickable, and it would fail exactly when the user needs it most ·
 * conversely, a file they copy back in themselves would never show up in the list at all.
 *
 * The `backup.json` already embedded inside each file already makes it fully
 * self-describing (domain · system user · layout · source machine), so there's no
 * need for a parallel row at all — see PLAN-BACKUP-V2 item B4.
 *
 * ## What this class answers, and what it doesn't
 *
 * Answers using **only the filename and its stat**, never touching what's inside the
 * file · reading `backup.json` requires one tar invocation per file, and an account
 * that's been backed up nightly for a year means 700 processes just to open the
 * screen once · whoever genuinely needs to know the origin for certain (at restore
 * time) reads the manifest themselves at that point.
 */
final class BackupFiles
{
    /** Site files — always end with this extension */
    public const SITE_SUFFIX = '.tar.gz';

    /** Database — `.sql.gz` since PLAN-BACKUP-V2 item B9 */
    public const DB_SUFFIX = '.sql.gz';

    /**
     * Ceiling on the number of files per account
     *
     * This folder belongs to the customer — they can put as many files in it as
     * they want · reading all of them and sending them out of the agent at once
     * would hit the protocol's frame size limit, the same problem `FileList` ran into.
     */
    public const MAX_FILES = 500;

    /**
     * Every backup file in this account's folder, newest first
     *
     * `$domains` is this account's own domains, used to tell which site a file
     * belongs to from its filename — a file that can't be matched (the customer
     * copied it in themselves, or it belongs to a site that's since been deleted)
     * is still always shown in the list, since it genuinely counts against their
     * quota · but it can't be auto-restored, and that must be made clearly visible.
     *
     * @param  list<string> $domains
     * @return list<array{name:string,path:string,type:string,domain:string,bytes:int,modified_at:int,restorable:bool}>
     */
    public static function listFor(Executor $executor, UserAccount $owner, array $domains): array
    {
        $dir = $owner->backupDir();
        $resolved = $executor->path($dir);

        // Never backed up = the folder doesn't exist yet · not an error
        if (!$executor->exists($resolved)) {
            return [];
        }

        $files = [];

        foreach ($executor->listDirectory($resolved) as $entry) {
            $name = (string) $entry['name'];

            if (($entry['type'] ?? '') !== 'file' || self::typeOf($name) === null) {
                continue;
            }

            $files[] = [
                'name' => $name,
                'path' => $dir . '/' . $name,
                'type' => (string) self::typeOf($name),
                'domain' => self::domainOf($name, $domains),
                'bytes' => (int) ($entry['size'] ?? 0),
                // Time comes from the file itself, not the filename — a file
                // that's been copied back in or renamed can still answer how
                // long it's actually existed
                'modified_at' => (int) ($entry['mtime'] ?? 0),
                'restorable' => self::typeOf($name) === 'site' && self::domainOf($name, $domains) !== '',
            ];
        }

        usort($files, static fn (array $a, array $b): int => $b['modified_at'] <=> $a['modified_at']);

        return array_slice($files, 0, self::MAX_FILES);
    }

    /**
     * A file's type from its extension — null = not a backup file
     *
     * Deliberately decided from the extension alone · the rest of the name belongs
     * to the customer — they can rename their own backup file and it's still a
     * backup file.
     */
    public static function typeOf(string $name): ?string
    {
        if (str_ends_with($name, self::DB_SUFFIX)) {
            return 'database';
        }

        return str_ends_with($name, self::SITE_SUFFIX) ? 'site' : null;
    }

    /**
     * The domain this filename refers to — an empty value means no match
     *
     * Compared against the account's real list of domains, instead of splitting the
     * filename on hyphens, since a domain name can contain hyphens itself
     * (`my-shop.example.com`) and can also contain the words `files` or `db` — a
     * rule based purely on name shape would split in the wrong place in those cases
     * with nothing to flag it.
     *
     * Picks the **longest** matching domain: `shop.example.com` and `example.com`
     * can both belong to the same account, and the first one's filename can't start
     * with the second — but the reverse can happen.
     *
     * @param list<string> $domains
     */
    public static function domainOf(string $name, array $domains): string
    {
        $best = '';

        foreach ($domains as $domain) {
            if ($domain !== '' && str_starts_with($name, $domain . '-') && strlen($domain) > strlen($best)) {
                $best = $domain;
            }
        }

        return $best;
    }

    /**
     * A filename that's acceptable from a caller — **name only, nothing else**
     *
     * This value gets appended to the customer's home path and is then used to
     * delete a file or extract an archive over a site · allowing even a single `/`
     * or `..` would mean the caller gets to choose which file on the machine the
     * system reaches for, then delete it with root privileges.
     *
     * @throws ValidationError
     */
    public static function assertName(string $name): string
    {
        $name = trim($name);

        if ($name === '' || $name !== basename($name) || str_contains($name, '..') || str_contains($name, "\0")) {
            throw new ValidationError('A backup filename must be a bare name, with no leading path');
        }

        if (self::typeOf($name) === null) {
            throw new ValidationError(
                'A backup filename must end with ' . self::SITE_SUFFIX . ' or ' . self::DB_SUFFIX,
            );
        }

        return $name;
    }

    /**
     * The full path of a file inside this account's folder — only after passing through {@see assertName()}
     *
     * @throws ValidationError
     */
    public static function resolve(UserAccount $owner, string $name): string
    {
        return $owner->backupDir() . '/' . self::assertName($name);
    }
}
