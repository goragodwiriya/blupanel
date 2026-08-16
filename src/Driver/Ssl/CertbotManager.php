<?php

declare(strict_types=1);

namespace Phpcp\Driver\Ssl;

use Phpcp\Agent\ExecutionFailed;
use Phpcp\Agent\Executor\Executor;
use Phpcp\Agent\ValidationError;
use Phpcp\Domain\Site;
use Phpcp\Support\Validator;

/**
 * SSL certificates through Let's Encrypt (certbot) — PROMPT.md's SSL Certificates section
 *
 * The two most important decisions in this file:
 *
 * 1. **Uses `--webroot`, never `--apache`** — Apache's own plugin would go
 *    edit the vhost itself, but every vhost file here is generated from a
 *    template and gets overwritten every time the website's settings
 *    change — letting certbot also edit it would mean that edit silently
 *    vanishes on the very next change, and a site that used to be HTTPS
 *    falls back to HTTP with nobody knowing · holding ownership of a
 *    config file in exactly one place matters more than convenience.
 *
 * 2. **Reads the expiry date from the genuine certificate file with
 *    openssl**, never from certbot's own summary, because what Apache
 *    genuinely uses is the PEM file itself — if the file and certbot's own
 *    database ever disagree (e.g. someone copied a certificate in by hand),
 *    the screen has to report the file's own truth.
 */
final class CertbotManager
{
    private const BINARY = '/usr/bin/certbot';
    private const OPENSSL = '/usr/bin/openssl';

    /**
     * The DNS-01 challenge responder certbot calls when requesting a wildcard certificate (PLAN-V2 phase E7)
     *
     * Must be an absolute path on the genuinely installed machine, never a
     * path within this project — certbot runs it as a child process, never through our own shell.
     */
    private const HOOK = '/usr/share/phpcp/bin/phpcp-acme-hook';

    /** Let's Encrypt's own standard location */
    public const LIVE_DIR = '/etc/letsencrypt/live';

    /** A certificate the panel generates itself for a site with no real certificate yet */
    public const SELF_SIGNED_DIR = '/etc/phpcp/ssl';

    /** Warns when fewer days than this remain — certbot renews automatically at 30 days */
    public const WARN_DAYS = 21;

    public function isInstalled(Executor $executor): bool
    {
        return $executor->isSimulated() || $executor->exists(self::BINARY);
    }

    /**
     * A single website's certificate data
     *
     * @return array{
     *     status:string, source:string, path:string, issuer:string, subject:string,
     *     domains:list<string>, expires_at:int, days_left:int, auto_renew:bool
     * }
     */
    public function inspect(Executor $executor, Site $site): array
    {
        foreach ([[self::LIVE_DIR, 'letsencrypt'], [self::SELF_SIGNED_DIR, 'self-signed']] as [$dir, $source]) {
            $path = $dir . '/' . $site->domain . '/fullchain.pem';

            if (!$executor->exists($executor->path($path))) {
                continue;
            }

            $info = $this->readCertificate($executor, $path);
            $info['source'] = $source;
            $info['path'] = $path;
            // Automatic renewal only ever applies to a Let's Encrypt certificate, since certbot's own timer is what does it
            $info['auto_renew'] = $source === 'letsencrypt';

            return $info;
        }

        return [
            'status' => 'none',
            'source' => '',
            'path' => '',
            'issuer' => '',
            'subject' => '',
            'domains' => [],
            'expires_at' => 0,
            'days_left' => 0,
            'auto_renew' => false,
        ];
    }

    /**
     * Reads any certificate file's details, anywhere on the machine
     *
     * Exists for use outside the SSL page — the mail hostname's own
     * certificate isn't tied to any one website (PLAN-MAIL phase M3), so it
     * can't call inspect(), which requires a Site.
     *
     * @return array<string,mixed>
     */
    public function inspectFile(Executor $executor, string $path): array
    {
        return $this->readCertificate($executor, $path);
    }

