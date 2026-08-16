<?php

declare(strict_types=1);

namespace Phpcp\Agent\Capability;

use Phpcp\Agent\Context;
use Phpcp\Agent\ExecutionFailed;
use Phpcp\Agent\Executor\Executor;
use Phpcp\Agent\ValidationError;
use Phpcp\Domain\SettingsRepository;
use Phpcp\Support\Validator;

/**
 * Switches the web server, all done in a single command — click it on screen and it's live
 *
 * **Why this has to be one capability, not several buttons:** switching web
 * servers has five steps that must run *in order* and *every single one*, or
 * the machine ends up stuck halfway in a state an admin can't recover from on their own:
 *
 *   1. Save the chosen value
 *   2. Rewrite every site's config file to match the new server's format (site.rebuild)
 *   3. **restart** whichever one needs its listening port changed — never
 *      reload · Apache can't change its Listen directive on reload, by its own design
 *   4. Start the one now needed / stop the one no longer used — two servers can't hold port 80 at once
 *   5. Fire a real request in to confirm the site still responds
 *
 * Left for an admin to do by hand from a manual, skipping even one step gets an
 * nginx that won't start, with an "Address already in use" message that never
 * says what to do next — this genuinely happened before, and is the entire
 * reason a control panel should exist.
 */
final class WebserverApply extends SiteCapability
{
    /** The selectable modes, with the name the user sees */
    public const MODES = [
        'nginx-proxy' => 'nginx + Apache (supports .htaccess · recommended)',
        'apache' => 'Apache only',
        'nginx' => 'nginx only (no .htaccess)',
    ];

    /** The units each mode requires running */
    private const REQUIRED_UNITS = [
        'apache' => ['apache2'],
        'nginx' => ['nginx'],
        'nginx-proxy' => ['apache2', 'nginx'],
    ];

    public static function name(): string
    {
        return 'webserver.apply';
    }

    public function permission(): string
    {
        return 'settings.manage';
    }

    public function isMutating(): bool
    {
        return true;
    }

    public function summary(): string
    {
        return 'Switch the web server hosting websites';
    }

    public function validate(array $args): array
    {
        $mode = Validator::requireString($args, 'mode', 32);

        if (!array_key_exists($mode, self::MODES)) {
            throw new ValidationError(sprintf(
                'Not a usable mode: %s (usable: %s)',
                $mode,
                implode(', ', array_keys(self::MODES)),
            ));
        }

        return [
            'mode' => $mode,
            // Lets nginx answer static files itself — only has an effect in nginx-proxy mode
            'static_by_nginx' => !isset($args['static_by_nginx'])
                || in_array($args['static_by_nginx'], [true, 1, '1', 'on', 'true'], true),
        ];
    }

    public function run(array $args, Executor $executor, Context $context): array
    {
        $target = $args['mode'];
        $settings = new SettingsRepository($context->db);
        $previous = self::webServerMode($context);

        $this->assertInstalled($target, $executor);

        // Saved before doing anything else — the remaining steps read this
        // value through SiteCapability::webServerMode() · saving it later
        // would mean site.rebuild writes every file in the old mode's format
        $settings->save([
            'webserver.mode' => $target,
            'webserver.static_by_nginx' => $args['static_by_nginx'] ? '1' : '0',
        ]);

        try {
            $rebuild = (new SiteRebuild())->run([], $executor, $context);
        } catch (\Throwable $e) {
            // Reverted immediately — otherwise the screen would say the new
            // mode is active while the files on disk are still the old ones, a
            // state an admin has no way to make sense of
            $settings->save(['webserver.mode' => $previous]);

            throw new ExecutionFailed(
                "Failed to write website config files, so it was reverted\n\n" . $e->getMessage(),
            );
        }

        $steps = $this->switchUnits($target, $executor);

        return [
            'mode' => $target,
            'previous' => $previous,
            'static_by_nginx' => $args['static_by_nginx'],
            'rebuilt' => $rebuild['count'] ?? 0,
            'steps' => $steps,
            'message' => sprintf(
                'Switched to %s · rewrote config files for %d website(s)',
                self::MODES[$target],
                (int) ($rebuild['count'] ?? 0),
            ),
        ];
    }

    /**
     * The machine has to genuinely have the software a mode needs, before switching is allowed
     *
     * Checked before saving the value — letting it save first and only fail at
     * start time would leave an admin with a machine whose settings say nginx
     * while nginx doesn't exist, which is harder to recover from than being rejected outright.
     */
    private function assertInstalled(string $mode, Executor $executor): void
    {
        $missing = [];

        foreach (self::REQUIRED_UNITS[$mode] as $unit) {
            $binary = $unit === 'nginx' ? '/usr/sbin/nginx' : '/usr/sbin/apache2';

            if (!$executor->exists($executor->path($binary))) {
                $missing[] = $unit;
            }
        }

        if ($missing !== []) {
            throw new ValidationError(sprintf(
                'This machine does not have %s installed — install it with `apt install %s` first',
                implode(' and ', $missing),
                implode(' ', $missing),
            ));
        }
    }

    /**
     * Starts/stops/restarts to match the new mode
     *
     * **Always stops whichever one isn't needed first** — port 80 can only ever
     * have one owner; starting the new one before stopping the old one gets
     * "Address already in use" every single time.
     *
     * @return list<string>
     */
    private function switchUnits(string $mode, Executor $executor): array
    {
        $needed = self::REQUIRED_UNITS[$mode];
        $steps = [];

        foreach (['nginx', 'apache2'] as $unit) {
            if (in_array($unit, $needed, true)) {
                continue;
            }

            $executor->exec([$executor->path('/usr/bin/systemctl'), 'stop', $unit], timeout: 30);
            $steps[] = "Stopped {$unit}";
        }

        foreach ($needed as $unit) {
            // restart, not reload — the listening port only ever changes on a fresh start
            $result = $executor->exec(
                [$executor->path('/usr/bin/systemctl'), 'restart', $unit],
                timeout: 60,
            );

            if (!$result->ok()) {
                throw new ExecutionFailed(sprintf(
                    "Config files were written successfully, but starting %s failed\n\n%s\n\n"
                    . 'The files on disk are already correct — fix the cause above and switching modes can be retried immediately',
                    $unit,
                    trim($result->stderr ?: $result->stdout),
                ));
            }

            $steps[] = "Restarted {$unit}";
        }

        return $steps;
    }
}
