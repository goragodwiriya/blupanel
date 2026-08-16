<?php

declare(strict_types=1);

namespace Phpcp\Agent\Capability;

use Phpcp\Agent\Context;
use Phpcp\Agent\Executor\Executor;
use Phpcp\Agent\ValidationError;
use Phpcp\Domain\Site;
use Phpcp\Driver\ConfigTransaction;
use Phpcp\Driver\Ssl\CertbotManager;

/**
 * The shared base for capabilities that manage SSL certificates
 *
 * Extends SiteCapability because every SSL command is always bound to a single
 * website, and has to rewrite the vhost after a certificate changes — using the
 * exact same path as any other site edit, so ConfigTransaction, configtest, and
 * reload all still apply, with no shortcut that skips validation.
 */
abstract class SslCapability extends SiteCapability
{
    protected function certbot(): CertbotManager
    {
        return new CertbotManager();
    }

    /**
     * Rewrites the vhost and reloads, letting the caller insert a database save
     * in the middle
     *
     * The order matters a great deal, and has been wrong before: the database
     * used to be saved "after" reload. When reload failed (systemd unavailable,
     * say), the error threw before the save line ever ran — leaving the file on
     * disk holding the new value while the database still held the old one, and
     * the screen lying about it.
     *
     * Now the order is: commit the file → save the database → reload.
     * If reload fails, the database and the file still agree — all that's left is
     * getting the reload to succeed. And if configtest fails, ConfigTransaction
     * restores the original file and nothing has been saved at all.
     *
     * @param (callable(): void)|null $afterCommit
     */
    protected function rewriteVhost(
        Context $context,
        Executor $executor,
        Site $site,
        ?callable $afterCommit = null,
    ): void {
        $provisioner = $this->provisioner($context);
        $transaction = new ConfigTransaction($executor);

        $provisioner->stageConfigs(
            $transaction,
            $site,
            $executor,
            $this->repository($context)->pointerDocrootsOwnedBy($site->owner->userId, $site->phpVersion),
        );
        $transaction->commit(static fn (): array => $provisioner->validate($executor, $site));

        if ($afterCommit !== null) {
            $afterCommit();
        }

        $provisioner->reload($executor, $site);
    }

    /**
     * Every domain of a website that should be covered by the same certificate
     *
     * Always includes aliases, because a certificate that doesn't cover all of
     * them makes the browser warn on just some aliases — a symptom harder to
     * trace back than the whole site having no SSL at all.
     *
     * @return list<string>
     */
    protected function domainsFor(Site $site): array
    {
        return array_values(array_unique([$site->domain, ...$site->aliases]));
    }

    protected function assertCertificateExists(Executor $executor, Site $site): array
    {
        $certificate = $this->certbot()->inspect($executor, $site);

        if ($certificate['status'] === 'none') {
            throw new ValidationError(
                'This website has no certificate yet — install SSL before HTTPS can be enabled',
            );
        }

        return $certificate;
    }
}
