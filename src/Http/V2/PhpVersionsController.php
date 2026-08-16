<?php

declare(strict_types=1);

namespace Phpcp\Http\V2;

use Phpcp\Http\ApiController;
use Phpcp\Kernel\Request;
use Phpcp\Kernel\Response;

/**
 * PHP versions installed on the machine — `/api/v2/php-versions`
 *
 * Always reads live status from the agent, never cached, because an admin
 * can install/remove PHP via apt outside the panel at any time — a cached
 * list would turn into a lie the moment they do that
 *
 * Per the UX rule in PROMPT.md: this page is read-only data, **no button
 * controls the FPM process here** — start/stop lives only on the services
 * resource (the `fpm_running` value is here to show status, not to build a command button)
 */
final class PhpVersionsController extends ApiController
{
    public function index(Request $request): Response
    {
        $data = $this->fetchPhpVersions($request);
        $versions = $data['versions'];

        // The pill's color comes from the server, so the template can write
        // `pill-${..._tone}` directly, with no status-to-color lookup table duplicated on screen
        $versions = array_map(static function (array $v): array {
            $v['fpm_tone'] = match ($v['fpm_status'] ?? '') {
                'running' => 'ok',
                'failed' => 'danger',
                'transitioning' => 'warn',
                default => 'muted',
            };
            $v['supported_tone'] = ($v['supported'] ?? false) ? 'ok' : 'warn';
            // `data-template` can only substitute ${key} directly, it has no
            // conditional support — a ready-composed text field must be
            // supplied, rather than having the template compute it from a boolean itself
            $v['supported_label'] = ($v['supported'] ?? false) ? 'Supported' : 'End of life';

            return $v;
        }, $versions);

        return $this->ok($versions, [
            'installed_count' => (int) ($data['installed_count'] ?? count($versions)),
            // The version recommended for a new website — the first one after sorting is the one genuinely able to serve
            'default' => $versions[0]['version'] ?? ($data['default'] ?? null),
        ]);
    }
}
