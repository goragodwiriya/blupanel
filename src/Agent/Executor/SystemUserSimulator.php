<?php

declare(strict_types=1);

namespace Phpcp\Agent\Executor;

/**
 * Simulates useradd / userdel / id and file-ownership commands in sandbox mode
 *
 * Has to be simulated for two reasons:
 *   1. Creating a real system user needs root and touches the machine's own
 *      /etc/passwd, which directly breaks sandbox mode's principle
 *   2. chown/chgrp on sandbox files also needs root — letting it run for real
 *      would always fail, making the site-creation flow untestable entirely
 *
 * The returned uid is a simulated value, fixed per username, so results stay
 * repeatable every time.
 */
final class SystemUserSimulator implements Simulator
{
    private const UID_BASE = 3000;

    public function handles(string $binary): bool
    {
        return in_array(basename($binary), ['useradd', 'userdel', 'usermod', 'id', 'chown', 'chgrp', 'getent'], true);
    }

    public function simulate(array $argv, SandboxState $state, ?string $stdin = null): ExecResult
    {
        return match (basename($argv[0])) {
            'useradd' => $this->useradd($argv, $state),
            'userdel' => $this->userdel($argv, $state),
            'id' => $this->id($argv, $state),
            'getent' => $this->getent($argv, $state),
            // Changing file ownership has no effect in the sandbox, but must still report success
            // — otherwise the site-creation flow would trip up right at the start
            'chown', 'chgrp', 'usermod' => $this->ok($argv),
            default => $this->fail($argv, 'sandbox: this command isn\'t supported yet'),
        };
    }

    private function useradd(array $argv, SandboxState $state): ExecResult
    {
        $name = $this->lastWord($argv);
        if ($name === '') {
            return $this->fail($argv, 'useradd: no username specified', 2);
        }

        $users = $state->read('passwd');

        if (isset($users[$name])) {
            return $this->fail($argv, "useradd: user '{$name}' already exists", 9);
        }

        $uid = self::UID_BASE + count($users) + 1;
        $users[$name] = [
            'uid' => $uid,
            'gid' => $uid,
            'home' => $this->optionValue($argv, '-d') ?: $this->optionValue($argv, '--home-dir') ?: ('/home/' . $name),
            'shell' => $this->optionValue($argv, '-s') ?: $this->optionValue($argv, '--shell') ?: '/bin/sh',
            /*
             * The comment is stored because it is **evidence**, not decoration
             *
             * `AccountProvisioner` decides whether a system account is the
             * panel's own from the signature it stamps in here — dropping it
             * would make `getent` below answer with an empty comment field, and
             * every account the sandbox created would read as somebody else's.
             */
            'comment' => $this->optionValue($argv, '-c') ?: $this->optionValue($argv, '--comment'),
            'created_at' => time(),
        ];

        $state->write('passwd', $users);

        return $this->ok($argv);
    }

    private function userdel(array $argv, SandboxState $state): ExecResult
    {
        $name = $this->lastWord($argv);
        $users = $state->read('passwd');

        if (!isset($users[$name])) {
            return $this->fail($argv, "userdel: user '{$name}' does not exist", 6);
        }

        unset($users[$name]);
        $state->write('passwd', $users);

        return $this->ok($argv);
    }

    private function id(array $argv, SandboxState $state): ExecResult
    {
        $name = $this->lastWord($argv);
        $users = $state->read('passwd');

        if (!isset($users[$name])) {
            return $this->fail($argv, "id: '{$name}': no such user", 1);
        }

        $wantsGroup = in_array('-g', $argv, true);
        $value = $wantsGroup ? $users[$name]['gid'] : $users[$name]['uid'];

        return new ExecResult(
            argv: $argv,
            exitCode: 0,
            stdout: $value . "\n",
            stderr: '',
            durationMs: 1,
            simulated: true,
        );
    }

    /**
     * `getent passwd <name>` — the only lookup the panel makes, and it has to
     * agree with what `useradd` above recorded
     *
     * Left unsimulated until now, which meant the sandbox contradicted itself:
     * `useradd` wrote the account into the sandbox's own state, and the
     * `getent` that checks whether a name is free read the **developer's real
     * `/etc/passwd`** instead. The two guards that depend on it —
     * `AccountProvisioner::assertNameAvailable()` and
     * `CustomerCreate::assertNoLeftovers()` — could therefore never be
     * exercised at all, and a machine that happened to have a matching account
     * would have made them behave differently from every other machine.
     *
     * Exit 2 for "not found" is what real getent returns, and both callers read
     * only "did it succeed", so nothing depends on the exact code.
     */
    private function getent(array $argv, SandboxState $state): ExecResult
    {
        if (($argv[1] ?? '') !== 'passwd') {
            return $this->fail($argv, 'sandbox: only `getent passwd` is simulated', 2);
        }

        $name = $this->lastWord($argv);
        $users = $state->read('passwd');

        if ($name === '' || !isset($users[$name])) {
            return $this->fail($argv, '', 2);
        }

        $user = $users[$name];

        return new ExecResult(
            argv: $argv,
            exitCode: 0,
            // name:x:uid:gid:comment:home:shell — the shape both callers split on
            stdout: sprintf(
                "%s:x:%d:%d:%s:%s:%s\n",
                $name,
                (int) $user['uid'],
                (int) $user['gid'],
                (string) ($user['comment'] ?? ''),
                (string) ($user['home'] ?? ''),
                (string) ($user['shell'] ?? ''),
            ),
            stderr: '',
            durationMs: 1,
            simulated: true,
        );
    }

    /** The last argument that isn't an option — that's the username */
    private function lastWord(array $argv): string
    {
        $words = array_values(array_filter(
            array_slice($argv, 1),
            static fn (string $a): bool => !str_starts_with($a, '-'),
        ));

        return $words === [] ? '' : (string) end($words);
    }

    private function optionValue(array $argv, string $option): string
    {
        foreach ($argv as $index => $arg) {
            if ($arg === $option) {
                return (string) ($argv[$index + 1] ?? '');
            }
        }

        return '';
    }

    private function ok(array $argv): ExecResult
    {
        return new ExecResult($argv, 0, '', '', 2, simulated: true);
    }

    private function fail(array $argv, string $message, int $code = 1): ExecResult
    {
        return new ExecResult($argv, $code, '', $message . "\n", 2, simulated: true);
    }
}
