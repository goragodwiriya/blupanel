<?php

declare(strict_types=1);

namespace Phpcp\Http\V2;

use Phpcp\Http\ApiController;
use Phpcp\Kernel\Request;
use Phpcp\Kernel\Response;

/**
 * PHP versions on the machine — `/api/v2/php-versions`
 *
 * Always reads live status from the agent, never cached, because an admin can
 * install or remove PHP with apt outside the panel at any time — a cached list
 * would turn into a lie the moment they did.
 *
 * ## Two audiences, one endpoint, two payloads
 *
 * The **page** is an admin screen: it shows FPM status, memory, extension
 * counts and per-version site counts, and it is where PHP gets installed and
 * removed. Its menu entry and route ask for `php.manage`.
 *
 * The **endpoint** still has to answer `php.view`, because a customer's own
 * site page builds its PHP-version dropdown from it. That customer has no
 * business knowing how much memory each FPM pool uses, how many websites on
 * the machine are on 8.1, or which unit name to aim at — so a non-admin gets
 * back the version, whether it is still supported, and nothing else.
 *
 * Trimming here rather than in the template is the point: a value that never
 * leaves the server cannot be read out of the browser's network tab.
 *
 * Per the UX rule in PROMPT.md, **no button here controls the FPM process** —
 * start/stop lives only on the services resource. `fpm_status` is here to show
 * state, not to build a command from. Installing a *version* is a different
 * thing from starting a *process*, and it belongs here because it changes what
 * a website can be set to use.
 */
final class PhpVersionsController extends ApiController
{
    public function index(Request $request): Response
    {
        $data = $this->fetchPhpVersions($request);
        $versions = array_values($data['versions']);
        $canManage = $this->ctx->can('php.manage');

        /*
         * `?installed=1` — for a dropdown, which must only ever offer versions
         * a website can genuinely be moved to right now
         *
         * The page itself wants the whole list, uninstalled versions included,
         * because installing one is the thing it is for. A `<select>` wants the
         * opposite: an option that would first need somebody to install it is
         * an option that fails on save, several seconds after being chosen.
         */
        if ($request->get('installed') !== '') {
            $versions = array_values(array_filter(
                $versions,
                static fn (array $v): bool => ($v['installed'] ?? false) === true,
            ));
        }

        if (!$canManage) {
            return $this->ok(
                array_values(array_map(
                    static fn (array $v): array => [
                        'version' => (string) $v['version'],
                        'supported' => (bool) ($v['supported'] ?? false),
                        'is_default' => (bool) ($v['is_default'] ?? false),
                    ],
                    // A customer picks from what is installed · offering a
                    // version that would first have to be installed by somebody
                    // else is a dropdown entry that fails on save
                    array_filter($versions, static fn (array $v): bool => ($v['installed'] ?? false) === true),
                )),
                ['default' => (string) ($data['default'] ?? '')],
            );
        }

        $versions = array_map($this->decorate(...), $versions);

        return $this->ok($versions, [
            'installed_count' => (int) ($data['installed_count'] ?? 0),
            // The version a new website should get — the newest one that is
            // still receiving security fixes, decided by PhpSupport from
            // published dates rather than by a list anyone has to maintain
            'default' => (string) ($data['default'] ?? ''),
            'can_install' => (bool) ($data['can_install'] ?? false),
            'panel_version' => (string) ($data['panel_version'] ?? ''),
        ]);
    }

    /** Start an install — answers as soon as the job is running, not when it finishes */
    public function install(Request $request): Response
    {
        $result = $this->agent()->data('php.install', [
            'version' => trim($request->payloadString('version')),
        ], $this->ctx->actor($request));

        return $this->jobAccepted($result);
    }

    /**
     * Start a removal
     *
     * `DELETE` on the version itself · every reason it might be refused (a
     * website still using it, the panel's own version, the last one left) is
     * checked in the capability, which re-reads the site table rather than
     * trusting the row the button was pressed on.
     */
    public function destroy(Request $request): Response
    {
        $result = $this->agent()->data('php.remove', [
            'version' => $request->param('version'),
        ], $this->ctx->actor($request));

        return $this->jobAccepted($result);
    }

