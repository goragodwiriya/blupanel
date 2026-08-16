<?php

declare(strict_types=1);

namespace Phpcp\Driver;

use Phpcp\Agent\Executor\Executor;
use Phpcp\Agent\ValidationError;
use Phpcp\Kernel\Config;
use Phpcp\Support\BinaryPath;
use Phpcp\Support\Validator;

/**
 * The certificate for **the panel's own login page** — an entirely
 * different thing from the certificate a customer's website uses
 *
 * ## Why this needs to be kept separate
 *
 * The panel's own Apache reads a fixed file at `/etc/phpcp/tls/panel.crt`,
 * from an `httpd.conf` the installer generated · this used to only be
 * changeable to a real certificate by editing the file by hand, and admins
 * just clicked through the certificate warning every single day instead —
 * training people to ignore a warning that will one day be real.
 *
 * ## Copies the file, never a symlink
 *
 * The shorter-looking path would be a symlink to
 * `/etc/letsencrypt/live/...`, but two problems rule that out:
 *
 *   1. **`RollbackGuard` restores by *writing file content*** — if the
 *      destination were a symlink, a restore would write straight through
 *      it and overwrite the genuine Let's Encrypt certificate a customer's
 *      website is also using.
 *   2. Apache does read the file as root at start time, but relying on
 *      another directory's permissions makes correctness depend on
 *      something outside the panel's own control.
 *
 * So the file is copied, and **certbot's own deploy hook** re-copies it on
 * every renewal ({@see hookScript()}) — missing this hook means the
 * certificate expires in 90 days and the warning is right back, even though
 * the file on disk is correct.
 *
 * ## The order of checks before switching
 *
 * Three things are checked here first (the file exists · the key and
 * certificate genuinely form a pair · not yet expired), and then **Apache's
 * own validator** decides at another layer through {@see ConfigTransaction}
 * · a mismatched key pair stops Apache from starting on the next reboot —
 * locking yourself out of the machine in a way nobody notices until the
 * reboot happens.
 */
final class PanelCertificate
{
    /** The fixed file panel.crt httpd.conf points to */
    public const CERT = '/etc/phpcp/tls/panel.crt';
    public const KEY = '/etc/phpcp/tls/panel.key';

    /** The certificate the installer generated — always kept as a way back */
    public const SELF_SIGNED_CERT = '/etc/phpcp/tls/panel.selfsigned.crt';
    public const SELF_SIGNED_KEY = '/etc/phpcp/tls/panel.selfsigned.key';

    /** The hook certbot calls after a successful renewal */
    public const HOOK = '/etc/letsencrypt/renewal-hooks/deploy/phpcp-panel-cert.sh';

    /** The systemd unit of the web server serving the panel */
    public const UNIT = 'phpcp-web';

    /** @var list<string> */
    public const OPENSSL_PATHS = ['/usr/bin/openssl', '/bin/openssl'];

    /** The location of the certificate certbot issued for a domain */
    public static function sourcePaths(string $domain): array
    {
        $domain = Validator::domain($domain);

        return [
            'cert' => '/etc/letsencrypt/live/' . $domain . '/fullchain.pem',
            'key' => '/etc/letsencrypt/live/' . $domain . '/privkey.pem',
        ];
    }

    /**
     * Reads the current state of the certificate the panel is using
     *
     * @return array{domain:string,self_signed:bool,subject:string,issuer:string,not_after:int,days_left:int,hook:bool}
     */
    public function status(Executor $executor, string $configuredDomain): array
    {
        $info = $this->inspect($executor, self::CERT);

        return [
            'domain' => $configuredDomain,
            // The issuer and the subject being the same entity = self-signed · never guessed from the filename
            'self_signed' => $configuredDomain === '' || $info['issuer'] === $info['subject'],
            'subject' => $info['subject'],
            'issuer' => $info['issuer'],
            'not_after' => $info['not_after'],
            'days_left' => $info['not_after'] > 0 ? (int) floor(($info['not_after'] - time()) / 86400) : 0,
            'hook' => $executor->exists($executor->path(self::HOOK)),
        ];
    }

    /**
     * The file content about to be installed as the panel's certificate, after confirming it genuinely works
     *
     * @return array{cert:string,key:string}
     */
    public function read(Executor $executor, string $certPath, string $keyPath): array
    {
        foreach ([$certPath, $keyPath] as $path) {
            if (!$executor->exists($executor->path($path))) {
                throw new ValidationError(
                    'Certificate file not found at ' . $path . ' — request a certificate for this domain first',
                );
            }
        }

        $cert = $executor->readFile($executor->path($certPath));
        $key = $executor->readFile($executor->path($keyPath));

        $this->assertUsable($executor, $certPath, $keyPath);

        return ['cert' => $cert, 'key' => $key];
    }

