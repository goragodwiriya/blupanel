<?php

declare(strict_types=1);

namespace Phpcp\Http\Resource;

use Phpcp\Security\Permissions;

/**
 * One panel user
 *
 * `password_hash` and `totp_secret` are never in the result — picking fields
 * by name one at a time (not `...$row` followed by unset) is the main reason
 * the Resource layer exists at all: a column added in the future never leaks
 * out through the API by nobody's intention
 */
final class UserResource extends Resource
{
    public static function one(array $row): array
    {
        $role = self::string($row['role'] ?? '');

        return [
            'id' => (int) ($row['id'] ?? 0),
            'username' => self::string($row['username'] ?? ''),
            'role' => $role,
            // The role's display name is also sent, so the SPA doesn't need
            // to know the PHP-side lookup table · once phase C's i18n work is
            // done, the frontend will translate from `role` itself and this
            // field gets dropped — for now this is English source text,
            // untranslated, since this is a static Resource class with no
            // `$this->t()` access · every caller that puts this on an HTTP
            // response (MeController::show(), SessionController::state(),
            // UsersController::present()) wraps it in `$this->t()` itself before sending
            'role_label' => Permissions::roleLabel($role),
            // Two axes deliberately kept separate: status controls login
            // privileges · service_status controls the hosting service · before
            // migration 0005 these lived in different tables and could genuinely disagree
            'status' => self::string($row['status'] ?? 'active'),
            'service_status' => self::string($row['service_status'] ?? 'active'),
            // The status pill's color — sent from here so the template can
            // write `pill-${service_tone}` directly, with no lookup table needed on the JS side
            'service_tone' => match (self::string($row['service_status'] ?? 'active')) {
                'active' => 'ok',
                'suspended' => 'warn',
                'expired' => 'danger',
                default => 'muted',
            },
            'email' => self::string($row['email'] ?? ''),
            'expiry_at' => self::intOrNull($row['expiry_at'] ?? null),
            'two_factor_enabled' => self::bool($row['totp_enabled'] ?? 0),
            'must_change_password' => self::bool($row['must_change_password'] ?? 0),
            'last_login_at' => self::intOrNull($row['last_login_at'] ?? null),
            'last_login_ip' => self::string($row['last_login_ip'] ?? ''),
            'created_at' => self::intOrNull($row['created_at'] ?? null),
        ];
    }

    /**
     * A user with hosting data — the configured quota, how much is used, and website count
     *
     * Separate from one() because an admin account has no meaning for a quota
     * — stuffing every row with a 0 for everyone would leave the screen unable
     * to tell "unlimited" apart from "doesn't apply"
     *
     * @param array<string,mixed> $row
     * @param array<string,array{used:int,limit:int,label:string,unlimited:bool,disabled:bool}> $quota
     * @return array<string,mixed>
     */
    public static function withHosting(array $row, array $quota, int $siteCount): array
    {
        $diskQuotaMb = (int) ($row['disk_quota_mb'] ?? -1);
        $diskUsedMb = (int) ($row['disk_used_mb'] ?? 0);
        $diskPercent = $diskQuotaMb > 0 ? min(999, (int) round($diskUsedMb / $diskQuotaMb * 100)) : 0;

        return self::one($row) + [
            'quota' => $quota,
            'site_count' => $siteCount,
            // MB, not an item count, so it doesn't live in `quota` alongside domains/databases — PLAN-V2 phase E2
            'disk_quota_mb' => $diskQuotaMb,
            'disk_used_mb' => $diskUsedMb,
            'disk_quota_unlimited' => $diskQuotaMb === -1,
            'disk_quota_percent' => $diskPercent,
            // The pill's color follows the same thresholds quota.disk_check uses for alerts (80/90/100%)
            'disk_quota_tone' => match (true) {
                $diskQuotaMb === -1 => 'muted',
                $diskPercent >= 100 => 'danger',
                $diskPercent >= 80 => 'warn',
                default => 'ok',
            },
            // SFTP — PLAN-V2 phase E4 · `sftp_available` is separate from
            // `sftp_enabled` because the screen must state the difference
            // between "not included in the plan" (quota 0) and "included but not turned on yet"
            'sftp_enabled' => self::bool($row['sftp_enabled'] ?? 0),
            'sftp_available' => (int) ($row['quota_ftp_users'] ?? 0) !== 0,
            'sftp_enabled_at' => self::intOrNull($row['sftp_enabled_at'] ?? null),

            /*
             * The file layout — three values sent because the screen must state three different things
             *
             *   site_layout         this account's own chosen value (empty = never chosen) → sets the select
             *   site_layout_actual  the value genuinely in effect after filling in the system default → tells the admin what it currently is
             *   site_layout_docroot a real example path for this account → the answer an admin wants most
             *
             * Sending only the first value would show an empty field for an
             * account that's never chosen one, without saying what "empty"
             * means — which is the first question an admin would ask
             */
            'site_layout' => (string) ($row['site_layout'] ?? ''),
            'site_layout_actual' => self::account($row)->layout()->value,
            'site_layout_docroot' => self::account($row)->siteDocroot(
                (string) ($row['main_domain'] ?? '') !== '' ? (string) $row['main_domain'] : 'example.com',
            ),
            'main_domain' => (string) ($row['main_domain'] ?? ''),

            /*
             * The PHP values this account's pools are built from
             *
             * Sent as one nested object rather than twelve flat keys, so the
             * form binds to `php.memory_limit_mb` and a field added later needs
             * no change here at all · `php_limits` carries each field's own
             * min/max so the screen states the same rule the server enforces,
             * instead of a second copy of the numbers drifting in the HTML
             */
            'php' => \Phpcp\Domain\PhpSettings::fromRow($row)->toArray(),
            'php_limits' => \Phpcp\Domain\PhpSettings::FIELDS,
        ];
    }

    /**
     * This row's system account — can never return null, because the screen must always show a path
     *
     * A user with no system account yet (`system_user` empty) uses their
     * username instead, which is the same name that will be used at real
     * provisioning time — so the path shown is the path they'll actually get, not a fake placeholder
     *
     * @param array<string,mixed> $row
     */
    private static function account(array $row): \Phpcp\Domain\UserAccount
    {
        return \Phpcp\Domain\UserAccount::fromRow($row);
    }

    /**
     * The currently logged-in user, from the SPA's point of view
     *
     * Different from one() in that it attaches `permissions` too — the SPA
     * only uses this to show/hide menus · **real permission enforcement still
     * lives at the middleware and agent, exactly as before** (PLAN-V2 §4.4),
     * so this list is purely a UX concern — even if a user edits the value in
     * their browser, they gain no actual privilege
     *
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    public static function withPermissions(array $row): array
    {
        return self::one($row) + [
            'permissions' => Permissions::forRole(self::string($row['role'] ?? '')),
        ];
    }
}
