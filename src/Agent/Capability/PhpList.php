<?php

declare(strict_types=1);

namespace Phpcp\Agent\Capability;

use Phpcp\Agent\Context;
use Phpcp\Agent\Executor\Executor;
use Phpcp\Domain\PhpSupport;
use Phpcp\Domain\ServiceCatalog;
use Phpcp\Domain\SiteRepository;
use Phpcp\Driver\Php\FpmManager;
use Phpcp\Driver\Template;

/**
 * Every PHP version this machine has or could have — PROMPT.md
 *
 * ## Three sources, one list
 *
 * Installed versions are scanned from `/etc/php`, installable ones come from
 * apt, and {@see PhpSupport::known()} fills in as a catalogue when apt cannot
 * be asked. The union is what the page shows, each row carrying the `state`
 * that decides which button (if any) it gets.
 *
 * This used to walk a constant of seven version numbers and report only the
 * ones already installed. Two things were wrong with that: an admin had no way
 * to see what they *could* install without leaving for a terminal, and a
 * version PHP had released but the constant had never heard of was invisible
 * even when it was installed and running.
 *
 * Lives in the Hosting category because it answers "which versions can a
 * website use", while starting and stopping the PHP-FPM process belongs to the
 * Services page, per the Important UX Rule — no button here controls the
 * process, and `fpm_status` is here to be read, not to build a command from.
 */
final class PhpList implements \Phpcp\Agent\Capability
{
    public static function name(): string
    {
        return 'php.list';
    }

    public function permission(): string
    {
        return 'php.view';
    }

    public function isMutating(): bool
    {
        return false;
    }

    public function summary(): string
    {
        return 'Read the list of installed PHP versions';
    }

    public function validate(array $args): array
    {
        return [];
    }

    public function run(array $args, Executor $executor, Context $context): array
    {
        $fpm = new FpmManager(new Template($context->config->paths->templates()));
        $usage = (new SiteRepository($context->db))->countByPhpVersion();

        $installed = $fpm->installedVersions($executor);
        $available = $fpm->availableVersions($executor);

        // apt could not be asked (no apt, or an index that has never been
        // updated) — show the catalogue rather than a page listing only what is
        // already installed, which would read as "there is nothing else"
        $catalogue = $available === [] ? PhpSupport::known() : $available;

        $versions = [];

        foreach (PhpSupport::sortNewestFirst(array_values(array_unique([...$installed, ...$catalogue]))) as $version) {
            $isInstalled = in_array($version, $installed, true);
            $versions[$version] = $isInstalled
                ? $this->installedRow($executor, $fpm, $version, $usage[$version] ?? 0)
                : $this->notInstalledRow($version, in_array($version, $available, true), $available !== []);
        }

        $default = PhpSupport::preferred($installed);

        foreach ($versions as $version => $row) {
            $versions[$version]['is_default'] = $version === $default;
        }

        return [
            'versions' => $versions,
            'installed_count' => count($installed),
            'default' => $default,
            // False = the page must not offer an Install button at all · an
            // offer that cannot work is worse than no offer, because the
            // failure arrives minutes later and looks like the panel is broken
            'can_install' => $available !== [],
            'panel_version' => PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function installedRow(Executor $executor, FpmManager $fpm, string $version, int $sites): array
    {
        $unit = ServiceCatalog::fpmUnit($version);
        $service = ServiceProbe::read($executor, $unit);
        $extensions = $fpm->extensions($executor, $version);

        return [
            'version' => $version,
            'unit' => $unit,
            'state' => 'installed',
            'installed' => true,
            'fpm_status' => $service['status'],
            'fpm_running' => $service['running'],
            'fpm_memory' => $service['memory_bytes'],
            'sites' => $sites,
            'extensions' => $extensions,
            'extension_count' => count($extensions),
            'supported' => PhpSupport::isSupported($version),
            'security_ends' => PhpSupport::endsAt($version),
            'cli_binary' => '/usr/bin/php' . $version,
            ...$this->removability($version, $sites),
        ];
    }

    /**
     * @param bool $inRepository whether apt genuinely offers it
     * @param bool $aptAnswered  false = apt could not be asked, so "not offered" is unknown rather than false
     * @return array<string,mixed>
     */
    private function notInstalledRow(string $version, bool $inRepository, bool $aptAnswered): array
    {
        return [
            'version' => $version,
            'unit' => ServiceCatalog::fpmUnit($version),
            'state' => $inRepository || !$aptAnswered ? 'available' : 'unavailable',
            'installed' => false,
            // Nothing is running, so these are all zero rather than absent —
            // one row shape, or the table would have to guess what a missing
            // key means (see the rule about `data-template` having no conditionals)
            'fpm_status' => 'not_installed',
            'fpm_running' => false,
            'fpm_memory' => 0,
            'sites' => 0,
            'extensions' => [],
            'extension_count' => 0,
            'supported' => PhpSupport::isSupported($version),
            'security_ends' => PhpSupport::endsAt($version),
            'cli_binary' => '',
            'removable' => false,
            'remove_blocked_reason' => '',
        ];
    }

    /**
     * Whether this version can be removed, and if not, why
     *
     * The reason travels with the row so the screen can say it outright
     * instead of offering a button that fails. Every one of these is also
     * re-checked inside {@see PhpRemove} — this half is courtesy, that half is
     * the gate.
     *
     * @return array{removable:bool,remove_blocked_reason:string}
     */
    private function removability(string $version, int $sites): array
    {
        if ($version === PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION) {
            return [
                'removable' => false,
                // The panel runs on this one · removing it takes the panel down
                // mid-request, and there is no screen left to report it on
                'remove_blocked_reason' => 'The control panel itself runs on this version',
            ];
        }

        if ($sites > 0) {
            return [
                'removable' => false,
                'remove_blocked_reason' => sprintf('%d website(s) still use it', $sites),
            ];
        }

        return ['removable' => true, 'remove_blocked_reason' => ''];
    }
}
