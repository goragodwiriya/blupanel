<?php

declare(strict_types=1);

namespace Phpcp\Agent\Capability;

use Phpcp\Agent\Capability;
use Phpcp\Agent\Context;
use Phpcp\Agent\Executor\Executor;

/**
 * The machine's health numbers — CPU, RAM, Disk, Network, Uptime, Load
 *
 * Reads straight from /proc, forking no process at all, so the cost is nearly
 * zero — necessary because the dashboard page calls this every 2 seconds
 * (ARCHITECTURE §9.4).
 *
 * /proc is read for real even in sandbox mode — so the graph on screen is always the machine's real value.
 */
final class SystemMetrics implements Capability
{
    /** The time between two CPU samples, used to compute the percentage */
    private const CPU_SAMPLE_US = 120_000;

    public static function name(): string
    {
        return 'system.metrics';
    }

    public function permission(): string
    {
        return 'dashboard.view';
    }

    public function isMutating(): bool
    {
        return false;
    }

    public function summary(): string
    {
        return 'Read server resource usage statistics';
    }

    public function validate(array $args): array
    {
        // Accepts no arguments at all — discards anything sent
        return [];
    }

    public function run(array $args, Executor $executor, Context $context): array
    {
        $cpu = $this->cpu($executor);
        $memory = $this->memory($executor);
        $disk = $this->disk($executor);
        $network = $this->network($executor);

        return [
            'cpu' => $cpu,
            'memory' => $memory,
            'disk' => $disk,
            'network' => $network,
            'load' => $this->load($executor),
            'uptime' => $this->uptime($executor),
            'hostname' => php_uname('n'),
            'kernel' => php_uname('r'),
            'cores' => $this->coreCount($executor),
            'sampled_at' => time(),
        ];
    }

    /** @return array{percent:float,cores:int} */
    private function cpu(Executor $executor): array
    {
        $first = $this->cpuTotals($executor);
        usleep(self::CPU_SAMPLE_US);
        $second = $this->cpuTotals($executor);

        $totalDelta = $second['total'] - $first['total'];
        $idleDelta = $second['idle'] - $first['idle'];

        $percent = $totalDelta > 0
            ? round((1 - $idleDelta / $totalDelta) * 100, 1)
            : 0.0;

        return [
            'percent' => max(0.0, min(100.0, $percent)),
            'cores' => $this->coreCount($executor),
        ];
    }

    /** @return array{total:int,idle:int} */
    private function cpuTotals(Executor $executor): array
    {
        foreach (preg_split('/\R/', $executor->readFile('/proc/stat')) ?: [] as $line) {
            if (!str_starts_with($line, 'cpu ')) {
                continue;
            }

            $fields = array_map(intval(...), preg_split('/\s+/', trim($line)) ?: []);
            array_shift($fields); // Discards the "cpu" label

            // Fields: user nice system idle iowait irq softirq steal guest guest_nice
            $idle = ($fields[3] ?? 0) + ($fields[4] ?? 0);

            return ['total' => array_sum($fields), 'idle' => $idle];
        }

        return ['total' => 0, 'idle' => 0];
    }

    private function coreCount(Executor $executor): int
    {
        $count = 0;
        foreach (preg_split('/\R/', $executor->readFile('/proc/cpuinfo')) ?: [] as $line) {
            if (str_starts_with($line, 'processor')) {
                $count++;
            }
        }

        return max(1, $count);
    }

    /** @return array{total:int,used:int,free:int,percent:float,swap_total:int,swap_used:int,swap_percent:float} */
    private function memory(Executor $executor): array
    {
        $values = [];
        foreach (preg_split('/\R/', $executor->readFile('/proc/meminfo')) ?: [] as $line) {
            if (preg_match('/^(\w+):\s+(\d+)/', $line, $m) === 1) {
                $values[$m[1]] = (int) $m[2] * 1024;    // kB → bytes
            }
        }

        $total = $values['MemTotal'] ?? 0;
        // Uses MemAvailable as the kernel recommends, not MemFree, which excludes reclaimable cache
        $available = $values['MemAvailable'] ?? ($values['MemFree'] ?? 0);
        $used = max(0, $total - $available);

        $swapTotal = $values['SwapTotal'] ?? 0;
        $swapFree = $values['SwapFree'] ?? 0;
        $swapUsed = max(0, $swapTotal - $swapFree);

        return [
            'total' => $total,
            'used' => $used,
            'free' => $available,
            'percent' => $total > 0 ? round($used / $total * 100, 1) : 0.0,
            'swap_total' => $swapTotal,
            'swap_used' => $swapUsed,
            /*
             * The percentage is computed right here, not left for the screen to
             * divide itself — a machine with no swap at all has `swap_total = 0`,
             * and dividing by zero in a template produces NaN, with the meter bar
             * silently rendering wrong · that kind of machine genuinely exists and
             * is common (nearly every container).
             */
            'swap_percent' => $swapTotal > 0 ? round($swapUsed / $swapTotal * 100, 1) : 0.0,
        ];
    }

    /** @return array{total:int,used:int,free:int,percent:float} */
    private function disk(Executor $executor): array
    {
        $space = $executor->diskSpace('/');
        $used = max(0, $space['total'] - $space['free']);

        return [
            'total' => $space['total'],
            'used' => $used,
            'free' => $space['free'],
            'percent' => $space['total'] > 0 ? round($used / $space['total'] * 100, 1) : 0.0,
        ];
    }

    /** @return array{rx_bytes:int,tx_bytes:int,interfaces:int} */
    private function network(Executor $executor): array
    {
        $rx = 0;
        $tx = 0;
        $interfaces = 0;

        foreach (preg_split('/\R/', $executor->readFile('/proc/net/dev')) ?: [] as $line) {
            if (!str_contains($line, ':')) {
                continue;
            }

            [$name, $rest] = explode(':', $line, 2);
            $name = trim($name);

            // Skips loopback and virtual interfaces, so the numbers reflect real traffic
            if ($name === 'lo' || str_starts_with($name, 'veth') || str_starts_with($name, 'docker')) {
                continue;
            }

            $fields = array_values(array_filter(preg_split('/\s+/', trim($rest)) ?: [], 'strlen'));
            if (count($fields) < 9) {
                continue;
            }

            $rx += (int) $fields[0];
            $tx += (int) $fields[8];
            $interfaces++;
        }

        return ['rx_bytes' => $rx, 'tx_bytes' => $tx, 'interfaces' => $interfaces];
    }

    /** @return array{1:float,5:float,15:float} */
    private function load(Executor $executor): array
    {
        $parts = preg_split('/\s+/', trim($executor->readFile('/proc/loadavg'))) ?: [];

        return [
            1 => (float) ($parts[0] ?? 0),
            5 => (float) ($parts[1] ?? 0),
            15 => (float) ($parts[2] ?? 0),
        ];
    }

    /** @return array{seconds:int,boot_at:int} */
    private function uptime(Executor $executor): array
    {
        $parts = preg_split('/\s+/', trim($executor->readFile('/proc/uptime'))) ?: [];
        $seconds = (int) (float) ($parts[0] ?? 0);

        return ['seconds' => $seconds, 'boot_at' => time() - $seconds];
    }
}
