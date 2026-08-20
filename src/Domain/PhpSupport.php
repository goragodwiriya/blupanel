<?php

declare(strict_types=1);

namespace Phpcp\Domain;

/**
 * Whether a PHP version is still getting security fixes — decided by date, not by a list
 *
 * ## The problem this replaces
 *
 * "Which versions are still supported" used to be a hand-written list in two
 * places that disagreed with each other: `PhpList::isSupported()` said
 * `8.5, 8.4, 8.3`, while `ServiceCatalog::PHP_EOL_VERSIONS` said `7.4, 8.0,
 * 8.1` — so 8.2 was simultaneously supported and end-of-life depending on
 * which screen asked. Both were correct on the day they were written and wrong
 * afterwards, because a list of supported versions is a fact that expires and
 * nothing in the code made that expiry visible.
 *
 * ## What replaces it
 *
 * The dates. PHP publishes each release's end-of-support date years ahead, so
 * they can be filled in once and left alone — the table below is already
 * correct until 2029. Nothing has to be edited when a version dies; the
 * comparison against today does it.
 *
 * ## And when a brand-new version appears
 *
 * A version newer than every key here is treated as **supported**, which is
 * the only answer that can be right: PHP has never released a version that was
 * already end-of-life. So the day PHP 8.6 lands in the repository it shows up
 * correctly labelled with no code change at all — which is the entire point,
 * since the panel now discovers installable versions from apt rather than from
 * a list of its own ({@see \Phpcp\Driver\Php\FpmManager::availableVersions()}).
 *
 * Source: https://www.php.net/supported-versions.php
 */
final class PhpSupport
{
    /**
     * When security support ends (or ended) for each release
     *
     * These are the dates PHP itself publishes. Add a line when a new major
     * release is announced — never to mark something end-of-life, which the
     * date already handles on its own.
     *
     * @var array<string,string>
     */
    private const SECURITY_ENDS = [
        '5.6' => '2018-12-31',
        '7.0' => '2019-01-10',
        '7.1' => '2019-12-01',
        '7.2' => '2020-11-30',
        '7.3' => '2021-12-06',
        '7.4' => '2022-11-28',
        '8.0' => '2023-11-26',
        '8.1' => '2025-12-31',
        '8.2' => '2026-12-31',
        '8.3' => '2027-12-31',
        '8.4' => '2028-12-31',
        '8.5' => '2029-12-31',
    ];

    /** @param int|null $now injectable so a test does not depend on the day it runs */
    public static function isSupported(string $version, ?int $now = null): bool
    {
        $ends = self::endsAt($version);

        // Newer than anything this table knows about — PHP has never shipped a
        // version that was end-of-life on arrival, so "supported" is the only
        // answer that can be right
        if ($ends === '') {
            return self::isNewerThanKnown($version);
        }

        return ($now ?? time()) <= strtotime($ends . ' 23:59:59');
    }

    /** '' = this table has never heard of the version */
    public static function endsAt(string $version): string
    {
        return self::SECURITY_ENDS[$version] ?? '';
    }

    /**
     * Newer than every version in the table
     *
     * Separated from `isSupported()` because the screen needs to say something
     * different about it: a version whose end date is genuinely known can show
     * that date, while one this table has not caught up with cannot pretend to.
     */
    public static function isNewerThanKnown(string $version): bool
    {
        if (!self::isValid($version)) {
            return false;
        }

        foreach (array_keys(self::SECURITY_ENDS) as $known) {
            if (version_compare($version, $known, '<=')) {
                return false;
            }
        }

        return true;
    }

    /**
     * Newest first — the order every list of versions in the panel is shown in
     *
     * `version_compare` and not a plain string sort, because a string sort puts
     * 8.10 before 8.2 · that is not hypothetical for a project on its fifth
     * minor release in the 8 series.
     *
     * @param list<string> $versions
     * @return list<string>
     */
    public static function sortNewestFirst(array $versions): array
    {
        usort($versions, static fn (string $a, string $b): int => version_compare($b, $a));

        return array_values($versions);
    }

    /**
     * The best version to give a new website
     *
     * The newest one that is still getting security fixes — never simply the
     * newest, because a machine can have a release candidate's packages
     * installed, and never a fixed name, which is what made this a line to
     * maintain in the first place.
     *
     * @param list<string> $installed
     */
    public static function preferred(array $installed, ?int $now = null): string
    {
        foreach (self::sortNewestFirst($installed) as $version) {
            if (self::isSupported($version, $now)) {
                return $version;
            }
        }

        // Every installed version is end-of-life · still has to answer with
        // something, since a website has to run on one of them
        return self::sortNewestFirst($installed)[0] ?? '';
    }

    /** The shape apt and `/etc/php` both use — `8.4`, never `8.4.3` and never `php8.4` */
    public static function isValid(string $version): bool
    {
        return preg_match('/^\d\.\d{1,2}$/', $version) === 1;
    }

    /**
     * Every version the panel knows a date for, newest first
     *
     * Used as the fallback catalogue on a machine where apt cannot be asked
     * (no apt at all, or a repository that is briefly unreachable) — better a
     * complete list that might be missing tomorrow's release than an empty page.
     *
     * @return list<string>
     */
    public static function known(): array
    {
        return self::sortNewestFirst(array_keys(self::SECURITY_ENDS));
    }
}
