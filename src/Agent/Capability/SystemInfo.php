<?php

declare(strict_types=1);

namespace Phpcp\Agent\Capability;

use Phpcp\Agent\Capability;
use Phpcp\Agent\Context;
use Phpcp\Agent\Executor\Executor;
use Phpcp\Domain\ServiceCatalog;

/**
 * Basic machine information that rarely changes — read-only
 *
 * Kept separate from system.metrics on purpose: metrics gets called every 2
 * seconds so it has to be as light as possible, while this set of data is called
 * once when the page opens and can afford to do more work.
 */
final class SystemInfo implements Capability
{
    public static function name(): string
    {
        return 'system.info';
    }

    public function permission(): string
    {
        return 'server.view';
    }

    public function isMutating(): bool
    {
        return false;
    }

    public function summary(): string
    {
        return 'Read basic server information';
    }

    public function validate(array $args): array
    {
        return [];
    }

    public function run(array $args, Executor $executor, Context $context): array
    {
        return [
            'hostname' => php_uname('n'),
            'os' => $this->osName($executor),
            'kernel' => php_uname('r'),
            'arch' => php_uname('m'),
            'cpu_model' => $this->cpuModel($executor),
            'cpu_cores' => $this->cores($executor),
            'timezone' => date_default_timezone_get(),
            'time' => time(),
            'php_panel' => PHP_VERSION,
            'php_installed' => $this->installedPhpVersions($executor),
            'filesystems' => $this->filesystems($executor),
            'boot_at' => $this->bootTime($executor),
        ];
    }

    private function osName(Executor $executor): string
    {
        if (!$executor->exists('/etc/os-release')) {
            return php_uname('s');
        }

        foreach (preg_split('/\R/', $executor->readFile('/etc/os-release')) ?: [] as $line) {
            if (preg_match('/^PRETTY_NAME="?([^"]+)"?/', $line, $m) === 1) {
                return $m[1];
            }
        }

        return php_uname('s');
    }

    private function cpuModel(Executor $executor): string
    {
        foreach (preg_split('/\R/', $executor->readFile('/proc/cpuinfo')) ?: [] as $line) {
            if (str_starts_with($line, 'model name')) {
                return trim(explode(':', $line, 2)[1] ?? '');
            }
        }

        return 'Unknown';
    }

    private function cores(Executor $executor): int
    {
        $count = 0;
        foreach (preg_split('/\R/', $executor->readFile('/proc/cpuinfo')) ?: [] as $line) {
            if (str_starts_with($line, 'processor')) {
                $count++;
            }
        }

        return max(1, $count);
    }

    /**
     * Checks which PHP versions the machine has installed, from each version's
     * config directory (full PHP management lives in Phase 2 — this just reports
     * what's there)
     *
     * @return list<string>
     */
    private function installedPhpVersions(Executor $executor): array
    {
        $found = [];

        foreach (ServiceCatalog::PHP_VERSIONS as $version) {
            if ($executor->exists('/etc/php/' . $version)) {
                $found[] = $version;
            }
        }

        return $found;
    }

    /**
     * Space for each mount point of interest — skips every virtual filesystem
     *
     * @return list<array{mount:string,total:int,used:int,free:int,percent:float}>
     */
    private function filesystems(Executor $executor): array
    {
        $skipTypes = ['proc', 'sysfs', 'devtmpfs', 'devpts', 'tmpfs', 'cgroup', 'cgroup2',
            'securityfs', 'pstore', 'bpf', 'debugfs', 'tracefs', 'configfs', 'fusectl',
            'squashfs', 'ramfs', 'autofs', 'mqueue', 'hugetlbfs', 'nsfs', 'binfmt_misc'];

        $out = [];
        $seen = [];

        foreach (preg_split('/\R/', $executor->readFile('/proc/mounts')) ?: [] as $line) {
            $parts = preg_split('/\s+/', $line) ?: [];
            if (count($parts) < 3) {
                continue;
            }

            [$device, $mount, $type] = $parts;

            if (in_array($type, $skipTypes, true) || !str_starts_with($device, '/')) {
                continue;
            }

            $mount = str_replace('\\040', ' ', $mount);
            if (isset($seen[$mount])) {
                continue;
            }
            $seen[$mount] = true;

            $space = $executor->diskSpace($mount);
            if ($space['total'] <= 0) {
                continue;
            }

            $used = max(0, $space['total'] - $space['free']);

            $out[] = [
                'mount' => $mount,
                'device' => $device,
                'type' => $type,
                'total' => $space['total'],
                'used' => $used,
                'free' => $space['free'],
                'percent' => round($used / $space['total'] * 100, 1),
            ];
        }

        usort($out, static fn (array $a, array $b): int => $b['total'] <=> $a['total']);

        return array_slice($out, 0, 8);
    }

    private function bootTime(Executor $executor): int
    {
        $parts = preg_split('/\s+/', trim($executor->readFile('/proc/uptime'))) ?: [];

        return time() - (int) (float) ($parts[0] ?? 0);
    }
}
