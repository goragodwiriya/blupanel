<?php

declare(strict_types=1);

namespace Phpcp\Http\V2;

use Phpcp\Kernel\Request;
use Phpcp\Kernel\Response;

/**
 * SSL certificates — `/api/v2/certificates`
 *
 * This resource is identified by **site id**, not a certificate id, because
 * one website always has exactly one certificate (covering all of that
 * website's domains in the one certificate) — a separate id would mean asking
 * twice every time "which certificate belongs to this website" for no extra benefit
 *
 * Data is read from the real certificate file every time, never a database
 * table — certbot renews on its own through its own timer, never through the
 * panel, so a table can always fall behind (`cert.sync` in phase A1 chases the
 * table back into sync, but the file is still the source of truth)
 */
final class CertificatesController extends HostingController
{
    /** Every certificate belonging to a website the caller has permission to see — filterable by `site_id` (§4.5) */
    public function index(Request $request): Response
    {
        $data = $this->agent()->data('ssl.list', [], $this->ctx->actor($request));
        $sites = $data['sites'] ?? [];

        $siteId = $request->queryInt('site_id', 0);
        if ($siteId > 0) {
            $sites = array_values(array_filter(
                $sites,
                static fn (array $row): bool => (int) ($row['site_id'] ?? 0) === $siteId,
            ));
        }

        // A button's condition in the table can only read values in the same
        // row — so permission must travel with the row, not have the screen go
        // find it elsewhere · the real guard is the API route's own permission check
        $manage = $this->ctx->can('ssl.manage');

        return $this->ok(
            array_map(static fn (array $row): array => self::flatten($row) + ['can_manage' => $manage], $sites),
            [
                'expiring' => (int) ($data['expiring'] ?? 0),
                'certbot_installed' => (bool) ($data['certbot_installed'] ?? false),
                'auto_renew_active' => (bool) ($data['auto_renew_active'] ?? false),
                'warn_days' => (int) ($data['warn_days'] ?? 0),
                'can' => $this->can(['manage' => 'ssl.manage']),
            ],
        );
    }

    /**
     * Flatten `certificate` up to the row's top level
     *
     * The agent's `ssl.list` nests certificate details inside a `certificate`
     * key, which reads easily by eye, but **the screen's table can only read
     * top-level keys** — so the status, issuer, and expiry columns stayed
     * empty the whole time while the table still showed every row and the
     * console had not a single error
     *
     * Flattened here instead of having the screen reach in itself, because
     * this is a **list endpoint** — the shape that fits it is a flat row, one
     * per website, while nested detail is the shape of a single resource
     *
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private static function flatten(array $row): array
    {
        $certificate = is_array($row['certificate'] ?? null) ? $row['certificate'] : [];
        unset($row['certificate']);

        $status = (string) ($certificate['status'] ?? 'none');

        return $row + [
            'status' => $status,
            // The pill's color comes from the server, so the template can write `pill-${status_tone}` directly
            'status_tone' => match ($status) {
                'valid' => 'ok',
                'expiring' => 'warn',
                'expired', 'invalid' => 'danger',
                default => 'muted',
            },
            // null when there's no certificate (status=none) — never '' or 0 · the
            // table's standard formatters (`data-format`, `data-empty-text`) show
            // "—" only for null/undefined values · a 0 would be interpreted as a
            // real date (Jan 1, 1970), not "none"
            'source' => isset($certificate['source']) && $certificate['source'] !== '' ? (string) $certificate['source'] : null,
            'issuer' => isset($certificate['issuer']) && $certificate['issuer'] !== '' ? (string) $certificate['issuer'] : null,
            'subject' => isset($certificate['subject']) && $certificate['subject'] !== '' ? (string) $certificate['subject'] : null,
            'covers' => is_array($certificate['domains'] ?? null) ? $certificate['domains'] : [],
            'expires_at' => !empty($certificate['expires_at']) ? (int) $certificate['expires_at'] : null,
            'days_left' => isset($certificate['days_left']) && $status !== 'none' ? (int) $certificate['days_left'] : null,
            'auto_renew' => (bool) ($certificate['auto_renew'] ?? false),
        ];
    }

    /**
     * Request a new certificate for a website
     *
     * `method` chooses between letsencrypt and self-signed · Let's Encrypt's
     * staging mode exists for testing without eating into production's rate
     * limit, which once hit means waiting a week — so this value must be
     * passable through the API, not exist only on the web page
     */
    public function store(Request $request): Response
    {
        $siteId = (int) $request->payload('site_id', 0);

        if ($this->findSite($siteId) === null) {
            return $this->siteNotFound();
        }

        $result = $this->agent()->data('ssl.issue', [
            'site_id' => $siteId,
            'method' => $request->payloadString('method'),
            'email' => $request->payloadString('email'),
            'staging' => (bool) $request->payload('staging', false),
        ], $this->ctx->actor($request));

        return $this->done(
            (string) ($result['message'] ?? 'Certificate issued'),
            [
                ['type' => 'notification', 'level' => 'success',
                 'message' => (string) ($result['message'] ?? 'Certificate issued')],
                ['type' => 'redirect', 'url' => 'reload', 'target' => 'certificates'],
            ],
            $result,
            201,
        )->withHeader('Location', '/api/v2/certificates');
    }

    /** Renew a certificate — `force` is used when it isn't due yet but a renewal is wanted anyway */
    public function renew(Request $request): Response
    {
        $siteId = $request->paramInt('site_id');

        if ($this->findSite($siteId) === null) {
            return $this->siteNotFound();
        }

        $result = $this->agent()->data('ssl.renew', [
            'site_id' => $siteId,
            'force' => (bool) $request->payload('force', false),
        ], $this->ctx->actor($request));

        return $this->completed(
            (string) ($result['message'] ?? 'Certificate renewed'),
            'certificates',
            $result,
        );
    }

    /**
     * Change a website's HTTPS mode (off / on / forced)
     *
     * A PUT because it replaces the whole value, and repeating it changes nothing further
     */
    public function setMode(Request $request): Response
    {
        $siteId = $request->paramInt('site_id');

        if ($this->findSite($siteId) === null) {
            return $this->siteNotFound();
        }

        $result = $this->agent()->data('ssl.set_mode', [
            'site_id' => $siteId,
            'mode' => $request->payloadString('mode'),
        ], $this->ctx->actor($request));

        return $this->completed(
            (string) ($result['message'] ?? 'HTTPS mode changed'),
            'certificates',
            $result,
        );
    }

    /**
     * Delete a certificate — requires confirming with the domain name
     *
     * The capability always turns off HTTPS before deleting the files, or the
     * vhost would point at files that no longer exist and the whole machine's
     * websites would go down (Apache rejects the entire config, not just that one vhost)
     */
    public function destroy(Request $request): Response
    {
        $siteId = $request->paramInt('site_id');

        if ($this->findSite($siteId) === null) {
            return $this->siteNotFound();
        }

        $confirm = trim($request->payloadString('confirm_domain')) ?: trim($request->get('confirm_domain'));

        $this->agent()->data('ssl.delete', [
            'site_id' => $siteId,
            'confirm_domain' => $confirm,
        ], $this->ctx->actor($request));

        return $this->completed('Certificate deleted', 'certificates', ['site_id' => $siteId]);
    }
}
