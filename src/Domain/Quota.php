<?php

declare(strict_types=1);

namespace Phpcp\Domain;

/**
 * Quota value rules — the single source of truth for what resource types exist and
 * what their numbers mean
 *
 * The same rules used to be written in three places (the now-deleted
 * `CustomerRepository::assertCanCreateResource`, `QuotaChecker::canCreate`, and a
 * switch inside a capability), which was the root cause of a bug that broke the
 * quota system entirely: callers spoke in the singular ("about to create 1 domain"),
 * but the table used the plural column name (quota_domains) — when the names didn't
 * match, the lookup read back 0 and interpreted that as "quota disabled," so a
 * customer with a quota of 10 domains couldn't create a single site.
 *
 * This class therefore accepts both forms and always normalizes to a single name first.
 */
final class Quota
{
    /** Unlimited */
    public const UNLIMITED = -1;

    /** This resource type is disabled */
    public const DISABLED = 0;

    /**
     * Every resource type → [column, label, forbids zero, is an on/off toggle]
     *
     * Domains can't be 0, because a hosting account that can't create a single site
     * is an account with no purpose — to disable service, use service_status =
     * suspended instead, which carries the meaning more directly.
     *
     * **The fourth slot (`toggle`) marks a type whose number has no meaning, only
     * "on/off"** — since Phase E4, one hosting account has always had exactly one
     * SFTP account (the unit of privilege separation has been the user, not the
     * site, since migration 0006), so `quota_ftp_users` is a switch:
     * `0` = not included in the package · `-1`/`>0` = one account can be enabled.
     *
     * Declared here in one place so the screen, the form, and the validator all read
     * the same rule — this meaning changed back in Phase E4, but the screen kept
     * displaying it as a count, turning it into a number that was actively
     * misleading and couldn't be corrected from the web page at all.
     *
     * @var array<string,array{0:string,1:string,2:bool,3:bool}>
     */
    public const TYPES = [
        'domains'    => ['quota_domains',    'Domains',    true,  false],
        'subdomains' => ['quota_subdomains', 'Subdomains', false, false],
        'aliases'    => ['quota_aliases',    'Aliases',    false, false],
        'emails'     => ['quota_emails',     'Emails',     false, false],
        'databases'  => ['quota_databases',  'Databases',  false, false],
        'ftp_users'  => ['quota_ftp_users',  'SFTP',       false, true],
    ];

    /** Whether this type is an on/off toggle, not a count */
    public static function isToggle(string $type): bool
    {
        return self::TYPES[self::assertType($type)][3] ?? false;
    }

    /**
     * The singular names callers actually use → the resource type's real name
     *
     * @var array<string,string>
     */
    private const ALIASES = [
        'domain'    => 'domains',
        'subdomain' => 'subdomains',
        'alias'     => 'aliases',
        // wildcard is counted together with aliases — it has no quota of its own,
        // since one site can have at most one meaningful wildcard entry
        // (`*.example.com`); a separate counting column would add nothing except
        // one more field the admin has to configure without knowing how it
        // differs from the existing one.
        'wildcard'  => 'aliases',
        'email'     => 'emails',
        'database'  => 'databases',
        'ftp_user'  => 'ftp_users',
    ];

    /** Return the standard type name, or null if unrecognized */
    public static function normalize(string $type): ?string
    {
        $name = self::ALIASES[$type] ?? $type;

        return isset(self::TYPES[$name]) ? $name : null;
    }

    /** The standard type name — throws if unrecognized */
    public static function assertType(string $type): string
    {
        $name = self::normalize($type);

        if ($name === null) {
            throw new \InvalidArgumentException("Unrecognized resource type: {$type}");
        }

        return $name;
    }

    /** The column name in the users table */
    public static function column(string $type): string
    {
        return self::TYPES[self::assertType($type)][0];
    }

    /** The display label used on screen and in error messages */
    public static function label(string $type): string
    {
        return self::TYPES[self::assertType($type)][1];
    }

    /**
     * Validate that a quota value about to be saved is actually usable
     *
     * @throws \InvalidArgumentException
     */
    public static function assertValue(int $value, string $type): void
    {
        $name = self::assertType($type);
        [, $label, $forbidsZero] = self::TYPES[$name];

        if ($value < self::UNLIMITED) {
            throw new \InvalidArgumentException(
                "{$label} quota must be -1 (unlimited), 0 (disabled), or a positive number",
            );
        }

        if ($forbidsZero && $value === self::DISABLED) {
            throw new \InvalidArgumentException(
                "{$label} quota cannot be 0 — minimum 1, or -1 (unlimited)",
            );
        }
    }

    public static function isUnlimited(int $quota): bool
    {
        return $quota === self::UNLIMITED;
    }

    public static function isDisabled(int $quota): bool
    {
        return $quota === self::DISABLED;
    }

    /** Convert a quota value into display text */
    public static function format(int $quota): string
    {
        return match ($quota) {
            self::UNLIMITED => 'Unlimited',
            self::DISABLED => 'Disabled',
            default => (string) $quota,
        };
    }
}