    /**
     * Reads details from a PEM file with openssl
     *
     * @return array<string,mixed>
     */
    private function readCertificate(Executor $executor, string $path): array
    {
        $result = $executor->exec(
            [self::OPENSSL, 'x509', '-in', $executor->path($path), '-noout', '-subject', '-issuer', '-enddate', '-ext', 'subjectAltName'],
            timeout: 15,
        );

        if (!$result->ok()) {
            return [
                'status' => 'invalid',
                'issuer' => '',
                'subject' => '',
                'domains' => [],
                'expires_at' => 0,
                'days_left' => 0,
            ];
        }

        $out = $result->stdout;
        $expiresAt = 0;

        if (preg_match('/^notAfter=(.+)$/m', $out, $m) === 1) {
            $expiresAt = strtotime(trim($m[1])) ?: 0;
        }

        $daysLeft = $expiresAt > 0 ? (int) floor(($expiresAt - time()) / 86400) : 0;

        return [
            'status' => match (true) {
                $expiresAt === 0 => 'invalid',
                $expiresAt <= time() => 'expired',
                $daysLeft <= self::WARN_DAYS => 'expiring',
                default => 'valid',
            },
            'issuer' => $this->field($out, 'issuer'),
            'subject' => $this->field($out, 'subject'),
            'domains' => $this->altNames($out),
            'expires_at' => $expiresAt,
            'days_left' => $daysLeft,
        ];
    }

    private function field(string $out, string $name): string
    {
        if (preg_match('/^' . preg_quote($name, '/') . '=(.+)$/m', $out, $m) !== 1) {
            return '';
        }

        // openssl returns "C = US, O = Let's Encrypt, CN = R11" — showing just the CN is enough
        if (preg_match('/CN\s*=\s*([^,\/]+)/', $m[1], $cn) === 1) {
            return trim($cn[1]);
        }

        return trim($m[1]);
    }

    /** @return list<string> */
    private function altNames(string $out): array
    {
        if (preg_match('/X509v3 Subject Alternative Name:\s*\R\s*(.+)/', $out, $m) !== 1) {
            return [];
        }

        $names = [];

        foreach (explode(',', $m[1]) as $entry) {
            $entry = trim($entry);

            if (str_starts_with($entry, 'DNS:')) {
                $names[] = substr($entry, 4);
            }
        }

        return $names;
    }

    /**
     * Requests a certificate from Let's Encrypt via the webroot method
     *
     * @param list<string> $domains every domain that must be in the same certificate
     */
    public function issue(Executor $executor, Site $site, array $domains, string $email, bool $staging): array
    {
        if (!$this->isInstalled($executor)) {
            throw new ValidationError('certbot was not found on this machine — install it with apt install certbot first');
        }

        if ($domains === []) {
            throw new ValidationError('At least one domain must be specified');
        }

        $webroot = $executor->path($site->docroot());

        if (!$executor->exists($webroot)) {
            throw new ValidationError("The website's directory was not found — finish creating the website before requesting a certificate");
        }

        // A wildcard can never be issued via HTTP-01 at all — Let's Encrypt
        // requires DNS-01 only, since proving control of a file on the site doesn't prove control of the whole domain
        $hasWildcard = false;
        foreach ($domains as $domain) {
            $hasWildcard = $hasWildcard || str_starts_with($domain, '*.');
        }

        $argv = [
            self::BINARY, 'certonly',
            '--non-interactive', '--agree-tos',
            '--email', self::assertEmail($email),
            // The certificate bundle's name is always tied to the primary domain, so its path can be predicted and the right one deleted
            '--cert-name', Validator::domain($site->domain),
            // Stops certbot from touching the web server's own config — this project owns that file
            '--no-eff-email',
        ];

        if ($hasWildcard) {
            array_push(
                $argv,
                '--manual',
                '--preferred-challenges', 'dns',
                '--manual-auth-hook', $executor->path(self::HOOK) . ' auth',
                '--manual-cleanup-hook', $executor->path(self::HOOK) . ' cleanup',
            );
        } else {
            array_push($argv, '--webroot', '--webroot-path', $webroot);
        }

        foreach ($domains as $domain) {
            // `*.example.com` fails Validator::domain, since `*` isn't a
            // valid hostname character — the remainder is validated
            // instead, then reassembled, so this check is never simply bypassed
            array_push($argv, '-d', str_starts_with($domain, '*.')
                ? '*.' . Validator::domain(substr($domain, 2))
                : Validator::domain($domain));
        }

        if ($staging) {
            // Let's Encrypt's staging server doesn't count against the
            // quota, so it can be practiced on without limit — the
            // resulting certificate isn't trusted by browsers, but it proves the entire ACME flow genuinely works
            $argv[] = '--staging';
        }

        $result = $executor->exec($argv, timeout: 180);

        if (!$result->ok()) {
            throw new ExecutionFailed($this->explain($result->stderr ?: $result->stdout));
        }

        return ['output' => trim($result->stdout), 'staging' => $staging];
    }

