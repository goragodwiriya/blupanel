<?php

declare(strict_types=1);

namespace Phpcp\Agent\Capability;

use Phpcp\Agent\Capability;
use Phpcp\Agent\Context;
use Phpcp\Agent\Executor\Executor;
use Phpcp\Domain\AlertRules;
use Phpcp\Domain\Notifier;
use Phpcp\Domain\ServiceCatalog;

/**
 * Checks every alert threshold and notifies only about what changed — PLAN-V2 phase E6
 *
 * Checks the four things that genuinely take down a hosting machine, in order of how often they happen:
 *   1. **Disk full** — MariaDB can't write, every site goes down at once
 *   2. **Memory/load** — sites become slow enough to time out before answering
 *   3. **A critical service stopped** — Apache/PHP-FPM/MariaDB died and nobody knew until a customer called
 *   4. **A certificate is about to expire** — the browser shows a full-page red warning
 *
 * **The decision of "should this actually be sent" lives entirely in
 * {@see AlertRules}** — this class only measures values and passes them
 * along · kept separate because the anti-spam rules (notify on entering a
 * bad state · notify again if it gets worse · stay quiet in between) are
 * logic that needs to be tested by simulating the passage of time, which is
 * impossible if it's tied to reading live values off the real machine.
 *
 * Marked **read-only**, like `disk.usage`/`metrics.record` — changes
 * nothing on the machine, only writes to the panel's own status table, and
 * a job that runs every 5 minutes must never add entries to the audit log.
 */
final class AlertCheck implements Capability
{
    public static function name(): string
    {
        return 'alert.check';
    }

    public function permission(): string
    {
        return 'dashboard.view';
    }

    public function isMutating(): bool
    {
        return false;
    }

    public function summary(): string
    {
        return "Check the machine's alert thresholds and notify on anything abnormal";
    }

    public function validate(array $args): array
    {
        return [];
    }

    public function run(array $args, Executor $executor, Context $context): array
    {
        $rules = new AlertRules($context->db);
        // The executor is also passed in so email notifications can be sent (needs to call sendmail)
        $notifier = new Notifier($context->db, $executor);

        $checked = [];
        $sent = 0;

        foreach ($this->collect($executor, $context) as $alert) {
            $decision = $rules->evaluate($alert['key'], $alert['level'], $alert['value']);

            $checked[] = [
                'key' => $alert['key'],
                'level' => $alert['level'] ?? 'ok',
                'value' => $alert['value'],
                'notified' => $decision['notify'],
                'reason' => $decision['reason'],
            ];

            if (!$decision['notify']) {
                continue;
            }

            $recovered = $decision['reason'] === 'recovered';

            $sent += $notifier->send(
                'alert',
                ($recovered ? 'Recovered: ' : '') . $alert['title'],
                $recovered ? $alert['recovery'] : $alert['body'],
                $recovered ? 'ok' : ($alert['level'] === 'critical' ? 'danger' : 'warn'),
            ) ? 1 : 0;
        }

        /*
         * A threshold this run didn't check must also disappear from the list.
         *
         * `collect()` skips services not installed on this machine
         * (correct — not an abnormality) — but if that service had
         * previously stopped while it was still installed, its row would
         * stay stuck in the table forever, since nothing ever evaluates
         * that key again · an admin would see "a problem stuck there" that
         * never clears no matter what they click, and eventually stop
         * trusting this section entirely — more dangerous than not having
         * it at all.
         */
        $forgotten = $rules->forgetOthers(array_column($checked, 'key'));

        return [
            'checked' => count($checked),
            'notified' => $sent,
            'forgotten' => $forgotten,
            'alerts' => $checked,
            'channels' => $notifier->activeChannels(),
            'message' => sprintf('Checked %d threshold(s) · sent %d notification(s)', count($checked), $sent),
        ];
    }

