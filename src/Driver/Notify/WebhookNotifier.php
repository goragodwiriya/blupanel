<?php

declare(strict_types=1);

namespace Phpcp\Driver\Notify;

use Phpcp\Agent\ExecutionFailed;
use Phpcp\Agent\ValidationError;

/**
 * Sends notifications through a webhook — POSTs JSON to a URL an admin configured (PLAN-V2 phase E6)
 *
 * Exists to plug into whatever system an admin already uses (Slack/Discord
 * via incoming webhook, a ticketing system, a log aggregator) without the
 * panel needing to know about each service individually — a single fixed
 * JSON shape is sent, and the destination parses it itself, which every
 * such service already knows how to do.
 *
 * **Signs with HMAC-SHA256 every time a secret is configured** — lets the
 * destination verify a message genuinely came from this machine, not from
 * anyone who happened to guess the URL · sent in the `X-Phpcp-Signature`
 * header, formatted as `sha256=<hex>`, the same convention GitHub uses, so
 * receiving-side example code is widely available to copy.
 *
 * **HTTPS is enforced** — a notification's content can reveal which
 * machine has what problem right now, exactly the kind of information used
 * to pick an attack target · the exception is `127.0.0.1`/`localhost`,
 * which runs on the same machine and never touches the network at all
 * (used to connect to a log aggregator running alongside it).
 */
final class WebhookNotifier
{
    /** Short, since this is a notification, not the main job — it must never be allowed to run slow */
    private const TIMEOUT = 8;
    private const CONNECT_TIMEOUT = 5;

    /** Long content (e.g. a failed command's stderr) is truncated before leaving the machine */
    private const MAX_BODY = 4000;

    public function __construct(
        private readonly string $url,
        private readonly string $secret = '',
    ) {
    }

    public function isConfigured(): bool
    {
        return $this->url !== '';
    }

