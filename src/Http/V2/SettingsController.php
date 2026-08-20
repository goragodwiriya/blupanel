<?php

declare(strict_types=1);

namespace Phpcp\Http\V2;

use Phpcp\Domain\Notifier;
use Phpcp\Domain\PhpSettings;
use Phpcp\Domain\SettingsRepository;
use Phpcp\Http\ApiController;
use Phpcp\Kernel\Request;
use Phpcp\Kernel\Response;

/**
 * The panel's settings — `/api/v2/settings`
 *
 * **The web layer must never write `config.php`, under any circumstance**
 * (§7.1 item 3) — that file is `include`d at boot, so a single vulnerability
 * in the settings page would mean running code instantly · every value
 * editable from here lives in the `settings` table, only through the
 * `settings.set` capability
 *
 * A secret value (a bot's token) is masked as `********` on the capability
 * side already — a token leaking out through a response means anyone can send
 * messages as the system, and it would sit in browser caches and proxy
 * histories for a long time afterward · sending the same `********` back when
 * saving is ignored by the capability, never written over the real token with
 * literal asterisks
 */
final class SettingsController extends ApiController
{
    public function show(Request $request): Response
    {
        $data = $this->agent()->data('settings.get', [], $this->ctx->actor($request));
        $notifier = new Notifier($this->app->db());

        return $this->ok($data, [
            'keys' => SettingsRepository::keys(),
            'events' => Notifier::EVENTS,
            'event_labels' => array_map(fn (string $label): string => $this->t($label), Notifier::LABELS),
            'notify_active' => $notifier->isActive(),
            // A channel that's "fully configured and turned on," not just a
            // checked switch — the screen uses this to state the truth about
            // where a message can actually go out right now (see Notifier::activeChannels)
            'notify_channels' => $notifier->activeChannels(),
        ]);
    }

    /**
     * A partial settings edit
     *
     * Only the keys to change are sent — different from the old HTML form,
     * which had to send every key every time, since an unchecked checkbox
     * never gets sent at all · here a boolean value can be sent as true/false
     * directly, so that ambiguity disappears
     */
    public function update(Request $request): Response
    {
        // The web server can't be changed from here — see SettingsRepository::webEditableKeys()
        $known = SettingsRepository::webEditableKeys();
        $args = [];

        foreach ($request->json() + $request->post as $key => $value) {
            if ($key === '_token' || !array_key_exists($key, $known)) {
                continue;
            }

            $args[$key] = $known[$key] === 'bool'
                ? (in_array($value, [true, 1, '1', 'true', 'on'], true) ? '1' : '0')
                : (string) $value;
        }

        if ($args === []) {
            return $this->problem(
                \Phpcp\Http\ApiProblem::ValidationError,
                'Send at least one value to change',
                ['keys' => 'See GET /api/v2/settings for the list of editable keys'],
            );
        }

        $result = $this->agent()->data('settings.set', $args, $this->ctx->actor($request));

        return $this->completed((string) ($result['message'] ?? 'Settings saved'), '', is_array($result) ? $result : []);
    }

    /**
     * Send a test message to one notification channel
     *
     * `channel` not sent = `telegram`, so an old caller doesn't break (from
     * when there was only the one channel) · tested one channel at a time,
     * because an admin needs to know exactly which channel is broken, not just that "some channel is broken"
     */
    public function testNotification(Request $request): Response
    {
        $result = $this->agent()->data(
            'notify.test',
            ['channel' => $request->payloadString('channel')],
            $this->ctx->actor($request),
        );

        return $this->completed((string) ($result['message'] ?? 'Test message sent'), '', is_array($result) ? $result : []);
    }