    /** Renews a single certificate — never a bare `certbot renew`, which would touch every certificate on the machine */
    public function renew(Executor $executor, string $certName, bool $force): array
    {
        $argv = [self::BINARY, 'renew', '--cert-name', Validator::domain($certName), '--non-interactive'];

        if ($force) {
            // certbot normally skips a certificate that isn't nearing
            // expiry yet, so the "renew now" button has to force it
            $argv[] = '--force-renewal';
        }

        $result = $executor->exec($argv, timeout: 180);

        if (!$result->ok()) {
            throw new ExecutionFailed($this->explain($result->stderr ?: $result->stdout));
        }

        return ['output' => trim($result->stdout)];
    }

    public function delete(Executor $executor, string $certName): void
    {
        $name = Validator::domain($certName);
        $result = $executor->exec([self::BINARY, 'delete', '--cert-name', $name, '--non-interactive'], timeout: 60);

        if (!$result->ok()) {
            throw new ExecutionFailed($this->explain($result->stderr ?: $result->stdout));
        }
    }

    /**
     * Generates a self-signed certificate
     *
     * Exists for two cases: a machine that hasn't had a domain genuinely
     * pointed at it yet, so Let's Encrypt can't be requested, and an
     * internal site with no public domain name at all.
     *
     * A browser will always show a warning, and that's correct — the
     * screen has to say so plainly, never make it look like an ordinary certificate.
     *
     * @param list<string> $domains every domain of the site · empty = use just the primary domain
     */
    public function selfSign(Executor $executor, Site $site, array $domains = [], int $days = 825): array
    {
        $domain = Validator::domain($site->domain);
        $dir = self::SELF_SIGNED_DIR . '/' . $domain;
        $resolved = $executor->path($dir);

        $executor->makeDirectory($resolved, 0700);

        /*
         * **Every domain of the site must be included, not just the primary one** — the same as issue() does
         *
         * A modern client only looks at subjectAltName, no longer caring
         * about CN at all · a certificate with only the primary domain
         * gets rejected instantly when reached through another name of
         * the same site (`www.` or `mail.`), even though the screen said the certificate installed successfully.
         *
         * Found while building M3: the mail hostname was one of the
         * site's own domains, so the self-signed certificate didn't cover
         * it at all, and `mail.cert` couldn't see it (correctly) — a path
         * that should have worked was blocked with nothing explaining why.
         */
        $names = [];

        foreach ($domains === [] ? [$site->domain] : $domains as $name) {
            // `*.example.com` fails Validator::domain, since `*` isn't a valid hostname character
            $names[] = str_starts_with((string) $name, '*.')
                ? '*.' . Validator::domain(substr((string) $name, 2))
                : Validator::domain((string) $name);
        }

        $names = array_values(array_unique($names));

        $result = $executor->exec([
            self::OPENSSL, 'req', '-x509', '-nodes',
            '-newkey', 'rsa:2048',
            '-days', (string) max(1, min($days, 3650)),
            '-keyout', $resolved . '/privkey.pem',
            '-out', $resolved . '/fullchain.pem',
            // -subj stops openssl from stopping to ask interactively and hanging the command
            '-subj', '/CN=' . $domain,
            // Must be combined into a single -addext separated by commas · sending it twice makes openssl complain about a duplicate
            '-addext', 'subjectAltName=' . implode(',', array_map(
                static fn (string $name): string => 'DNS:' . $name,
                $names,
            )),
            // `openssl req -x509` sets CA:TRUE on its own when not
            // specified, which is wrong for a website's certificate —
            // Apache would warn AH01906, and modern browsers reject a CA certificate instantly
            '-addext', 'basicConstraints=critical,CA:FALSE',
            '-addext', 'keyUsage=critical,digitalSignature,keyEncipherment',
            '-addext', 'extendedKeyUsage=serverAuth',
        ], timeout: 60);

        if (!$result->ok()) {
            throw new ExecutionFailed('Failed to generate a self-signed certificate: ' . trim($result->stderr));
        }

        $executor->changeMode($resolved . '/privkey.pem', 0600);

        return ['path' => $dir];
    }

