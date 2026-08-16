<?php

declare(strict_types=1);

namespace Phpcp\Domain;

/**
 * The security score and its list of things to fix — PROMPT.md's Security section
 *
 * "Computing the score" is kept separate from "collecting the data," since the two
 * change on different timelines, and score computation must be testable without a
 * real server.
 *
 * The principle held throughout this whole file: **the score must not be gameable
 * by hiding a problem**. If a check can't be performed at all (e.g. the firewall's
 * status can't be read), that check counts as "unknown" and scores the same as not
 * passing, not skipped outright — because if it could be skipped, a machine so
 * broken that nothing can be checked at all would score 100, the exact opposite of
 * the truth.
 */
final class SecurityScore
{
    /** Passed — nothing to do */
    public const PASS = 'pass';

    /** Should be improved, but not yet dangerous */
    public const WARN = 'warn';

    /** Must be fixed */
    public const FAIL = 'fail';

    /** Couldn't be checked — scores the same as not passing, but tells the user honestly that it's unknown */
    public const UNKNOWN = 'unknown';

    /**
     * The share of credit each status earns
     *
     * WARN earns half, since it's something that "should" be done, not something
     * that "must" be done — giving it 0 would mean an admin who's already handled
     * everything important sees a low score and stops paying attention to it at all.
     */
    private const CREDIT = [
        self::PASS => 1.0,
        self::WARN => 0.5,
        self::FAIL => 0.0,
        self::UNKNOWN => 0.0,
    ];

    /**
     * Compute the overall weighted score
     *
     * @param list<array{status:string,weight:int}> $checks
     */
    public static function calculate(array $checks): int
    {
        $total = 0;
        $earned = 0.0;

        foreach ($checks as $check) {
            $weight = max(0, $check['weight']);
            $total += $weight;
            $earned += $weight * (self::CREDIT[$check['status']] ?? 0.0);
        }

        if ($total === 0) {
            return 0;
        }

        // Always round down — 99.6 must never become 100, since 100 implies "nothing left to do"
        return (int) floor($earned / $total * 100);
    }

    /** The grade used to pick a color and wording on screen */
    public static function grade(int $score): string
    {
        return match (true) {
            $score >= 90 => 'Good',
            $score >= 70 => 'Fair',
            $score >= 50 => 'Needs improvement',
            default => 'At risk',
        };
    }

    public static function tone(int $score): string
    {
        return match (true) {
            $score >= 90 => 'ok',
            $score >= 70 => 'warn',
            default => 'danger',
        };
    }

    public static function statusLabel(string $status): string
    {
        return match ($status) {
            self::PASS => 'Passed',
            self::WARN => 'Should be improved',
            self::FAIL => 'Must be fixed',
            default => 'Could not be checked',
        };
    }

    public static function statusTone(string $status): string
    {
        return match ($status) {
            self::PASS => 'ok',
            self::WARN => 'warn',
            self::FAIL => 'danger',
            default => 'muted',
        };
    }

    /**
     * Sort the list of things to fix by actual urgency
     *
     * Sorted by "impact" first, not by the order checks ran in — someone with time
     * to fix only one thing should fix the most important one, not whichever
     * happened to be checked first.
     *
     * @param list<array<string,mixed>> $checks
     * @return list<array<string,mixed>>
     */
    public static function recommendations(array $checks): array
    {
        $todo = array_values(array_filter(
            $checks,
            static fn (array $c): bool => $c['status'] !== self::PASS,
        ));

        usort($todo, static function (array $a, array $b): int {
            $rank = static fn (array $c): int => match ($c['status']) {
                self::FAIL => 0,
                self::UNKNOWN => 1,
                default => 2,
            };

            // A worse status comes first; if equal, the one with more weight comes first
            return [$rank($a), -$a['weight']] <=> [$rank($b), -$b['weight']];
        });

        return $todo;
    }
}
