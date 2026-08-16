<?php

declare(strict_types=1);

namespace Phpcp\Agent\Executor;

/**
 * Simulates certbot in sandbox mode
 *
 * Needed for security reasons, same as UfwSimulator and MariaDbSimulator: /usr/bin
 * is on SandboxExecutor's passthrough list — without intercepting it, every test
 * run would fire a real request at Let's Encrypt, burning through its per-domain
 * quota (5 certificates a week) and potentially locking a real domain out of
 * issuing certificates for days.
 *
 * openssl itself is not simulated, because openssl works on files inside the
 * already-mapped sandbox area and never talks to anything external — letting it
 * run for real means expiry-date reading can be tested against the real thing
 * (ARCHITECTURE §6.3).
 */
final class CertbotSimulator implements Simulator
{
    public function handles(string $binary): bool
    {
        return basename($binary) === 'certbot';
    }

    public function simulate(array $argv, SandboxState $state, ?string $stdin = null): ExecResult
    {
        $command = $argv[1] ?? '';

        return match ($command) {
            'certonly' => $this->certonly($argv, $state),
            'renew' => $this->renew($argv, $state),
            'delete' => $this->delete($argv, $state),
            'certificates' => $this->certificates($argv, $state),
            default => $this->fail($argv, "sandbox: the certbot {$command} command isn't supported yet"),
        };
    }

    /**
     * Issues a fake certificate using the real openssl
     *
     * Has to be a genuinely valid PEM file, not an empty one, because
     * CertbotManager reads the expiry date with `openssl x509`, and Apache has to
     * be able to load this file during configtest. Simulating it with a fake file
     * would let the test pass while the real path is actually broken.
     */
    private function certonly(array $argv, SandboxState $state): ExecResult
    {
        $name = $this->option($argv, '--cert-name');
        $domains = $this->domains($argv);

        if ($name === '' && $domains !== []) {
            $name = $domains[0];
        }

        if ($name === '') {
            return $this->fail($argv, 'sandbox: no domain specified');
        }

        $dir = $state->prefix() . '/etc/letsencrypt/live/' . $name;

        if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
            return $this->fail($argv, "sandbox: could not create directory {$dir}");
        }

        $alt = implode(',', array_map(static fn (string $d): string => 'DNS:' . $d, $domains ?: [$name]));

        // 90 days, matching real life, so expiry-date tests use the same numbers as production
        $result = (new RealExecutor())->exec([
            '/usr/bin/openssl', 'req', '-x509', '-nodes',
            '-newkey', 'rsa:2048', '-days', '90',
            '-keyout', $dir . '/privkey.pem',
            '-out', $dir . '/fullchain.pem',
            '-subj', '/CN=' . $name,
            '-addext', 'subjectAltName=' . $alt,
            // Matches a real Let's Encrypt certificate — not a CA certificate
            '-addext', 'basicConstraints=critical,CA:FALSE',
            '-addext', 'keyUsage=critical,digitalSignature,keyEncipherment',
            '-addext', 'extendedKeyUsage=serverAuth',
        ], 60);

        if (!$result->ok()) {
            return $this->fail($argv, 'sandbox: failed to create the simulated certificate — ' . trim($result->stderr));
        }

        @chmod($dir . '/privkey.pem', 0600);

        $certificates = $state->read('certificates');
        $certificates[$name] = ['domains' => $domains ?: [$name], 'issued_at' => time()];
        $state->write('certificates', $certificates);

        return $this->out($argv, sprintf(
            "Successfully received certificate.\nCertificate is saved at: %s/fullchain.pem\n",
            $dir,
        ));
    }

    private function renew(array $argv, SandboxState $state): ExecResult
    {
        $name = $this->option($argv, '--cert-name');
        $certificates = $state->read('certificates');

        if ($name === '' || !isset($certificates[$name])) {
            return $this->fail($argv, "sandbox: no certificate named {$name} was found");
        }

        if (!in_array('--force-renewal', $argv, true)) {
            // The real certbot skips a certificate that isn't close to expiring
            // and exits with code 0 — this behavior has to be simulated too, or a
            // test would never catch the "clicked renew and nothing happened" case
            return $this->out($argv, "Certificate not yet due for renewal; no action taken.\n");
        }

        $issue = array_values(array_filter($argv, static fn (string $a): bool => $a !== 'renew' && $a !== '--force-renewal'));
        array_splice($issue, 1, 0, 'certonly');

        foreach ($certificates[$name]['domains'] as $domain) {
            array_push($issue, '-d', $domain);
        }

        return $this->certonly($issue, $state);
    }

    private function delete(array $argv, SandboxState $state): ExecResult
    {
        $name = $this->option($argv, '--cert-name');
        $certificates = $state->read('certificates');

        if ($name === '' || !isset($certificates[$name])) {
            return $this->fail($argv, "sandbox: no certificate named {$name} was found");
        }

        $dir = $state->prefix() . '/etc/letsencrypt/live/' . $name;

        foreach (['fullchain.pem', 'privkey.pem'] as $file) {
            @unlink($dir . '/' . $file);
        }

        @rmdir($dir);

        unset($certificates[$name]);
        $state->write('certificates', $certificates);

        return $this->out($argv, "Deleted all files relating to certificate {$name}.\n");
    }

    private function certificates(array $argv, SandboxState $state): ExecResult
    {
        $lines = ['Found the following certs:'];

        foreach ($state->read('certificates') as $name => $info) {
            $lines[] = '  Certificate Name: ' . $name;
            $lines[] = '    Domains: ' . implode(' ', $info['domains']);
        }

        return $this->out($argv, implode("\n", $lines) . "\n");
    }

    private function option(array $argv, string $name): string
    {
        $at = array_search($name, $argv, true);

        return $at === false ? '' : (string) ($argv[$at + 1] ?? '');
    }

    /** @return list<string> */
    private function domains(array $argv): array
    {
        $domains = [];
        $count = count($argv);

        for ($i = 0; $i < $count; $i++) {
            if ($argv[$i] === '-d' && isset($argv[$i + 1])) {
                $domains[] = $argv[$i + 1];
            }
        }

        return $domains;
    }

    private function out(array $argv, string $stdout): ExecResult
    {
        return new ExecResult($argv, 0, $stdout, '', 120, simulated: true);
    }

    private function fail(array $argv, string $message): ExecResult
    {
        return new ExecResult($argv, 1, '', $message . "\n", 20, simulated: true);
    }
}
