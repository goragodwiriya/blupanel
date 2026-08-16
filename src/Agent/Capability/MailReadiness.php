<?php

declare(strict_types=1);

namespace Phpcp\Agent\Capability;

use Phpcp\Agent\Context;
use Phpcp\Agent\Executor\Executor;
use Phpcp\Domain\MailboxRepository;
use Phpcp\Domain\SettingsRepository;
use Phpcp\Driver\Mail\DkimManager;
use Phpcp\Driver\Mail\MailCertificate;
use Phpcp\Driver\Ssl\CertbotManager;

/**
 * Mail readiness — PLAN-MAIL §7
 *
 * **This is where phase M3's real substance lives** · PLAN-V2 originally cut
 * mail out because of three things code can't fix (rDNS can only be set at
 * the provider · outbound port 25 is often blocked · a new IP has no
 * reputation yet) · this phase doesn't make those three go away, it makes
 * **the system say so plainly** — which item isn't ready and which one has
 * to be handled somewhere else — instead of leaving an admin to discover it
 * when a customer calls to say mail never arrived.
 *
 * Every check always returns the same three things: pass or not · what was
 * actually found · who can fix it (the panel, or somewhere else).
 */
final class MailReadiness extends MailCapability
{
    public static function name(): string
    {
        return 'mail.readiness';
    }

    public function permission(): string
    {
        return 'mail.view';
    }

    public function isMutating(): bool
    {
        return false;
    }

    public function summary(): string
    {
        return 'Check mail readiness across six items';
    }

    public function validate(array $args): array
    {
        return [];
    }

    public function run(array $args, Executor $executor, Context $context): array
    {
        $settings = new SettingsRepository($context->db);
        // Has to be the name Postfix actually announces, not the value in the form field — a readiness page that says "not set" while the machine correctly announces a name sends every investigation the wrong way
        $hostname = self::mailHostname($settings);
        $domains = (new MailboxRepository($context->db))->enabledDomains();

        $checks = [
            $this->hostnameCheck($hostname),
            $this->listeningCheck($executor),
            $this->outboundCheck($executor, $settings->get('mail.mode') ?: 'local'),
            $this->rdnsCheck($executor, $hostname),
            $this->dkimCheck($executor, $context, $domains),
            $this->tlsCheck($executor, $settings, $hostname),
        ];

        $failed = count(array_filter($checks, static fn (array $c): bool => !$c['ok']));

        return [
            'checks' => $checks,
            'ready' => $failed === 0,
            'failed' => $failed,
            'domains' => $domains,
            'message' => $failed === 0
                ? 'Mail is ready on every item'
                : sprintf('%d of %d item(s) are not ready yet', $failed, count($checks)),
        ];
    }

    /** @return array{key:string,ok:bool,found:string,fix:string} */
    private function hostnameCheck(string $hostname): array
    {
        return [
            'key' => 'hostname',
            'ok' => $hostname !== '' && str_contains($hostname, '.'),
            'found' => $hostname !== '' ? $hostname : 'not set yet',
            // Can be set from the settings page, so this is the panel's job
            'fix' => 'panel',
        ];
    }

    /** @return array{key:string,ok:bool,found:string,fix:string} */
    private function listeningCheck(Executor $executor): array
    {
        $result = $executor->exec([$executor->path('/usr/bin/ss'), '-ltnH'], timeout: 10);
        $public = false;

        foreach (explode("\n", $result->stdout) as $line) {
            $fields = preg_split('/\s+/', trim($line)) ?: [];
            $local = $fields[3] ?? '';

            if (!str_ends_with($local, ':25')) {
                continue;
            }

            $address = trim(substr($local, 0, -3), '[]');

            if ($address !== '127.0.0.1' && $address !== '::1') {
                $public = true;
                break;
            }
        }

        return [
            'key' => 'listening',
            'ok' => $public,
            'found' => $public ? 'Already listening on port 25 externally' : 'Only listening on loopback',
            'fix' => 'panel',
        ];
    }

    /**
     * Can it genuinely send outbound — connects on port 25 to Gmail's MX
     *
     * Relay mode doesn't need outbound port 25 at all, so it's always
     * treated as passing — reporting "not passing" on a machine that already
     * intends to send through a relay would be noise, not information.
     *
     * @return array{key:string,ok:bool,found:string,fix:string}
     */
    private function outboundCheck(Executor $executor, string $mode): array
    {
        if ($mode === 'relay') {
            return ['key' => 'outbound', 'ok' => true, 'found' => "Sending through a relay doesn't need outbound port 25", 'fix' => 'panel'];
        }

        $socket = @stream_socket_client('tcp://gmail-smtp-in.l.google.com:25', $errno, $error, 6);
        $ok = is_resource($socket);

        if ($ok) {
            fclose($socket);
        }

        return [
            'key' => 'outbound',
            'ok' => $ok,
            'found' => $ok ? 'Can connect out on port 25' : 'Outbound port 25 is blocked',
            // Getting it unblocked means filing a request with the provider, or switching to a relay
            'fix' => $ok ? 'panel' : 'provider',
        ];
    }

