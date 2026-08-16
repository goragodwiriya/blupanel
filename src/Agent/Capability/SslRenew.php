<?php

declare(strict_types=1);

namespace Phpcp\Agent\Capability;

use Phpcp\Agent\Capability;
use Phpcp\Agent\Context;
use Phpcp\Agent\Executor\Executor;
use Phpcp\Agent\ValidationError;
use Phpcp\Support\Validator;

/**
 * Renews a certificate right now
 *
 * Not normally something to click — certbot's own timer renews it automatically
 * with 30 days left. This button exists for right after adding a new domain to a
 * website, or after an automatic renewal failed and the cause has just been
 * fixed, wanting to know the result now instead of waiting for the next cycle.
 */
final class SslRenew extends SslCapability implements Capability
{
    public static function name(): string
    {
        return 'ssl.renew';
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
        return 'Renew SSL certificate';
    }

    public function validate(array $args): array
    {
        return [
            'site_id' => Validator::requireInt($args, 'site_id', 1),
            'force' => (bool) ($args['force'] ?? false),
        ];
    }

    public function run(array $args, Executor $executor, Context $context): array
    {
        $this->assertSiteAccess($context, $args['site_id']);

        $site = $this->loadSite($context, $args['site_id']);
        $certbot = $this->certbot();
        $before = $this->assertCertificateExists($executor, $site);

        if ($before['source'] !== 'letsencrypt') {
            throw new ValidationError(
                'A self-signed certificate cannot be renewed — issue a new one instead with the Install SSL button',
            );
        }

        $certbot->renew($executor, $site->domain, $args['force']);
        $after = $certbot->inspect($executor, $site);

        // The vhost already points at the same path, but Apache keeps the
        // certificate cached in memory — without a reload, visitors would keep
        // getting the old certificate until something else triggers a restart
        if ($site->sslMode !== 'off') {
            $this->rewriteVhost($context, $executor, $site);
        }

        $changed = $after['expires_at'] > $before['expires_at'];

        return [
            'site_id' => $site->id,
            'domain' => $site->domain,
            'changed' => $changed,
            'certificate' => $after,
            'message' => $changed
                ? sprintf('Renewed the certificate for %s — expires again in %d days', $site->domain, $after['days_left'])
                : sprintf(
                    'The certificate for %s is not due for renewal yet (%d days left), so it\'s '
                    . 'still the same one — to genuinely replace it right now, choose force renewal',
                    $site->domain,
                    $after['days_left'],
                ),
        ];
    }
}
