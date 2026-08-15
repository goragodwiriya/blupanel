<?php

declare(strict_types=1);

namespace Phpcp\Http\V2;

use Phpcp\Http\ApiController;
use Phpcp\Middleware\RateLimit;
use Phpcp\Kernel\Request;
use Phpcp\Kernel\Response;

final class SecurityController extends ApiController
{
    public function scan(Request $request): Response
    {
        $data = $this->agent()->data('security.scan', [], $this->ctx->actor($request));

        $checks = $data['checks'] ?? [];
        unset($data['checks']);

        /*
         * Pill colour comes from the server, so the template can write
         * `pill-${status_tone}` directly.
         *
         * Title/detail/advice are translated here rather than in SecurityScan itself,
         * because a capability has no request context to know the caller's language.
         * This is safe for checks not yet converted to English: `t()` returns any
         * string unchanged when it finds no catalogue entry for it, and Thai text
         * never matches an English key, so old Thai checks just pass through as-is.
         */
        $checks = array_map(function (array $check): array {
            $check['status_tone'] = match ($check['status'] ?? '') {
                'pass' => 'ok',
                'fail' => 'danger',
                'warn' => 'warn',
                default => 'muted',
            };
            $check['title'] = $this->t((string) ($check['title'] ?? ''));
            $check['detail'] = $this->t((string) ($check['detail'] ?? ''));

            if (($check['advice'] ?? '') !== '') {
                $check['advice'] = $this->t((string) $check['advice']);
            }

            return $check;
        }, $checks);

        return $this->ok($checks, $data);
    }

    /**
     * The whole machine's protection picture — the master switch, every jail, and
     * every banned IP
     *
     * The question this page answers is "who is this machine banning right now",
     * which is a machine-level question, not a per-site one — previously this meant
     * opening each site's own page one at a time to find the answer.
     */
    public function protection(Request $request): Response
    {
        return $this->ok($this->translateProtection(
            $this->agent()->data('security.protection', [], $this->ctx->actor($request)),
        ));
    }

    /** Every jail's banned IPs combined — its own route so the table can reload itself */
    public function protectionBans(Request $request): Response
    {
        $data = $this->translateProtection(
            $this->agent()->data('security.protection', [], $this->ctx->actor($request)),
        );

        return $this->ok($data['bans'] ?? []);
    }

    /**
     * Translates the label fields ProtectionOverview returns
     *
     * Only the fully static fields can round-trip through the catalogue —
     * `mode_label`, `count_label`, and `state_label` are each one of a fixed, small
     * set of values, so a th.json entry matches them exactly. `label` and
     * `jail_label` for per-site rows carry a domain name baked in
     * (`'Request rate limit — ' . $domain`), so they can never match a catalogue key;
     * those stay in English until the response carries the site name and the
     * template's own label separately.
     */
    private function translateProtection(array $data): array
    {
        $data['jails'] = array_map(function (array $jail): array {
            $jail['label'] = $this->t((string) ($jail['label'] ?? ''));
            $jail['mode_label'] = $this->t((string) ($jail['mode_label'] ?? ''));
            $jail['count_label'] = $this->t((string) ($jail['count_label'] ?? ''));

            return $jail;
        }, $data['jails'] ?? []);

        $data['bans'] = array_map(function (array $ban): array {
            $ban['jail_label'] = $this->t((string) ($ban['jail_label'] ?? ''));
            $ban['state_label'] = $this->t((string) ($ban['state_label'] ?? ''));

            return $ban;
        }, $data['bans'] ?? []);

        return $data;
    }

    /** Turns fail2ban use on or off, for the panel as a whole */
    public function fail2banSet(Request $request): Response
    {
        $data = $this->agent()->data('security.fail2ban_set', [
            'enabled' => $request->payload('enabled'),
        ], $this->ctx->actor($request));

        return $this->refreshed(
            (string) ($data['message'] ?? 'Saved'),
            extra: is_array($data) ? $data : [],
        );
    }

    /** Status of login brute-force protection */
    public function panelJail(Request $request): Response
    {
        $data = $this->agent()->data('security.panel_jail', [], $this->ctx->actor($request));

        /*
         * The ceiling on `max_retry` isn't a chosen number — it's computed from the
         * login page's own rate-limit quota. A request rejected with 429 never
         * produces a line in the audit log for fail2ban to count. Sent to the screen
         * to write as a caption under the field, so the user knows before saving
         * rather than being rejected after.
         */
        $data['max_retry_ceiling'] = RateLimit::maxLoginFailuresWithin(
            (int) ($data['find_seconds'] ?? 600),
        );

        return $this->ok($data);
    }

    /**
     * Turns login brute-force protection on or off
     *
     * **Doesn't use `saved()`** — this form doesn't live in a modal, and the screen
     * needs to show the real state from fail2ban right away (is the jail running,
     * who is it banning), so it tells the page to reload instead.
     */
    public function panelJailSet(Request $request): Response
    {
        $data = $this->agent()->data('security.panel_jail_set', [
            'mode' => $request->payloadString('mode'),
            'enabled' => $request->payload('enabled', false),
            'max_retry' => $request->payload('max_retry'),
            'find_seconds' => $request->payload('find_seconds'),
            'ban_seconds' => $request->payload('ban_seconds'),
            'ignore_ips' => $request->payloadString('ignore_ips'),
        ], $this->ctx->actor($request));

        return $this->refreshed(
            (string) ($data['message'] ?? 'Saved'),
            extra: is_array($data) ? $data : [],
        );
    }

    /**
     * Sets the never-ban address list — machine-wide, applied to every jail
     *
     * Kept separate from the login jail's own form on purpose: this list also
     * affects per-site jails, so it has to be saveable even while the login jail
     * itself is off.
     */
    public function neverBanSet(Request $request): Response
    {
        $data = $this->agent()->data('security.never_ban_set', [
            'ips' => $request->payloadString('ips'),
        ], $this->ctx->actor($request));

        return $this->refreshed(
            (string) ($data['message'] ?? 'Saved'),
            extra: is_array($data) ? $data : [],
        );
    }

    /**
     * Unbans one IP from the login page's jail
     *
     * Has to exist because bad bans happen for real and block every port — an admin
     * who banned themselves from another machine needs to be able to unban from the
     * screen, without having to find SSH.
     */
    public function panelJailUnban(Request $request): Response
    {
        $data = $this->agent()->data('security.panel_jail_unban', [
            'ip' => $request->payloadString('ip'),
            // The combined view sends the jail name since it lists several — the
            // older page doesn't send it, meaning the login jail
            'jail' => $request->payloadString('jail'),
        ], $this->ctx->actor($request));

        return $this->refreshed(
            (string) ($data['message'] ?? 'Unbanned'),
            extra: is_array($data) ? $data : [],
        );
    }
}
