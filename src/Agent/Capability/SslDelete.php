<?php

declare(strict_types=1);

namespace Phpcp\Agent\Capability;

use Phpcp\Agent\Capability;
use Phpcp\Agent\Context;
use Phpcp\Agent\Executor\Executor;
use Phpcp\Support\Validator;

/**
 * Deletes a certificate
 *
 * HTTPS always has to be disabled first, never delete the file and sort it out
 * after — the vhost references the certificate file directly, and deleting it
 * while config still points there makes Apache refuse to load config for the
 * whole machine. The result is "every" site on the server going down, not just
 * the one whose certificate was deleted.
 */
final class SslDelete extends SslCapability implements Capability
{
    public static function name(): string
    {
        return 'ssl.delete';
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
        return 'Delete website SSL certificate';
    }

    public function validate(array $args): array
    {
        return [
            'site_id' => Validator::requireInt($args, 'site_id', 1),
            'confirm_domain' => trim((string) ($args['confirm_domain'] ?? '')),
        ];
    }

    public function run(array $args, Executor $executor, Context $context): array
    {
        $this->assertSiteAccess($context, $args['site_id']);

        $site = $this->loadSite($context, $args['site_id']);
        $certificate = $this->assertCertificateExists($executor, $site);

        if ($args['confirm_domain'] !== $site->domain) {
            throw new \Phpcp\Agent\ValidationError(
                'The confirmation domain name does not match the target website — cancelled for safety',
            );
        }

        // This order must never be reversed: disable HTTPS and rewrite the vhost
        // to stop referencing the certificate file first, only then delete the
        // file. Reversed, Apache is left holding config pointing at a file that no longer exists.
        if ($site->sslMode !== 'off') {
            $repository = $this->repository($context);

            $this->rewriteVhost(
                $context,
                $executor,
                $site->withSslMode('off'),
                static fn () => $repository->setSslMode($site->id, 'off'),
            );
        }

        if ($certificate['source'] === 'letsencrypt') {
            $this->certbot()->delete($executor, $site->domain);
        } else {
            $this->certbot()->deleteSelfSigned($executor, $site->domain);
        }

        return [
            'site_id' => $site->id,
            'domain' => $site->domain,
            'source' => $certificate['source'],
            'message' => sprintf(
                'Deleted the certificate for %s and automatically disabled HTTPS, so config never points at a file that no longer exists',
                $site->domain,
            ),
        ];
    }
}