    /**
     * Sends in "can fail, never throws" mode — used everywhere an automatic notification is sent
     *
     * The main job has already succeeded by the time this is called —
     * letting an exception escape here would report an action that already
     * succeeded as a failure, just because the destination webhook is down.
     */
    public function notify(string $event, string $title, string $body, string $level = 'info'): bool
    {
        if (!$this->isConfigured()) {
            return false;
        }

        try {
            $this->send($event, $title, $body, $level);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Sends in "must know if it fails" mode — used only when a user clicks the test button
     *
     * @return array{status:int}
     */
    public function test(): array
    {
        if (!$this->isConfigured()) {
            throw new ValidationError("The webhook's URL is not set yet");
        }

        return ['status' => $this->send(
            'test',
            'Notification test',
            'If the destination received this message, the webhook is configured correctly',
            'ok',
        )];
    }

    /** @return int the HTTP status code the destination answered with */
    private function send(string $event, string $title, string $body, string $level): int
    {
        $payload = json_encode([
            'source' => 'phpcp',
            'host' => gethostname() ?: '',
            'event' => $event,
            'level' => $level,
            'title' => $title,
            'body' => mb_substr($body, 0, self::MAX_BODY),
            'sent_at' => date(DATE_ATOM),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($payload === false) {
            throw new ExecutionFailed('Failed to build the webhook payload');
        }

        $headers = ['Content-Type: application/json', 'User-Agent: phpcp/' . PHPCP_VERSION];

        if ($this->secret !== '') {
            // Signed from the exact payload content actually sent — the destination must also verify against the raw body
            $headers[] = 'X-Phpcp-Signature: sha256=' . hash_hmac('sha256', $payload, $this->secret);
        }

        $handle = curl_init($this->url);

        if ($handle === false) {
            throw new ExecutionFailed('Failed to start a connection to the webhook');
        }

        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => self::TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            // A destination that redirects to http:// would bypass the HTTPS enforcement done in assertUrl()
            CURLOPT_FOLLOWLOCATION => false,
        ]);

        $raw = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error = curl_error($handle);
        curl_close($handle);

        if ($raw === false || $error !== '') {
            throw new ExecutionFailed('Failed to send the webhook: ' . $error);
        }

        if ($status < 200 || $status >= 300) {
            throw new ExecutionFailed("The destination responded with status {$status} — check that the URL is correct and accepts POST");
        }

        return $status;
    }

    /**
     * Validates the URL's format when settings are saved — catches a mistake at input time,
     * not the moment an important notification fails to send exactly when it's needed most
     */
    public static function assertUrl(string $url): string
    {
        if ($url === '') {
            return '';
        }

        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            throw new ValidationError('Malformed webhook URL');
        }

        $host = (string) (parse_url($url, PHP_URL_HOST) ?? '');

        // parse_url returns IPv6 wrapped in brackets (`[::1]`) — the localhost
        // exception written above would never match `::1` unless the brackets are stripped first
        $isLocal = in_array(trim($host, '[]'), ['127.0.0.1', 'localhost', '::1'], true);

        if (!str_starts_with($url, 'https://') && !$isLocal) {
            throw new ValidationError(
                "The webhook URL must be https:// — a notification's content can reveal what "
                . "problem this machine currently has, which should never travel over an unencrypted "
                . 'network (except to a destination on this same machine)',
            );
        }

        /*
         * A username/password embedded in the URL — rejected, never passed along
         *
         * It would be saved into the settings table as plain text (the key
         * `notify.webhook.url` isn't a `secret` type, so it isn't masked
         * when sent back to the screen), and it also shows up in curl's own
         * error messages · anyone wanting to authenticate to the
         * destination should use the HMAC signing that already exists.
         */
        if (parse_url($url, PHP_URL_USER) !== null || parse_url($url, PHP_URL_PASS) !== null) {
            throw new ValidationError(
                'The webhook URL must not have a username or password embedded in it — this value is stored '
                . 'and displayed as plain text · use the "secret" field instead to sign with HMAC',
            );
        }

        if (!$isLocal) {
            self::assertNotInternal($host);
        }

        return $url;
    }

    /**
     * The destination must never be an internal network address — stops the panel from being used as an SSRF proxy
     *
     * A tricked admin (or an admin account that's been taken over) could
     * set the URL to `https://10.0.0.5/` or `https://169.254.169.254/` ·
     * the first is an internal service never exposed to the internet, and
     * the second is the cloud provider's metadata endpoint, which answers
     * back with the machine's own credentials · this machine can reach both, even though an outsider can't.
     *
     * **A literal numeric address is always checked · a hostname is
     * checked as far as it can be resolved.** A name that can't be
     * resolved (DNS is down, no record set yet) is not rejected — breaking
     * the settings form every time DNS hiccups isn't worth it in exchange
     * for a gate that's already bypassable anyway · and this gate checks at
     * save time, not pinned at send time — a name that points at a public
     * address today and an internal one tomorrow (DNS rebinding) still
     * passes — an accepted limitation for a value only an admin can ever set.
     */
    private static function assertNotInternal(string $host): void
    {
        $public = FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE;

        foreach (self::addressesOf($host) as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP, $public) === false) {
                throw new ValidationError(sprintf(
                    'The webhook URL points at an internal address (%s) — the destination must be a public '
                    . 'address, so this machine can never be used to reach into an internal network on someone else\'s behalf',
                    $ip,
                ));
            }
        }
    }

    /** @return list<string> the host's addresses · empty = couldn't be resolved, which isn't treated as an error */
    private static function addressesOf(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }

        // Strips an IPv6 URL's brackets (`[::1]`) first — parse_url returns it with the brackets still on
        $bare = trim($host, '[]');

        if (filter_var($bare, FILTER_VALIDATE_IP) !== false) {
            return [$bare];
        }

        $records = @dns_get_record($host, DNS_A | DNS_AAAA);

        if (!is_array($records)) {
            return [];
        }

        $addresses = [];

        foreach ($records as $record) {
            $ip = (string) ($record['ip'] ?? $record['ipv6'] ?? '');

            if ($ip !== '') {
                $addresses[] = $ip;
            }
        }

        return $addresses;
    }
}
