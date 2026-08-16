<?php

declare (strict_types = 1);

namespace Phpcp\Agent\Capability;

use Phpcp\Agent\Capability;
use Phpcp\Agent\Context;
use Phpcp\Agent\ExecutionFailed;
use Phpcp\Agent\Executor\Executor;
use Phpcp\Agent\SelfProtection;
use Phpcp\Agent\ValidationError;
use Phpcp\Domain\ServiceCatalog;
use Phpcp\Support\BinaryPath;
use Phpcp\Support\Validator;

/**
 * The base for commands that change a service's state — start / stop / restart / reload
 *
 * The first group of capabilities in the system that genuinely "changes"
 * anything, so it goes through the full path: check permission → validate →
 * write the audit entry before acting → run → write the result.
 *
 * Security: unit names come from a fixed allowlist in ServiceCatalog and always
 * pass through SelfProtection first — a value the user sends is never assembled
 * directly into a command.
 */
abstract class ServiceAction implements Capability
{
    /** @var list<string> systemd lives at /usr/bin on Debian/Ubuntu · some systems also have it at /bin */
    private const JOURNALCTL_PATHS = ['/usr/bin/journalctl', '/bin/journalctl'];

    /** @var list<string> iproute2 — used to find who's holding a colliding port */
    private const SS_PATHS = ['/usr/bin/ss', '/bin/ss', '/usr/sbin/ss'];

    /** The verb sent to systemctl — comes from code, never from input */
    abstract protected function verb(): string;

    public function permission(): string
    {
        return 'service.control';
    }

    public function isMutating(): bool
    {
        return true;
    }

    /**
     * @param array $args
     */
    public function validate(array $args): array
    {
        $unit = strtolower(Validator::requireString($args, 'service', 64));

        // Checks the panel's own services before anything else, so the error message matches the real cause
        SelfProtection::assertUnit($unit);

        if (!ServiceCatalog::isAllowed($unit)) {
            throw new ValidationError("Unknown service: {$unit}");
        }

        return ['service' => $unit];
    }

    /**
     * @param array $args
     * @param Executor $executor
     * @param Context $context
     */
    public function run(array $args, Executor $executor, Context $context): array
    {
        $unit = $args['service'];
        $before = ServiceProbe::read($executor, $unit);

        if (!$before['installed']) {
            throw new ExecutionFailed("Service {$unit} was not found on this machine");
        }

        $result = $executor->exec(
            [$executor->path('/usr/bin/systemctl'), $this->verb(), $unit],
            timeout: 30,
        );

        if (!$result->ok()) {
            // A fallback for Docker/sysvinit if systemctl fails
            $serviceResult = $executor->exec(
                [$executor->path('/usr/sbin/service'), $unit, $this->verb()],
                timeout: 30,
            );

            if (!$serviceResult->ok()) {
                $detail = trim($result->stderr) !== '' ? ' — '.trim($result->stderr) : '';

                throw new ExecutionFailed(
                    sprintf('Failed to %s service %s%s', $this->verb(), $unit, $detail)
                    . $this->explainFailure($executor, $unit),
                    $result->exitCode,
                    $result->stderr,
                );
            }
        }

        // Reads the status back immediately, so the UI can update its card with no need to call again
        $after = ServiceProbe::read($executor, $unit);

        return [
            'service' => $unit,
            'action' => $this->verb(),
            'before' => $before['status'],
            'after' => $after['status'],
            'state' => $after,
            'message' => sprintf('%s service %s successfully', $this->actionLabel(), ServiceCatalog::label($unit))
        ];
    }

    /**
     * Finds the real cause from the journal, instead of just relaying systemd's own sentence
     *
     * The message systemd returns is *"Job for nginx.service failed because the
     * control process exited with error code. See systemctl status..."*, which
     * says nothing beyond "go look further yourself" — an admin working through
     * the web page usually has no terminal in front of them.
     *
     * The most common case by far is a port collision (starting nginx while
     * Apache is already holding 80/443), which can be fully explained: who's
     * holding what, and what to do about it.
     */
    private function explainFailure(Executor $executor, string $unit): string
    {
        $log = $this->recentLog($executor, $unit);

        if ($log === '') {
            return '';
        }

        // nginx: "bind() to 0.0.0.0:80 failed (98: Address already in use)"
        // apache: "(98)Address already in use: AH00072: make_sock: could not bind to address"
        if (preg_match('/bind\(\)? to [^\s]*?:(\d+) failed \(98/', $log, $m) === 1
            || preg_match('/\(98\)Address already in use.*?:(\d+)/', $log, $m) === 1) {
            $port = (int) $m[1];

            return sprintf(
                "\n\nCause: port %d is already in use%s\n\n"
                . 'Two web servers can\'t hold the same port — stop whichever one '
                . 'is holding it first, or if both are genuinely needed, set '
                . '`webserver` to `nginx-proxy`, which has nginx take ports '
                . '80/443 and forward to Apache at 127.0.0.1:8080',
                $port,
                $this->whoHoldsPort($executor, $port),
            );
        }

        return "\n\nDetail from the journal:\n" . $log;
    }

    /**
     * The lines that are genuinely errors from that unit's journal
     *
     * Only lines with real content are kept — the journal also includes
     * systemd's own explanatory lines (starting with ░░), which run many times
     * longer than the real cause.
     */
    private function recentLog(Executor $executor, string $unit): string
    {
        try {
            $journalctl = BinaryPath::resolve($executor, self::JOURNALCTL_PATHS, 'systemd');
        } catch (ExecutionFailed) {
            return '';
        }

        $result = $executor->exec(
            [$journalctl, '-u', $unit, '-n', '30', '--no-pager', '-o', 'cat'],
            timeout: 15,
        );

        $lines = [];
        foreach (explode("\n", $result->stdout) as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '░')) {
                continue;
            }

            $lines[] = $line;
        }

        // Keeps only the tail end, the most recent run — anything older is a past attempt that's already been dealt with
        return implode("\n", array_slice($lines, -6));
    }

    /** The name of the process holding the port — empty if it can't be found, better than guessing */
    private function whoHoldsPort(Executor $executor, int $port): string
    {
        try {
            $ss = BinaryPath::resolve($executor, self::SS_PATHS, 'iproute2');
        } catch (ExecutionFailed) {
            return '';
        }

        $result = $executor->exec([$ss, '-ltnpH'], timeout: 10);

        foreach (explode("\n", $result->stdout) as $line) {
            $fields = preg_split('/\s+/', trim($line)) ?: [];

            // Field 4 is our own local address — the peer field is always 0.0.0.0:*, so it must never be checked instead
            if (!isset($fields[3]) || !str_ends_with($fields[3], ':' . $port)) {
                continue;
            }

            if (preg_match('/users:\(\("([^"]+)"/', $line, $m) === 1) {
                return sprintf(' by %s', $m[1]);
            }
        }

        return '';
    }

    protected function actionLabel(): string
    {
        return match ($this->verb()) {
            'start' => 'Started',
            'stop' => 'Stopped',
            'restart' => 'Restarted',
            'reload' => 'Reloaded',
            default => ucfirst($this->verb()),
        };
    }
}
