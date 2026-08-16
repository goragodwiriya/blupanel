<?php

declare(strict_types=1);

namespace Phpcp\Agent\Executor;

/**
 * Simulates ufw in sandbox mode
 *
 * Needed for security reasons, same as MariaDbSimulator, not just convenience:
 * /usr/sbin is on SandboxExecutor's passthrough list (since that's where the
 * binary lives) — without simulating it, `ufw --force enable` in test mode would
 * enable the machine's real firewall, and could cut off whoever is testing right now.
 *
 * State is kept in SandboxState, and output is printed in exactly the same format
 * as real ufw, because UfwDriver parses that output with a regex — if the format
 * drifts, the test stops meaning anything.
 */
final class UfwSimulator implements Simulator
{
    public function handles(string $binary): bool
    {
        return basename($binary) === 'ufw';
    }

    public function simulate(array $argv, SandboxState $state, ?string $stdin = null): ExecResult
    {
        // Strips out --force first, since it lands in a different position depending on the command
        $args = array_values(array_filter(array_slice($argv, 1), static fn (string $a): bool => $a !== '--force'));
        $command = $args[0] ?? 'status';

        return match (true) {
            $command === 'show' && ($args[1] ?? '') === 'added' => $this->showAdded($argv, $state),
            $command === 'status' => $this->status($argv, $state),
            $command === 'enable' => $this->setActive($argv, $state, true),
            $command === 'disable' => $this->setActive($argv, $state, false),
            $command === 'allow' || $command === 'deny' => $this->add($argv, $state, $command, array_slice($args, 1)),
            $command === 'delete' => $this->delete($argv, $state, array_slice($args, 1)),
            default => $this->fail($argv, "sandbox: the ufw {$command} command isn't supported yet"),
        };
    }

    private function status(array $argv, SandboxState $state): ExecResult
    {
        $data = $this->load($state);

        if (!$data['active']) {
            return $this->out($argv, "Status: inactive\n");
        }

        $out = "Status: active\n\n     To                         Action      From\n"
            . "     --                         ------      ----\n";

        foreach ($data['rules'] as $index => $rule) {
            $out .= sprintf(
                "[%2d] %-26s %-11s %s\n",
                $index + 1,
                $this->target($rule),
                $rule['action'] . ' IN',
                $rule['source'] === '' ? 'Anywhere' : $rule['source'],
            );
        }

        return $this->out($argv, $out);
    }

    /** `ufw show added` shows configured rules regardless of whether the firewall is enabled */
    private function showAdded(array $argv, SandboxState $state): ExecResult
    {
        $out = "Added user rules (see 'ufw status' for running firewall):\n";

        foreach ($this->load($state)['rules'] as $rule) {
            $out .= 'ufw ' . strtolower($rule['action']) . ' ';
            $out .= $rule['source'] === ''
                ? $this->target($rule) . "\n"
                : sprintf(
                    "from %s to any port %s%s\n",
                    $rule['source'],
                    $rule['port'],
                    $rule['protocol'] === 'any' ? '' : ' proto ' . $rule['protocol'],
                );
        }

        return $this->out($argv, $out);
    }

    private function setActive(array $argv, SandboxState $state, bool $active): ExecResult
    {
        $data = $this->load($state);
        $data['active'] = $active;
        $state->write('firewall', $data);

        return $this->out(
            $argv,
            $active
                ? "Firewall is active and enabled on system startup\n"
                : "Firewall stopped and disabled on system startup\n",
        );
    }

    /** @param list<string> $spec */
    private function add(array $argv, SandboxState $state, string $action, array $spec): ExecResult
    {
        $rule = $this->parseSpec($spec);
        $rule['action'] = strtoupper($action);

        $data = $this->load($state);

        foreach ($data['rules'] as $existing) {
            if ($this->same($existing, $rule)) {
                return $this->out($argv, "Skipping adding existing rule\n");
            }
        }

        $data['rules'][] = $rule;
        $state->write('firewall', $data);

        return $this->out($argv, "Rule added\n");
    }

    /** @param list<string> $spec */
    private function delete(array $argv, SandboxState $state, array $spec): ExecResult
    {
        $action = strtoupper(array_shift($spec) ?? '');
        $rule = $this->parseSpec($spec);
        $rule['action'] = $action;

        $data = $this->load($state);

        foreach ($data['rules'] as $index => $existing) {
            if ($this->same($existing, $rule)) {
                array_splice($data['rules'], $index, 1);
                $state->write('firewall', $data);

                return $this->out($argv, "Rule deleted\n");
            }
        }

        return $this->fail($argv, "ERROR: Could not delete non-existent rule");
    }

    /**
     * Turns the part of argv describing a rule's scope back into a structure
     *
     * Accepts both forms UfwDriver produces:
     *   8080/tcp
     *   from 203.0.113.5 to any port 8080 proto tcp
     *
     * @param list<string> $spec
     * @return array{port:string,protocol:string,source:string,action:string}
     */
    private function parseSpec(array $spec): array
    {
        $rule = ['port' => '', 'protocol' => 'any', 'source' => '', 'action' => 'ALLOW'];

        if ($spec !== [] && !in_array($spec[0], ['from', 'to', 'port', 'proto'], true)) {
            [$port, $protocol] = array_pad(explode('/', $spec[0], 2), 2, 'any');
            $rule['port'] = $port;
            $rule['protocol'] = $protocol;

            return $rule;
        }

        $count = count($spec);

        for ($i = 0; $i < $count; $i++) {
            $next = $spec[$i + 1] ?? '';

            match ($spec[$i]) {
                'from' => $rule['source'] = $next === 'any' ? '' : $next,
                'port' => $rule['port'] = $next,
                'proto' => $rule['protocol'] = $next,
                default => null,
            };
        }

        return $rule;
    }

    /** @return string the "To" column of ufw's output */
    private function target(array $rule): string
    {
        return $rule['protocol'] === 'any' ? $rule['port'] : $rule['port'] . '/' . $rule['protocol'];
    }

    private function same(array $a, array $b): bool
    {
        return $a['port'] === $b['port']
            && $a['protocol'] === $b['protocol']
            && $a['source'] === $b['source']
            && $a['action'] === $b['action'];
    }

    /** @return array{active:bool,rules:list<array<string,string>>} */
    private function load(SandboxState $state): array
    {
        $data = $state->read('firewall');

        if ($data === []) {
            // Starts with the firewall disabled and no rules at all — matches a
            // machine that just ran apt install ufw, so the "first enable" path,
            // the most dangerous one, can actually be tested
            $data = ['active' => false, 'rules' => []];
            $state->write('firewall', $data);
        }

        return ['active' => (bool) ($data['active'] ?? false), 'rules' => array_values($data['rules'] ?? [])];
    }

    private function out(array $argv, string $stdout): ExecResult
    {
        return new ExecResult($argv, 0, $stdout, '', 8, simulated: true);
    }

    private function fail(array $argv, string $message): ExecResult
    {
        return new ExecResult($argv, 1, '', $message . "\n", 8, simulated: true);
    }
}