    /**
     * Measures every threshold and returns the list for `AlertRules` to decide on
     *
     * Deliberately returns **every threshold, including the ones that are
     * fine** (level = null) — `AlertRules` needs to know a threshold that
     * used to be abnormal has gone back to normal, or it can never send a
     * "recovered" message and clear its own state.
     *
     * @return list<array{key:string,level:string|null,value:float,title:string,body:string,recovery:string}>
     */
    private function collect(Executor $executor, Context $context): array
    {
        $alerts = [];
        $metrics = (new SystemMetrics())->run([], $executor, $context);

        // --- Resources measured as a percentage ---
        foreach (AlertRules::THRESHOLDS as $type => [$warning, , $label]) {
            $percent = (float) ($metrics[$type]['percent'] ?? 0);
            $used = (int) ($metrics[$type]['used'] ?? 0);
            $total = (int) ($metrics[$type]['total'] ?? 0);

            $alerts[] = [
                'key' => $type,
                'level' => AlertRules::levelForPercent($type, $percent),
                'value' => $percent,
                'title' => sprintf('%s at %.1f%% used', $label, $percent),
                'body' => sprintf(
                    "%s: %s of %s (%.1f%%)\nWarning threshold at %.0f%%",
                    $label,
                    $this->bytes($used),
                    $this->bytes($total),
                    $percent,
                    $warning,
                ),
                'recovery' => sprintf('%s is back down to %.1f%%', $label, $percent),
            ];
        }

        // --- Load average per core ---
        $load1 = (float) ($metrics['load'][1] ?? 0);
        $cores = max(1, (int) ($metrics['cores'] ?? 1));

        $alerts[] = [
            'key' => 'load',
            'level' => AlertRules::levelForLoad($load1, $cores),
            'value' => $load1,
            'title' => sprintf('Load average %.2f (%d core(s))', $load1, $cores),
            'body' => sprintf(
                "1-minute load average: %.2f on a %d-core machine (%.2f per core)\n"
                . 'Above one per core genuinely means work is queued up',
                $load1,
                $cores,
                $load1 / $cores,
            ),
            'recovery' => sprintf('Load is back down to %.2f', $load1),
        ];

        // --- Critical services that have stopped ---
        //
        // Some kinds of service are **used one at a time**: a machine serves
        // websites with either Apache or Nginx, never both · the same is
        // true of MariaDB and MySQL, which share the same port.
        //
        // The question that genuinely needs alerting on is **"is a web
        // server still running at all"**, not "is nginx running" — this
        // very machine is an example: nginx is installed and enabled, but
        // fails to start because port 80 is held by Apache, which is this
        // machine's **normal state**, not a reason to wake anyone up · if
        // each one alerted separately, it would alert every 6 hours forever.
        //
        // The opposite of php-fpm, where each version is genuinely separate
        // — a site configured to use 8.4 goes down the instant 8.4 dies, no
        // matter whether other versions are still running.
        $exclusive = [ServiceCatalog::KIND_WEBSERVER, ServiceCatalog::KIND_DATABASE];
        $groupAlive = [];       // kind → is at least one of them running yet
        $groupMembers = [];     // kind → the names genuinely installed on this machine
        $probes = [];

        foreach (ServiceCatalog::all() as $unit => $meta) {
            if (($meta['critical'] ?? false) !== true) {
                continue;   // A non-critical service can stop without waking anyone in the middle of the night
            }

            $status = ServiceProbe::read($executor, $unit);

            // Not installed on this machine = not an abnormality (e.g.
            // nginx on a machine using Apache, or a PHP version
            // ServiceCatalog knows about but isn't installed here)
            //
            // **Decided from `status`, never from `installed` alone** —
            // ServiceProbe's `probeFallback()` returns `installed => true`
            // as a blanket default whenever it can't guess the real status,
            // so that value alone can't be trusted · `status` instead goes
            // through `statusOf()`, computed from a genuine LoadState.
            //
            // A mistake made once before: the first pass filtered on a
            // `load` key that ServiceProbe **never actually returns**, so
            // the condition could never be true, and the system fired off 6
            // notifications in a row about php-fpm versions that were never
            // even installed — exactly the kind of spam AlertRules was
            // written to prevent.
            if (($status['status'] ?? '') === 'not_installed' || ($status['installed'] ?? true) === false) {
                continue;
            }

            $running = (bool) ($status['running'] ?? false);
            $kind = (string) ($meta['kind'] ?? '');

            if (in_array($kind, $exclusive, true)) {
                $groupAlive[$kind] = ($groupAlive[$kind] ?? false) || $running;
                $groupMembers[$kind][] = $meta['label'] ?? $unit;
                $probes[$kind][] = $unit;

                continue;   // The whole group is decided together after the loop finishes
            }

            $alerts[] = [
                'key' => 'service:' . $unit,
                'level' => $running ? null : 'critical',
                'value' => $running ? 1.0 : 0.0,
                'title' => sprintf('Service %s has stopped', $meta['label'] ?? $unit),
                'body' => sprintf(
                    "Service %s (%s) is not running\nIt can be restarted from the panel's \"Services\" page",
                    $meta['label'] ?? $unit,
                    $unit,
                ),
                'recovery' => sprintf('Service %s is running again', $meta['label'] ?? $unit),
            ];
        }

        // A kind used one at a time — alerts when **not a single one is
        // running left at all**, since that's genuinely where sites go down
        // · the key is the kind's name, not a unit name, so it can never
        // fire multiple separate alerts for the same underlying problem.
        foreach ($groupAlive as $kind => $alive) {
            $label = ServiceCatalog::KIND_WEBSERVER === $kind ? 'web server' : 'database';
            $members = implode(' or ', array_unique($groupMembers[$kind]));

            $alerts[] = [
                'key' => 'service-kind:' . $kind,
                'level' => $alive ? null : 'critical',
                'value' => $alive ? 1.0 : 0.0,
                'title' => sprintf('No %s is running at all', $label),
                'body' => sprintf(
                    "Not a single %s is running on this machine (checked: %s)\n"
                    . "Every website on this machine is currently unreachable\n"
                    . 'It can be restarted from the panel\'s "Services" page',
                    $label,
                    $members,
                ),
                'recovery' => sprintf('%s is running again', ucfirst($label)),
            ];
        }

        // --- Certificates nearing expiry ---
        $now = time();
        $certificates = $context->db->all(
            "SELECT domain, not_after FROM certificates WHERE not_after IS NOT NULL AND status != 'pending'",
        );

        foreach ($certificates as $certificate) {
            $daysLeft = (int) floor(((int) $certificate['not_after'] - $now) / 86400);

            $alerts[] = [
                'key' => 'cert:' . $certificate['domain'],
                'level' => AlertRules::levelForCertDays($daysLeft),
                'value' => (float) $daysLeft,
                'title' => sprintf('%s\'s certificate has %d day(s) left', $certificate['domain'], $daysLeft),
                'body' => sprintf(
                    "%s's SSL certificate expires in %d day(s) (%s)\n"
                    . 'certbot normally renews it on its own at 30 days out — fewer days than that means automatic renewal has a problem',
                    $certificate['domain'],
                    $daysLeft,
                    date('d/m/Y', (int) $certificate['not_after']),
                ),
                'recovery' => sprintf('%s\'s certificate has been renewed (%d day(s) left)', $certificate['domain'], $daysLeft),
            ];
        }

        return $alerts;
    }

    /** A human-readable size — a notification message has to be read on a phone and understood immediately */
    private function bytes(int $value): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $index = 0;

        while ($value >= 1024 && $index < count($units) - 1) {
            $value = (int) ($value / 1024);
            $index++;
        }

        return $value . ' ' . $units[$index];
    }
}
