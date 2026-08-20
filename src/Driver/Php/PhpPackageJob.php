<?php

declare(strict_types=1);

namespace Phpcp\Driver\Php;

use Phpcp\Agent\ExecutionFailed;
use Phpcp\Agent\Executor\Executor;
use Phpcp\Domain\PhpSupport;

/**
 * Runs one long apt job under systemd and reports on it afterwards
 *
 * ## The problem
 *
 * Installing a PHP version takes one to five minutes. The agent answers a
 * request within thirty seconds ({@see \Phpcp\Agent\Client}), so the work
 * cannot happen inside the request that asks for it. Raising that timeout
 * would be worse, not better: it would hold a web request open for minutes and
 * still lose the result the moment the browser was closed.
 *
 * ## The approach
 *
 * `systemd-run` starts the command as a transient service and returns
 * immediately. The panel already depends on systemd for every service it
 * manages, so this adds no new moving part — no queue table, no worker
 * process, no lock file. It also means the job belongs to the machine rather
 * than to a browser tab: closing the page, or the panel restarting mid-install,
 * does not interrupt apt halfway through unpacking.
 *
 * ## Reading the result back
 *
 * A transient unit normally disappears the instant it finishes, taking its
 * exit status with it — which would leave "did it work?" unanswerable.
 * `RemainAfterExit=yes` keeps the unit around in a completed state so
 * `systemctl show` can still be asked, and each new job clears the previous
 * one first. The output itself comes from the journal, where systemd puts it
 * anyway.
 */
final class PhpPackageJob
{
    private const SYSTEMD_RUN = '/usr/bin/systemd-run';
    private const SYSTEMCTL = '/usr/bin/systemctl';
    private const JOURNALCTL = '/usr/bin/journalctl';
    private const APT_GET = '/usr/bin/apt-get';

    /** How much of the job's output the screen gets — enough to see the real error, not a whole apt transcript */
    private const LOG_LINES = 40;

    /**
     * The transient unit's name for a version
     *
     * **Dots become dashes.** systemd reads everything after the last dot as
     * the unit *type*, so `phpcp-php-8.4` would be a unit of type `4` and
     * every command against it would fail with a message about an unknown unit
     * type — which looks nothing like the actual mistake.
     */
    public function unit(string $version): string
    {
        return 'phpcp-php-' . str_replace('.', '-', $version) . '.service';
    }

    public function isSupportedHere(Executor $executor): bool
    {
        return $executor->exists($executor->path(self::SYSTEMD_RUN));
    }

    /**
     * Start the job — returns as soon as systemd has accepted it
     *
     * @param list<string> $aptArgs the apt-get sub-command and its arguments, already assembled from validated pieces
     * @return array{started:bool,unit:string,action:string,message:string}
     */
    public function start(Executor $executor, string $version, string $action, array $aptArgs): array
    {
        if (!$this->isSupportedHere($executor)) {
            throw new ExecutionFailed(
                'systemd-run was not found on this machine, so package installation cannot be run in the background — '
                . 'install or remove the packages from a terminal instead',
            );
        }

        $unit = $this->unit($version);
        $running = $this->status($executor, $version);

        if ($running['state'] === 'running') {
            throw new ExecutionFailed(sprintf('A %s job for PHP %s is already running', $running['action'], $version));
        }

        // Clear whatever the previous job left behind · a completed or failed
        // unit still holds the name, and systemd-run would refuse to reuse it
        $this->clear($executor, $unit);

        $result = $executor->exec([
            $executor->path(self::SYSTEMD_RUN),
            '--unit=' . $unit,
            '--description=phpcp ' . $action . ' PHP ' . $version,
            // Keeps the finished unit readable — without this the exit status
            // vanishes the moment apt returns and the panel can never say
            // whether the job worked
            '--property=RemainAfterExit=yes',
            '--property=Type=oneshot',
            /*
             * **`--no-block` is not optional with `Type=oneshot`**
             *
             * `systemctl start` on a oneshot unit waits for the whole thing to
             * finish · without this, the call that was supposed to return in
             * milliseconds would sit there for the entire apt run and hit the
             * agent's thirty-second timeout — reintroducing the exact problem
             * this class exists to avoid, but harder to spot, because apt would
             * still be running perfectly well in the background
             */
            '--no-block',
            // apt asks about a changed config file and then waits forever ·
            // there is nobody at this terminal to answer, so answer in advance:
            // keep the existing file, which is the choice that never destroys
            // an admin's own edits
            '--setenv=DEBIAN_FRONTEND=noninteractive',
            $executor->path(self::APT_GET),
            '-o', 'Dpkg::Options::=--force-confold',
            '-o', 'Dpkg::Options::=--force-confdef',
            ...$aptArgs,
        ], timeout: 30);

        if (!$result->ok()) {
            throw new ExecutionFailed('Could not start the job: ' . trim($result->stderr ?: $result->stdout));
        }

        return [
            'started' => true,
            'unit' => $unit,
            'action' => $action,
            'message' => sprintf(
                'Started %s for PHP %s — this takes a few minutes, and the page will report when it finishes',
                $action,
                $version,
            ),
        ];
    }

