<?php

declare(strict_types=1);

namespace Phpcp\Driver\Mail;

use Phpcp\Agent\Executor\Executor;
use Phpcp\Driver\Ssl\CertbotManager;

/**
 * The mail hostname's certificate — PLAN-MAIL phase M3
 *
 * ## Why this doesn't request its own certificate
 *
 * `mail.example.com`'s certificate is just an ordinary certificate, nothing
 * special about it at all · requesting one has to prove control of that
 * name, and the only method that genuinely works on a machine whose web
 * server already holds port 80 is the webroot of whichever website accepts
 * that name — the exact same path the existing "request certificate" button on the SSL page already uses.
 *
 * Writing a second certificate-requester here would produce two paths doing
 * the same thing but talking to ACME in different places, renewing
 * differently, and breaking with different symptoms · instead, this does
 * what nobody was doing yet: **find a certificate that already covers this
 * name on the machine, and tell Postfix and Dovecot about it**.
 *
 * So an admin adds `mail.example.com` as a website domain, clicks the
 * existing "request certificate" button, and mail picks up the real
 * certificate automatically — no new step to learn.
 *
 * ## The easiest thing to get wrong is renewal
 *
 * A Let's Encrypt certificate lasts 90 days, and certbot renews it on its
 * own without ever going through the panel · Postfix re-reads the
 * certificate file on every new connection (a fresh smtpd process is always
 * spawned), but **Dovecot reads it once at start time and holds onto it** —
 * with nobody triggering a reload after a renewal, a customer's mail client
 * would keep running into an expired certificate forever, even though the
 * file on disk is already correct · see `changedSince()`.
 */
final class MailCertificate
{
    /**
     * The certificate the distro generates at install time — used as a fallback until a real one exists
     *
     * Nobody trusts this certificate — mail clients warn about it every
     * time · but the alternative is no TLS at all, meaning every mailbox's
     * password travels as plain text — far worse · the mail readiness page
     * is what tells an admin this certificate is still in use.
     *
     * **Always genuinely present on any machine with Dovecot installed** —
     * `dovecot-core` has `ssl-cert` as a Depends (not a Recommends) ·
     * writing this path into a config file can therefore never produce a
     * daemon that fails to start because it points at a missing file — verified before choosing it as the fallback.
     */
    public const DEFAULT_CERT = '/etc/ssl/certs/ssl-cert-snakeoil.pem';
    public const DEFAULT_KEY = '/etc/ssl/private/ssl-cert-snakeoil.key';

    public function __construct(private readonly CertbotManager $certbot)
    {
    }

    /**
     * The certificate paths to write into the config file — falls back to the distro's certificate whenever empty
     *
     * @return array{cert:string,key:string}
     */
    public static function pathsOrDefault(string $cert, string $key): array
    {
        // Both must be present · a certificate with no matching key stops the whole daemon from starting
        return $cert !== '' && $key !== ''
            ? ['cert' => $cert, 'key' => $key]
            : ['cert' => self::DEFAULT_CERT, 'key' => self::DEFAULT_KEY];
    }

    /**
     * Finds the best certificate on the machine that covers this name
     *
     * Searches both Let's Encrypt certificates and the panel's own
     * self-signed ones — a self-signed certificate doesn't stop mail
     * clients from warning, but it's still better than the distro's own
     * snakeoil certificate, in that the name in it at least matches the
     * name the server announces · a real certificate always wins over a self-signed one.
     *
     * @return array{cert:string,key:string,source:string,name:string,expires_at:int,days_left:int,status:string}|null
     */
    public function locate(Executor $executor, string $hostname): ?array
    {
        $hostname = strtolower(trim($hostname));

        if ($hostname === '') {
            return null;
        }

        $best = null;

        foreach ([CertbotManager::LIVE_DIR => 'letsencrypt', CertbotManager::SELF_SIGNED_DIR => 'self-signed'] as $dir => $source) {
            foreach ($this->certificateDirs($executor, $dir) as $name) {
                $cert = $dir . '/' . $name . '/fullchain.pem';
                $key = $dir . '/' . $name . '/privkey.pem';

                if (!$executor->exists($executor->path($cert)) || !$executor->exists($executor->path($key))) {
                    continue;
                }

                $info = $this->certbot->inspectFile($executor, $cert);

                if (!self::covers((array) ($info['domains'] ?? []), $hostname)) {
                    continue;
                }

                $candidate = [
                    'cert' => $cert,
                    'key' => $key,
                    'source' => $source,
                    'name' => $name,
                    'expires_at' => (int) ($info['expires_at'] ?? 0),
                    'days_left' => (int) ($info['days_left'] ?? 0),
                    'status' => (string) ($info['status'] ?? 'invalid'),
                ];

                if ($best === null || self::better($candidate, $best)) {
                    $best = $candidate;
                }
            }
        }

        return $best;
    }

    /**
     * Every certificate name in one directory — a missing directory just means no certificate exists yet, not an error
     *
     * @return list<string>
     */
    private function certificateDirs(Executor $executor, string $dir): array
    {
        $resolved = $executor->path($dir);

        if (!$executor->exists($resolved)) {
            return [];
        }

        $names = [];

        foreach ($executor->listDirectory($resolved) as $entry) {
            if ($entry['type'] === 'dir' && $entry['name'] !== '.' && $entry['name'] !== '..') {
                $names[] = (string) $entry['name'];
            }
        }

        return $names;
    }

    /**
     * Does this certificate cover this name?
     *
     * Supports wildcards, because a `*.example.com` certificate an admin
     * already requested for a website already covers `mail.example.com` —
     * failing to recognize that would force an unnecessary duplicate request.
     *
     * @param array<int,mixed> $domains
     */
    public static function covers(array $domains, string $hostname): bool
    {
        $hostname = strtolower(trim($hostname));

        foreach ($domains as $domain) {
            $domain = strtolower(trim((string) $domain));

            if ($domain === $hostname) {
                return true;
            }

            // `*.example.com` only covers a single level — `a.b.example.com` doesn't count
            // per RFC 6125's rule, which mail clients genuinely enforce
            if (str_starts_with($domain, '*.')
                && str_ends_with($hostname, substr($domain, 1))
                && substr_count($hostname, '.') === substr_count($domain, '.')
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Which certificate is better — a real one wins over a self-signed one, then whichever lasts longer
     *
     * @param array{source:string,expires_at:int} $candidate
     * @param array{source:string,expires_at:int} $current
     */
    private static function better(array $candidate, array $current): bool
    {
        if ($candidate['source'] !== $current['source']) {
            return $candidate['source'] === 'letsencrypt';
        }

        return $candidate['expires_at'] > $current['expires_at'];
    }

    /**
     * Has the certificate changed since the last time the daemon was told about it?
     *
     * Compares the certificate file's modification time against the config
     * file the panel itself wrote — the right question to ask is "has the
     * certificate changed since we last told it", which both files can
     * answer without needing any extra state stored anywhere · no config
     * file yet = nobody was ever told, so it's treated as changed.
     *
     * A Let's Encrypt certificate is a symlink recreated on every renewal,
     * so this follows the real path to check the archived file's time, not the link's own.
     */
    public function changedSince(Executor $executor, string $certPath, string $configPath): bool
    {
        $config = $executor->stat($executor->path($configPath));

        if ($config === null) {
            return true;
        }

        $resolved = $executor->realPath($executor->path($certPath)) ?? $executor->path($certPath);
        $cert = $executor->stat($resolved);

        return $cert === null || $cert['mtime'] > $config['mtime'];
    }
}