    /** Deletes a self-signed certificate — kept separate from delete(), since certbot has never heard of these */
    public function deleteSelfSigned(Executor $executor, string $domain): void
    {
        $dir = self::SELF_SIGNED_DIR . '/' . Validator::domain($domain);
        $resolved = $executor->path($dir);

        if (!$executor->exists($resolved)) {
            return;
        }

        $real = $executor->realPath($resolved);
        $base = rtrim($executor->path(self::SELF_SIGNED_DIR), '/');

        // Checked again after realpath, in case a symlink points outside the directory
        if ($real === null || !str_starts_with($real, $base . '/')) {
            throw new ValidationError('The certificate path is outside the configured directory — deletion cancelled');
        }

        $executor->removePath($real);
    }

    /** The automatic-renewal timer's status — a user has to see whether it's genuinely running or not */
    public function autoRenewActive(Executor $executor): bool
    {
        $result = $executor->exec(
            [$executor->path('/usr/bin/systemctl'), 'is-enabled', 'certbot.timer'],
            timeout: 15,
        );

        return str_starts_with(trim($result->stdout), 'enabled');
    }

    public static function assertEmail(string $email): string
    {
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new ValidationError('Invalid email — Let\'s Encrypt uses this email to warn when a certificate is nearing expiry');
        }

        if (mb_strlen($email) > 254) {
            throw new ValidationError('Email is too long');
        }

        return $email;
    }

    /**
     * Turns certbot's own error message into something an admin can act on
     *
     * certbot's raw message is long and mixed with a lot of ACME detail —
     * most users only ever run into a handful of causes, so this states
     * the cause and the fix directly, then attaches the original at the end.
     */
    private function explain(string $raw): string
    {
        $raw = trim($raw);

        $hint = match (true) {
            str_contains($raw, 'NXDOMAIN') || str_contains($raw, 'DNS problem') =>
                'The domain does not correctly point at this server yet — check the DNS record points at this machine\'s IP first',
            str_contains($raw, 'Timeout during connect') =>
                'Let\'s Encrypt could not connect in on port 80 — check that the firewall has port 80 open and Apache is running',
            str_contains($raw, 'too many certificates') || str_contains($raw, 'rateLimited') =>
                'Requested a certificate for this domain more often than Let\'s Encrypt\'s quota allows — wait '
                . 'and try again, or use test mode (staging) while still adjusting settings',
            str_contains($raw, '404') && str_contains($raw, 'acme-challenge') =>
                'Let\'s Encrypt could not reach the validation file at .well-known/acme-challenge — '
                . 'check that DocumentRoot is correct and no rewrite rule is intercepting that file',
            /*
             * A name with no public TLD — `.test`, `.local`, `.internal`, `.lan`, or a bare name
             *
             * **Not a mistake that can be fixed and retried** · Let's
             * Encrypt can never issue a certificate to a name that cannot
             * prove ownership through public DNS by definition — no number
             * of retries changes that · nearly every dev machine uses a
             * name like this, so the message has to point toward something
             * that genuinely works, not just say it failed and leave someone chasing DNS that can never be right.
             */
            str_contains($raw, 'valid public suffix') || str_contains($raw, 'Domain name does not end with') =>
                'This name has no public TLD (e.g. ending in .test, .local, .internal) — Let\'s Encrypt '
                . 'can never issue a certificate for it no matter how many times it\'s tried, since ownership '
                . 'can\'t be proven through public DNS · use "self-signed" instead, which genuinely works for '
                . 'both HTTPS and mail, except a browser or mail client will show a warning',
            default => 'Failed to request the certificate',
        };

        return $hint . "\n\n" . mb_substr($raw, 0, 1200);
    }
}