    /**
     * The job the screen should be watching, without being told which
     *
     * The page polls "is anything happening?", not "is 8.4 happening?" — which
     * matters more than it sounds: an admin who reloads the page, or a second
     * admin who opens it, still sees the install that is running. Tying the
     * poll to a version remembered in one browser tab would lose it the moment
     * that tab went away, and the job would finish with nobody being told.
     *
     * One `systemctl list-units` call answers it for every version at once.
     */
    public function findVersion(Executor $executor): string
    {
        if (!$this->isSupportedHere($executor)) {
            return '';
        }

        $result = $executor->exec([
            $executor->path(self::SYSTEMCTL),
            'list-units',
            '--all',
            '--plain',
            '--no-legend',
            '--no-pager',
            'phpcp-php-*.service',
        ], timeout: 15);

        $best = '';

        foreach ($result->lines() as $line) {
            $fields = preg_split('/\s+/', trim($line)) ?: [];
            $unit = $fields[0] ?? '';

            if (preg_match('/^phpcp-php-(\d+)-(\d{1,2})\.service$/', $unit, $m) !== 1) {
                continue;
            }

            $version = $m[1] . '.' . $m[2];
            $active = $fields[2] ?? '';

            // A job still running always wins · otherwise the last one listed
            // stands, which is the only finished result there is anything to say about
            if (in_array($active, ['activating', 'active'], true)) {
                return $version;
            }

            $best = $version;
        }

        return $best;
    }

    /**
     * What that job is doing, or did
     *
     * States, in the words the screen uses:
     *   idle     no job has run for this version since the machine booted
     *   running  apt is working right now
     *   done     finished, exit code 0
     *   failed   finished with an error, or systemd could not run it at all
     *
     * @param string $version empty = whichever job systemd currently has, if any
     * @return array{version:string,state:string,action:string,exit_code:int,log:string}
     */
    public function status(Executor $executor, string $version = ''): array
    {
        $idle = ['version' => $version, 'state' => 'idle', 'action' => '', 'exit_code' => 0, 'log' => ''];

        if (!$this->isSupportedHere($executor)) {
            return $idle;
        }

        if ($version === '') {
            $version = $this->findVersion($executor);

            if ($version === '') {
                return $idle;
            }

            $idle['version'] = $version;
        }

        $unit = $this->unit($version);

        $show = $executor->exec([
            $executor->path(self::SYSTEMCTL),
            'show',
            $unit,
            '--property=LoadState',
            '--property=ActiveState',
            '--property=Result',
            '--property=ExecMainStatus',
            '--property=Description',
        ], timeout: 15);

        $values = $show->keyValues();

        /*
         * **`LoadState`, not an empty `Result`, is what says "no such job"**
         *
         * `systemctl show` answers for a unit that has never existed as
         * happily as for one that has, filling in defaults —
         * `ActiveState=inactive`, `Result=success`. Reading that as a result
         * would report a successful install of a version nobody ever asked
         * for. `not-found` is the one field that tells the two apart.
         */
        if (($values['LoadState'] ?? 'not-found') === 'not-found' || ($values['ActiveState'] ?? '') === '') {
            return $idle;
        }

        $active = $values['ActiveState'] ?? '';
        $result = $values['Result'] ?? 'success';
        $exitCode = (int) ($values['ExecMainStatus'] ?? 0);

        /*
         * A `Type=oneshot` unit with `RemainAfterExit=yes` reports:
         *   activating  while ExecStart is still running
         *   active      finished, and stays that way to be read back
         *   failed      systemd could not run it, or it exited non-zero
         */
        $state = match (true) {
            in_array($active, ['activating', 'reloading', 'deactivating'], true) => 'running',
            $active === 'failed' || $result !== 'success' || $exitCode !== 0 => 'failed',
            default => 'done',
        };

        return [
            'version' => $version,
            'state' => $state,
            // The description was written when the job started, so this
            // survives the panel restarting halfway through — the screen can
            // still say which of install/remove it is watching
            'action' => str_contains($values['Description'] ?? '', 'remove') ? 'remove' : 'install',
            'exit_code' => $exitCode,
            'log' => $this->log($executor, $unit),
        ];
    }