    /**
     * The certificate and key must genuinely form a pair, and must not have expired
     *
     * **A mismatched pair locks you out of the machine** — Apache fails to
     * start on the next reboot, with nothing indicating why until that
     * reboot happens · compared by the public key's fingerprint, the only
     * way to answer this with certainty (a matching domain name doesn't mean they're actually a pair).
     */
    private function assertUsable(Executor $executor, string $certPath, string $keyPath): void
    {
        $openssl = BinaryPath::resolve($executor, self::OPENSSL_PATHS, 'openssl');

        $certPub = $executor->exec(
            [$openssl, 'x509', '-noout', '-pubkey', '-in', $executor->path($certPath)],
            timeout: 10,
        );
        $keyPub = $executor->exec(
            [$openssl, 'pkey', '-pubout', '-in', $executor->path($keyPath)],
            timeout: 10,
        );

        if (!$certPub->ok() || !$keyPub->ok()) {
            throw new ValidationError(
                "Failed to read the certificate or key — the file may be corrupt\n"
                . trim($certPub->stderr . ' ' . $keyPub->stderr),
            );
        }

        if (trim($certPub->output()) !== trim($keyPub->output())) {
            throw new ValidationError(
                'The certificate and key are not a matching pair — using them would stop the '
                . "panel's own web server from starting on the next reboot",
            );
        }

        $info = $this->inspect($executor, $certPath);

        if ($info['not_after'] > 0 && $info['not_after'] < time()) {
            throw new ValidationError(sprintf(
                'This certificate already expired on %s — renew it first, then try again',
                date('Y-m-d', $info['not_after']),
            ));
        }
    }

    /**
     * A certificate's own data — returns an empty result when it can't be read, never throws
     *
     * Also used when displaying status, which has to keep working even when
     * the file is broken · a screen that breaks because a certificate is
     * broken would be closing off the one way an admin could get in to fix it.
     *
     * @return array{subject:string,issuer:string,not_after:int}
     */
    private function inspect(Executor $executor, string $path): array
    {
        $empty = ['subject' => '', 'issuer' => '', 'not_after' => 0];

        if (!$executor->exists($executor->path($path))) {
            return $empty;
        }

        try {
            $openssl = BinaryPath::resolve($executor, self::OPENSSL_PATHS, 'openssl');
        } catch (\Throwable) {
            return $empty;
        }

        $result = $executor->exec(
            [$openssl, 'x509', '-noout', '-subject', '-issuer', '-enddate', '-in', $executor->path($path)],
            timeout: 10,
        );

        if (!$result->ok()) {
            return $empty;
        }

        $out = $result->output();
        $subject = $this->field($out, 'subject=');
        $issuer = $this->field($out, 'issuer=');
        $notAfter = $this->field($out, 'notAfter=');

        return [
            'subject' => $subject,
            'issuer' => $issuer,
            'not_after' => $notAfter === '' ? 0 : (int) max(0, strtotime($notAfter)),
        ];
    }

    private function field(string $output, string $prefix): string
    {
        foreach (preg_split('/\R/', $output) ?: [] as $line) {
            if (str_starts_with($line, $prefix)) {
                return trim(substr($line, strlen($prefix)));
            }
        }

        return '';
    }

    /**
     * The script certbot calls after a successful renewal
     *
     * Calls `phpcp panel:cert-sync` rather than copying the file itself,
     * because that command knows which domain the panel is currently bound
     * to (reads it from settings) · writing the hook with direct copy logic
     * would create a second piece of data that has to be kept in sync by hand, and one day it wouldn't be.
     *
     * The trailing `|| true` is deliberate — a hook that returns non-zero
     * makes certbot report the renewal as failed even though the new
     * certificate was already issued successfully, sending anyone
     * troubleshooting it looking in the wrong place.
     */
    public static function hookScript(string $phpBinary, string $cliPath): string
    {
        return "#!/bin/sh\n"
            . "# Generated by phpcp — do not edit by hand\n"
            . "# Copies the just-renewed certificate to the panel, then triggers a reload without cutting connections\n"
            . sprintf("%s %s panel:cert-sync >/dev/null 2>&1 || true\n", $phpBinary, $cliPath);
    }

    /** Reloads gracefully — a request currently being answered (including the one from whoever clicked the button) must not be cut off */
    public function reload(Executor $executor): void
    {
        $executor->exec(
            [$executor->path('/usr/bin/systemctl'), 'reload', self::UNIT],
            timeout: 30,
        );
    }

    /** Apache's own validator — decides whether the file just installed genuinely works */
    public function checkConfig(Executor $executor, Config $config): array
    {
        $httpd = $this->httpdBinary($executor);

        if ($httpd === null) {
            // No binary to validate with (dev mode) — the key-pair check earlier already ran
            return [true, ''];
        }

        $confDir = rtrim($config->paths->etc, '/') . '/httpd';
        $result = $executor->exec(
            [$httpd, '-d', $executor->path($confDir), '-f', $executor->path($confDir . '/httpd.conf'), '-t'],
            timeout: 20,
        );

        if (!$result->ok()) {
            return [false, trim($result->output() . $result->stderr)];
        }

        return [true, ''];
    }

    private function httpdBinary(Executor $executor): ?string
    {
        foreach (['/usr/sbin/apache2', '/usr/sbin/httpd'] as $candidate) {
            if ($executor->exists($executor->path($candidate))) {
                return $candidate;
            }
        }

        return null;
    }
}