    /**
     * Configure outgoing mail — saved and written into Postfix in one request
     *
     * Kept separate from `PATCH /settings` for the same reason as the web
     * server: this doesn't just save a value, it writes a system service's
     * config file and genuinely reloads it · someone just editing the
     * notification email shouldn't be dragged into writing `main.cf` too
     *
     * **No decisions are made here at all** — the values the user entered are
     * passed straight to the capability, which is the only place that knows
     * which domains this machine currently hosts mail for, and exactly what needs writing
     */
    public function applyMail(Request $request): Response
    {
        $args = [];

        foreach ([
            'mail.enabled', 'mail.mode', 'mail.from', 'mail.hostname',
            'mail.relay_host', 'mail.relay_port', 'mail.relay_user',
            'mail.relay_password', 'mail.relay_tls',
        ] as $key) {
            $value = $request->payload($key);

            if ($value !== null) {
                $args[$key] = $value;
            }
        }

        /*
         * An unchecked checkbox is never sent at all per the HTML standard — if
         * it isn't filled in as 0 here, turning the switch off would have no
         * effect whatsoever, and the user could never turn it off
         * (this form always sends every field, unlike PATCH /settings, which sends only what changed)
         */
        foreach (['mail.enabled', 'mail.relay_tls'] as $key) {
            $args[$key] ??= '0';
        }

        // The old name of the one field this route used to accept · an old
        // script still sending `hostname` must get the same result as before,
        // not be silently ignored while thinking the setting succeeded
        $legacy = $request->payloadString('hostname');

        if ($legacy !== '' && !isset($args['mail.hostname'])) {
            $args['mail.hostname'] = $legacy;
        }

        $result = $this->agent()->data('mail.apply', $args, $this->ctx->actor($request));

        return $this->completed((string) ($result['message'] ?? 'Email settings saved'), '', is_array($result) ? $result : []);
    }

    /**
     * Switch the web server hosting websites — completes in one request
     *
     * This layer makes no decisions at all, passing everything to the
     * capability, the only place that knows which files need writing and what
     * needs restarting in which order — the web layer can't touch /etc anyway
     */
    public function applyWebserver(Request $request): Response
    {
        $result = $this->agent()->data(
            'webserver.apply',
            [
                'mode' => $request->payloadString('mode'),
                'static_by_nginx' => $request->payloadString('static_by_nginx'),
            ],
            $this->ctx->actor($request),
        );

        return $this->completed((string) ($result['message'] ?? 'Web server changed'), '', is_array($result) ? $result : []);
    }

    /**
     * Change the management page's certificate
     *
     * **Automatically reverts if not confirmed**, same as the firewall rules
     * and SSH settings — a bad certificate makes the browser reject the
     * connection entirely, locking out the very page that's the only place to
     * fix it · send an empty `domain` to go back to the self-signed certificate
     */
    public function applyPanelCertificate(Request $request): Response
    {
        $result = $this->agent()->data(
            'panel.cert_set',
            [
                'domain' => $request->payloadString('domain'),
                'window' => (int) $request->payload('window', 0),
            ],
            $this->ctx->actor($request),
        );

        return $this->completed(
            (string) ($result['message'] ?? 'Panel certificate changed'),
            '',
            is_array($result) ? $result : [],
        );
    }

    /**
     * Set the panel's own PHP values — its pool and its Apache limit together
     *
     * Kept off `PATCH /settings` for the same reason as the web server mode:
     * these values only mean anything once the pool file and Apache's
     * `LimitRequestBody` have been rewritten from them and both services have
     * re-read them · saving the row alone would leave the screen reporting a
     * limit no process on the machine is enforcing.
     *
     * Nothing is decided here — the capability is the only place that knows
     * which files the installer wrote and in what order they have to be
     * validated and reloaded.
     */
    public function applyPanelPhp(Request $request): Response
    {
        $args = [];

        foreach (array_keys(PhpSettings::FIELDS) as $field) {
            $value = $request->payload($field);

            if ($value !== null) {
                $args[$field] = $value;
            }
        }

        $result = $this->agent()->data('panel.php_set', $args, $this->ctx->actor($request));

        return $this->completed(
            (string) ($result['message'] ?? 'PHP settings saved'),
            '',
            is_array($result) ? $result : [],
        );
    }

    /** Send a test email */
    public function testMail(Request $request): Response
    {
        $result = $this->agent()->data(
            'mail.test',
            ['to' => $request->payloadString('to')],
            $this->ctx->actor($request),
        );

        return $this->completed((string) ($result['message'] ?? 'Test email sent'), '', is_array($result) ? $result : []);
    }
}
