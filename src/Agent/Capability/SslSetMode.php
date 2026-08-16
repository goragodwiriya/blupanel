<?php

declare(strict_types=1);

namespace Phpcp\Agent\Capability;

use Phpcp\Agent\Capability;
use Phpcp\Agent\Context;
use Phpcp\Agent\Executor\Executor;
use Phpcp\Agent\ValidationError;
use Phpcp\Support\Validator;

/**
 * Enables HTTPS / forces HTTPS / disables it — PROMPT.md specifies these as three actions
 *
 * All three collapse into one capability because they're the same database
 * value (`ssl_mode`), and the result is rewriting the vhost the same way in every
 * case — splitting this into three would mean three copies of the same code that
 * always need editing together.
 *
 * Enabling without a certificate is rejected right here, rather than letting
 * configtest catch it — Apache's error about failing to open a .pem file doesn't
 * help a user understand what to do next.
 */
final class SslSetMode extends SslCapability implements Capability
{
    private const LABELS = [
        'off' => 'Disable HTTPS',
        'on' => 'Enable HTTPS',
        'forced' => 'Force HTTPS',
    ];

    public static function name(): string
    {
        return 'ssl.set_mode';
    }

    public function permission(): string
    {
        return 'ssl.manage';
    }

    public function isMutating(): bool
    {
        return true;
    }

    public function summary(): string
    {
        return 'Enable/disable/force website HTTPS';
    }

    public function validate(array $args): array
    {
        return [
            'site_id' => Validator::requireInt($args, 'site_id', 1),
            'mode' => Validator::requireEnum($args, 'mode', ['off', 'on', 'forced']),
        ];
    }

    public function run(array $args, Executor $executor, Context $context): array
    {
        $this->assertSiteAccess($context, $args['site_id']);

        $site = $this->loadSite($context, $args['site_id']);
        $mode = $args['mode'];

        if ($site->sslMode === $mode) {
            return [
                'site_id' => $site->id,
                'domain' => $site->domain,
                'ssl_mode' => $mode,
                'changed' => false,
                'message' => sprintf('%s is already in "%s" state', $site->domain, self::LABELS[$mode]),
            ];
        }

        if ($mode !== 'off') {
            $this->assertCertificateExists($executor, $site);
        }

        $updated = $site->withSslMode($mode);
        $repository = $this->repository($context);

        // Saved to the database after the file is committed but before reload — see the reasoning in rewriteVhost()
        $this->rewriteVhost(
            $context,
            $executor,
            $updated,
            static fn () => $repository->setSslMode($site->id, $mode),
        );

        return [
            'site_id' => $site->id,
            'domain' => $site->domain,
            'ssl_mode' => $mode,
            'previous_mode' => $site->sslMode,
            'changed' => true,
            'message' => match ($mode) {
                'off' => sprintf('Disabled HTTPS for %s — the site is served over HTTP only', $site->domain),
                'on' => sprintf('Enabled HTTPS for %s — reachable at both http:// and https://', $site->domain),
                'forced' => sprintf(
                    'HTTPS forced for %s — every HTTP request will be redirected to HTTPS, '
                    . 'and browsers will remember this for 6 months (HSTS)',
                    $site->domain,
                ),
            },
        ];
    }
}