    /** @return array{key:string,ok:bool,found:string,fix:string} */
    private function rdnsCheck(Executor $executor, string $hostname): array
    {
        $ip = trim($executor->exec([$executor->path('/bin/sh'), '-c', 'hostname -I | awk "{print \$1}"'], timeout: 10)->stdout);
        $ptr = $ip !== '' ? trim((string) gethostbyaddr($ip)) : '';

        return [
            'key' => 'rdns',
            'ok' => $ptr !== '' && $ptr !== $ip && $ptr === $hostname,
            'found' => $ptr !== '' ? $ptr : 'No PTR record',
            // **The one item the panel genuinely cannot do at all** — has to be set at the VPS provider
            'fix' => 'provider',
        ];
    }

    /**
     * @param list<string> $domains
     * @return array{key:string,ok:bool,found:string,fix:string}
     */
    private function dkimCheck(Executor $executor, Context $context, array $domains): array
    {
        if ($domains === []) {
            return ['key' => 'dkim', 'ok' => false, 'found' => 'No domain has mail enabled yet', 'fix' => 'panel'];
        }

        $manager = new DkimManager(new \Phpcp\Driver\Template($context->config->paths->templates()));
        $missing = [];

        foreach ($domains as $domain) {
            if (!$executor->exists($executor->path($manager->keyPath($domain)))) {
                $missing[] = $domain;
            }
        }

        return [
            'key' => 'dkim',
            'ok' => $missing === [],
            'found' => $missing === []
                ? sprintf('All %d domain(s) have a key', count($domains))
                : 'No key yet for: ' . implode(', ', $missing),
            'fix' => 'panel',
        ];
    }

    /**
     * The mail hostname's certificate — **read from the real file, never
     * trusted from the database value**
     *
     * The saved value can only ever say "a certificate was bound at some
     * point" · what an admin genuinely needs to know is whether the
     * certificate the daemon is using right now has expired, and whether the
     * name in it matches the name the machine announces — a certificate that
     * expired yesterday is still a file that "exists" in every sense (§7
     * specifies reading the expiry date from the file itself).
     *
     * @return array{key:string,ok:bool,found:string,fix:string}
     */
    private function tlsCheck(Executor $executor, SettingsRepository $settings, string $hostname): array
    {
        $cert = trim($settings->get('mail.tls_cert'));

        if ($cert === '' || !$executor->exists($executor->path($cert))) {
            /*
             * No certificate bound yet — but also report whether a usable one
             * is already sitting on the machine waiting · "never configured"
             * and "already requested but never bound" need two different fixes.
             */
            $available = (new MailCertificate(new CertbotManager()))->locate($executor, $hostname);

            return [
                'key' => 'tls',
                'ok' => false,
                'found' => $available !== null
                    ? sprintf('A certificate for %s already exists but mail isn\'t using it yet — click "bind certificate"', $available['name'])
                    : "Using the distro's default certificate (mail clients will warn)",
                'fix' => 'panel',
            ];
        }

        $info = (new CertbotManager())->inspectFile($executor, $cert);
        $status = (string) ($info['status'] ?? 'invalid');
        $days = (int) ($info['days_left'] ?? 0);
        $covers = MailCertificate::covers((array) ($info['domains'] ?? []), $hostname);

        return [
            'key' => 'tls',
            // Nearly expired still counts as passing — certbot renews on its own at 30 days out; turning this red at that point would just train an admin to ignore a permanently-red item with nothing to actually do about it
            'ok' => in_array($status, ['valid', 'expiring'], true) && $covers,
            'found' => match (true) {
                !$covers => sprintf('This certificate does not cover %s (covers: %s)', $hostname, implode(', ', (array) ($info['domains'] ?? [])) ?: '—'),
                $status === 'expired' => sprintf('Already expired (%s)', $cert),
                $status === 'invalid' => sprintf('Failed to read the certificate file (%s)', $cert),
                default => sprintf('%s · %d day(s) left', $cert, $days),
            },
            'fix' => 'panel',
        ];
    }
}
