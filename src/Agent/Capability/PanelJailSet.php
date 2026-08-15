<?php

declare(strict_types=1);

namespace Phpcp\Agent\Capability;

use Phpcp\Agent\Capability;
use Phpcp\Agent\Context;
use Phpcp\Agent\Executor\Executor;
use Phpcp\Agent\ValidationError;
use Phpcp\Domain\SettingsRepository;
use Phpcp\Driver\Security\Fail2banManager;
use Phpcp\Middleware\RateLimit;
use Phpcp\Support\Validator;

/**
 * Turns login brute-force protection on or off — enforced through fail2ban
 *
 * **Why this exists despite the app already having account lockout** — lockout only
 * stops one account at a time; someone cycling through `admin`, `root`,
 * `administrator` is never caught by it at all, and every attempt still costs one of
 * only four PHP-FPM workers. A ban at the firewall cuts them off before they ever
 * reach PHP.
 *
 * **The file is always written before the setting is saved** — swap that order and
 * the screen can report protection turned on while fail2ban actually rejected the
 * file, which is worse than not having this feature at all.
 */
final class PanelJailSet implements Capability
{
    public static function name(): string
    {
        return 'security.panel_jail_set';
    }

    public function permission(): string
    {
        return 'security.manage';
    }

    public function isMutating(): bool
    {
        return true;
    }

    public function summary(): string
    {
        return 'Configure login brute-force protection';
    }

    public function validate(array $args): array
    {
        /*
         * `mode` replaces the old on/off `enabled` — three states, off | notify | ban
         *
         * Still accepts the old `enabled` too, so an older caller doesn't break:
         * true = ban, since that was the only meaning "on" ever had.
         */
        $mode = Validator::optionalString($args, 'mode', '', 16);

        if ($mode === '') {
            $mode = Validator::requireBool($args, 'enabled')
                ? Fail2banManager::MODE_BAN
                : Fail2banManager::MODE_OFF;
        }

        if (!in_array($mode, Fail2banManager::modes(), true)) {
            throw new ValidationError('Invalid jail mode — must be off, notify, or ban');
        }

        // Turning it off means the rest of the fields don't need to be filled in at all
        if ($mode === Fail2banManager::MODE_OFF) {
            return ['mode' => Fail2banManager::MODE_OFF, 'enabled' => false];
        }

        $findSeconds = Validator::requireInt($args, 'find_seconds', 60, 86_400);

        /*
         * Minimum of 3 attempts — below that, someone who mistypes their password
         * twice with Caps Lock stuck on gets locked out of their own control panel,
         * locking the owner out of their own house to guard against a burglar who
         * hasn't shown up yet.
         */
        $maxRetry = Validator::requireInt($args, 'max_retry', 3, 100);

        /*
         * **The ceiling comes from the login page's own rate limiter, not a number
         * chosen on purpose.**
         *
         * A request rejected with 429 is cut off at the middleware, before it ever
         * reaches the controller, so it never produces a line in the audit log — one
         * IP can only produce as many "denied" lines as the quota allows (5 rapid
         * attempts, refilling one per minute). Measured on a real machine: firing 10
         * rapid attempts produced exactly one line; the rest were plain 429s.
         *
         * Setting maxretry above that ceiling means a jail that's turned on but can
         * never fire — worse than off, because the screen would say it's protecting.
         * Reject it outright and say what the real ceiling is.
         */
        $ceiling = RateLimit::maxLoginFailuresWithin($findSeconds);

        if ($maxRetry > $ceiling) {
            throw new ValidationError(sprintf(
                'Within %d seconds the system allows at most %d failed logins (the rate '
                . 'limiter drops the rest before the password is even checked) — a threshold '
                . 'of %d can never be reached. Lower it to %d or fewer, or widen the window.',
                $findSeconds,
                $ceiling,
                $maxRetry,
                $ceiling,
            ));
        }

        return [
            'mode' => $mode,
            'enabled' => true,
            'max_retry' => $maxRetry,
            'find_seconds' => $findSeconds,
            'ban_seconds' => Validator::requireInt($args, 'ban_seconds', 60, 604_800),
            'ignore_ips' => Fail2banManager::normalizeIgnoreList(
                Validator::optionalString($args, 'ignore_ips', '', 2000),
            ),
        ];
    }

    public function run(array $args, Executor $executor, Context $context): array
    {
        $settings = new SettingsRepository($context->db);
        $manager = (new Fail2banManager($executor))
            ->withNeverBan($settings->get('security.never_ban_ips'))
            ->withAlertBinary($context->config->paths->binary('phpcp-alert'));

        if ($args['mode'] === Fail2banManager::MODE_OFF) {
            $manager->removePanelLogin();
            $settings->save([
                'security.panel_jail.enabled' => '0',
                'security.panel_jail.mode' => Fail2banManager::MODE_OFF,
            ]);

            return [
                'mode' => Fail2banManager::MODE_OFF,
                'enabled' => false,
                'active' => false,
                'message' => 'Login brute-force protection turned off',
            ];
        }

        /*
         * The master switch being off means the admin chose not to use fail2ban on
         * this machine at all (limited RAM, say) — a jail write can't override that
         * choice silently, it has to say exactly where it's blocked.
         */
        if (!$settings->bool('security.fail2ban.enabled')) {
            throw new ValidationError(
                'fail2ban is turned off on this machine — turn on "Use fail2ban" on the security page first',
            );
        }

        // The text copy of the audit log is the only thing fail2ban can read — the
        // audit_log table is SQLite, which it can't parse (see AuditLog::mirror)
        $auditLog = $context->config->paths->logFile('audit');

        $settingsToWrite = [
            'mode' => $args['mode'],
            'max_retry' => $args['max_retry'],
            'find_seconds' => $args['find_seconds'],
            'ban_seconds' => $args['ban_seconds'],
            'ignore_ips' => $args['ignore_ips'],
        ];

        // File first, setting saved after — throw here and there's no stored value
        // claiming this is "on"
        $manager->applyPanelLogin($auditLog, $settingsToWrite);

        $settings->save([
            'security.panel_jail.enabled' => '1',
            'security.panel_jail.mode' => $args['mode'],
            'security.panel_jail.max_retry' => (string) $args['max_retry'],
            'security.panel_jail.find_seconds' => (string) $args['find_seconds'],
            'security.panel_jail.ban_seconds' => (string) $args['ban_seconds'],
            'security.panel_jail.ignore_ips' => $args['ignore_ips'],
        ]);

        return [
            'mode' => $args['mode'],
            'enabled' => true,
            'jail' => Fail2banManager::PANEL_LOGIN_JAIL,
            'log_path' => $auditLog,
            'status' => $manager->statusOf(Fail2banManager::PANEL_LOGIN_JAIL),
            'message' => $args['mode'] === Fail2banManager::MODE_NOTIFY
                ? sprintf(
                    'Notify mode turned on — %d failed logins within %d minutes will send you a message, **without banning**',
                    $args['max_retry'],
                    (int) round($args['find_seconds'] / 60),
                )
                : sprintf(
                    'Ban mode turned on — %d failed logins within %d minutes will result in a %d-minute ban',
                    $args['max_retry'],
                    (int) round($args['find_seconds'] / 60),
                    (int) round($args['ban_seconds'] / 60),
                ),
        ];
    }
}
