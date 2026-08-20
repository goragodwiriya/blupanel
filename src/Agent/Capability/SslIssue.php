<?php

declare(strict_types=1);

namespace Phpcp\Agent\Capability;

use Phpcp\Agent\Capability;
use Phpcp\Agent\Context;
use Phpcp\Agent\Executor\Executor;
use Phpcp\Agent\ValidationError;
use Phpcp\Domain\SettingsRepository;
use Phpcp\Domain\Site;
use Phpcp\Driver\Ssl\CertbotManager;
use Phpcp\Support\Validator;

/**
 * Installs a certificate — Let's Encrypt or self-signed
 *
 * Never enables SSL automatically after successfully issuing a certificate,
 * split into two steps on purpose: issuing can succeed while the certificate
 * doesn't cover every domain, or an admin might not be ready to switch yet —
 * seeing the result first, then clicking to enable it, is safer than switching
 * immediately and breaking the site.
 *
 * **But the second step has to exist somewhere.** For a long time it did not:
 * `ssl.set_mode` was registered and routed, and nothing in the panel ever
 * called it — so `sites.ssl_mode` stayed `off`, the vhost never grew a `:443`
 * block, and a perfectly valid certificate sat on disk while the browser said
 * "not secure", with no screen anywhere that could change that.
 *
 * Both callers now pair the two commands rather than leaving the pairing to
 * memory: the certificates table has explicit enable/force/off buttons, and
 * {@see \Phpcp\Http\V2\UsersController::store()} runs both in the request
 * that creates the account. Anything new that issues a certificate has to
 * decide what switches it on — leaving that out is not a neutral default.
 */
final class SslIssue extends SslCapability implements Capability
{
    public static function name(): string
    {
        return 'ssl.issue';
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
        return 'Request SSL certificate for website';
    }

    public function validate(array $args): array
    {
        $method = Validator::requireEnum($args, 'method', ['letsencrypt', 'self-signed']);
        $out = [
            'site_id' => Validator::requireInt($args, 'site_id', 1),
            'method' => $method,
            'staging' => (bool) ($args['staging'] ?? false),
            'email' => '',
        ];

        // The email is validated in run(), not here — a value the caller
        // didn't send gets filled in from the site owner or system settings
        // first (see resolveEmail) · validating it here would reject before
        // ever trying values that already exist in the system
        $out['email'] = trim((string) ($args['email'] ?? ''));

        return $out;
    }

    public function run(array $args, Executor $executor, Context $context): array
    {
        $this->assertSiteAccess($context, $args['site_id']);

        $site = $this->loadSite($context, $args['site_id']);

        if (!$site->isActive()) {
            throw new ValidationError('The website is suspended — lift the suspension before requesting a certificate');
        }

        $certbot = $this->certbot();
        $domains = $this->domainsFor($site);

        if ($args['method'] === 'self-signed') {
            // Every domain of the site, not just the primary one — the same set letsencrypt requests
            $certbot->selfSign($executor, $site, $domains);

            return [
                'site_id' => $site->id,
                'domain' => $site->domain,
                'method' => 'self-signed',
                'certificate' => $certbot->inspect($executor, $site),
                'message' => sprintf(
                    'Created a self-signed certificate for %s — browsers will always show a warning, '
                    . 'suitable for testing or internal sites only, never for a public website',
                    $site->domain,
                ),
            ];
        }

        $certbot->issue($executor, $site, $domains, $this->resolveEmail($args['email'], $site, $context), $args['staging']);
        $certificate = $certbot->inspect($executor, $site);

        /*
         * The web server only reads the certificate at start/reload — a new
         * file on disk has no effect until a reload is triggered.
         *
         * **Found on the real server (2026-08-14):** requested a new
         * certificate that added `www.` coverage, the screen reported success
         * and the file on disk was entirely correct, but visitors still
         * received the old certificate with no `www.` in it — the browser kept
         * showing a name-mismatch warning with nothing explaining why, and an
         * admin had no way to guess that a manual reload was needed.
         *
         * Never rethrown on failure: the certificate genuinely was issued
         * successfully — turning the whole command into an error would invite
         * clicking to request again, burning through Let's Encrypt's quota
         * without fixing anything — reports back that it wasn't loaded instead.
         */
        $reloaded = true;

        try {
            SiteCapability::provisionerFor($context)->reload($executor, $site);
        } catch (\Throwable) {
            $reloaded = false;
        }

        return [
            'site_id' => $site->id,
            'domain' => $site->domain,
            'method' => 'letsencrypt',
            'domains' => $domains,
            'certificate' => $certificate,
            'reloaded' => $reloaded,
            'message' => sprintf(
                'Successfully requested a certificate for %s (covers %d domain(s), expires in %d days)%s — '
                . 'click "Enable HTTPS" to actually start using it%s',
                $site->domain,
                count($domains),
                $certificate['days_left'],
                $args['staging'] ? ' [staging test certificate, not trusted by browsers]' : '',
                $reloaded ? '' : ' · failed to load the new certificate into the web server, visitors still get the old one until reloaded',
            ),
        ];
    }

    /**
     * Finds a contact email for Let's Encrypt, in order of appropriateness
     *
     * **Why it's filled in automatically rather than required:** the
     * certificate list page has a "request certificate" button right in a
     * table row, with nowhere to type anything · requiring an email would make
     * that button fail every time with an "invalid email" message pointing at
     * entirely the wrong cause (the user didn't type anything wrong — there
     * was never a field to type into in the first place) · found on the real
     * machine on 2026-08-11.
     *
     * The order chosen:
     *   1. The value the caller sent — an API caller who specifies one always wins
     *   2. **The site owner's email** — the person who should get the warning
     *      that a certificate is about to expire is whoever manages that site,
     *      not an admin who happened to click the button
     *   3. The system's `mail.from` — the last resort when a customer account never filled in an email
     */
    private function resolveEmail(string $requested, Site $site, Context $context): string
    {
        if ($requested !== '') {
            return CertbotManager::assertEmail($requested);
        }

        $candidates = [];

        if ($site->owner->userId > 0) {
            $candidates[] = (string) $context->db->value(
                'SELECT email FROM users WHERE id = :id',
                ['id' => $site->owner->userId],
                '',
            );
        }

        $candidates[] = (new SettingsRepository($context->db))->get('mail.from');

        foreach ($candidates as $candidate) {
            if (trim($candidate) !== '' && filter_var(trim($candidate), FILTER_VALIDATE_EMAIL) !== false) {
                return trim($candidate);
            }
        }

        throw new ValidationError(
            "No contact email found for Let's Encrypt — the system needs this email to warn when a certificate is about to expire\n\n"
            . "Two ways to fix this: fill in an email on the website owner's account, "
            . 'or set the "sender email" on the system settings page',
        );
    }
}