    /** Forget a finished job, so the same version can be worked on again */
    public function clear(Executor $executor, string $unit): void
    {
        // Both are expected to fail when there is nothing there — that is the
        // ordinary case, not an error worth reporting to anybody
        $executor->exec([$executor->path(self::SYSTEMCTL), 'stop', $unit], timeout: 20);
        $executor->exec([$executor->path(self::SYSTEMCTL), 'reset-failed', $unit], timeout: 15);
    }

    /**
     * The tail of what the job printed
     *
     * From the journal, because that is where systemd puts a transient unit's
     * output — writing to a file of our own would mean a second copy to clean
     * up, and log rotation to think about, for something already solved.
     */
    private function log(Executor $executor, string $unit): string
    {
        if (!$executor->exists($executor->path(self::JOURNALCTL))) {
            return '';
        }

        $result = $executor->exec([
            $executor->path(self::JOURNALCTL),
            '-u', $unit,
            '-n', (string) self::LOG_LINES,
            '--no-pager',
            // Message text only · the timestamp and hostname on every line
            // push the part that matters off the side of a narrow screen
            '-o', 'cat',
        ], timeout: 20);

        return $result->ok() ? trim($result->stdout) : '';
    }

    /**
     * The exact package names installed for a version, read from dpkg
     *
     * Used when removing: the list the panel *would* install is not necessarily
     * what is actually there — an admin may have added `php8.2-redis` by hand,
     * and leaving it behind would keep the version half-present, with
     * `/etc/php/8.2` still on disk and the panel still listing it as installed.
     *
     * **Names are read back and passed individually — no glob ever reaches
     * apt.** `apt-get remove 'php8.2-*'` expands inside apt itself, and a
     * pattern is a much less careful thing to hand a package manager running
     * as root than a list of names that were just confirmed to exist.
     *
     * @return list<string>
     */
    public function installedPackages(Executor $executor, string $version): array
    {
        if (!PhpSupport::isValid($version)) {
            return [];
        }

        $result = $executor->exec([
            $executor->path('/usr/bin/dpkg-query'),
            '-W',
            '-f=${Package} ${Status}\n',
            'php' . $version . '-*',
        ], timeout: 30);

        $packages = [];

        foreach ($result->lines() as $line) {
            // "php8.2-cli install ok installed" — anything else (deinstall,
            // config-files) is a package already gone, and asking apt to remove
            // it again makes the command fail as a whole
            if (preg_match('/^(php' . preg_quote($version, '/') . '-[a-z0-9.+-]+) install ok installed$/', trim($line), $m) === 1) {
                $packages[] = $m[1];
            }
        }

        return $packages;
    }
}