    /**
     * How the running job is getting on — polled by the page every few seconds
     *
     * A plain read, so it answers with `data` and no actions: the screen
     * decides what to do with a state of `running` versus `failed`, and a
     * notification fired from here on every poll would stack up one bar per
     * poll while the user watched.
     */
    public function job(Request $request): Response
    {
        $result = $this->agent()->data('php.job_status', [
            // Empty = whichever job the machine has · the page asks "is
            // anything happening", so a reload, or a second admin opening the
            // page, still finds the install that is running
            'version' => $request->get('version'),
        ], $this->ctx->actor($request));

        $state = (string) ($result['state'] ?? 'idle');

        return $this->ok([
            'version' => (string) ($result['version'] ?? ''),
            'state' => $state,
            'action' => (string) ($result['action'] ?? ''),
            'running' => $state === 'running',
            'finished' => in_array($state, ['done', 'failed'], true),
            'log' => (string) ($result['log'] ?? ''),
            // Ready-composed for the pill, the same as everywhere else — the
            // template can write `pill-${tone}` with no lookup table of its own
            'tone' => match ($state) {
                'running' => 'warn',
                'done' => 'ok',
                'failed' => 'danger',
                default => 'muted',
            },
            'label' => $this->t(match ($state) {
                'running' => 'Working...',
                'done' => 'Finished',
                'failed' => 'Failed',
                default => 'Nothing running',
            }),
        ]);
    }

    /**
     * A started job's response
     *
     * `completed()`, not `accepted()` — the screen has to be told to start
     * polling and to refresh the table, and 202 with a bare body would give it
     * neither. What did *not* happen yet is stated in the message itself.
     *
     * @param array<string,mixed> $result
     */
    private function jobAccepted(array $result): Response
    {
        return $this->completed(
            (string) ($result['message'] ?? 'Job started'),
            'phpVersions',
            is_array($result) ? $result : [],
        );
    }

    /**
     * The bits of presentation that belong to the server, not the template
     *
     * `data-template` can only substitute `${key}` — it has no conditionals —
     * so every "which colour" and "which word" decision has to arrive already
     * made, or it ends up duplicated across templates as a lookup table.
     *
     * @param array<string,mixed> $version
     * @return array<string,mixed>
     */
    private function decorate(array $version): array
    {
        $state = (string) ($version['state'] ?? 'installed');
        $supported = (bool) ($version['supported'] ?? false);
        $ends = (string) ($version['security_ends'] ?? '');

        $version['fpm_tone'] = match ($version['fpm_status'] ?? '') {
            'running' => 'ok',
            'failed' => 'danger',
            'transitioning' => 'warn',
            default => 'muted',
        };

        $version['state_tone'] = match ($state) {
            'installed' => 'ok',
            'available' => 'muted',
            default => 'warn',
        };

        $version['state_label'] = $this->t(match ($state) {
            'installed' => 'Installed',
            'available' => 'Not installed',
            default => 'Not in the repository',
        });

        $version['supported_tone'] = $supported ? 'ok' : 'warn';
        $version['supported_label'] = $supported ? $this->t('Supported') : $this->t('End of life');

        /*
         * Says *when*, not just *whether* — "supported" alone gives an admin
         * nothing to plan with · a version with eleven months left and one with
         * six weeks left look identical otherwise, and the second is the one
         * worth acting on now
         */
        $version['support_note'] = match (true) {
            $ends === '' => $this->t('Too new for this panel to know its end date'),
            $supported => $this->t('Security fixes until {date}', ['date' => $ends]),
            default => $this->t('No security fixes since {date}', ['date' => $ends]),
        };

        // The FPM status column has nothing to show for a version that is not
        // there — an empty pill reads as "broken", a stated absence does not
        $version['fpm_label'] = $state === 'installed'
            ? $this->t((string) $version['fpm_status'])
            : '—';

        return $version;
    }
}
