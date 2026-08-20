<?php

declare(strict_types=1);

namespace Phpcp\Agent\Capability;

use Phpcp\Agent\Capability;
use Phpcp\Agent\Context;
use Phpcp\Agent\Executor\Executor;
use Phpcp\Agent\ValidationError;
use Phpcp\Domain\PhpSettings;
use Phpcp\Domain\SettingsRepository;
use Phpcp\Driver\ConfigTransaction;
use Phpcp\Driver\PanelCertificate;
use Phpcp\Driver\Php\PanelPhpTuning;

/**
 * Sets **the panel's own** PHP values — its pool and its Apache body ceiling together
 *
 * ## Why the panel needs its own screen for this at all
 *
 * The panel's pool ships small on purpose: it runs `pm = static`, so
 * `memory_limit × pm.max_children` is memory the machine has to be able to hold
 * at once, and the shipped numbers assume a 1 GB VPS. On a bigger machine those
 * numbers are simply wrong, and the first thing that hits them is importing a
 * database through the phpMyAdmin the panel bridges into — a 100 MB dump is
 * refused by a 32 MB `upload_max_filesize` long before anything interesting
 * happens. Until now the only way to change that was editing
 * `templates/panel/panel-pool.conf.tpl` and reinstalling.
 *
 * ## The three limits that have to move together
 *
 * `upload_max_filesize`, `post_max_size` and Apache's `LimitRequestBody` all cap
 * the same upload, and the smallest one wins. Raising only the PHP pair leaves
 * Apache answering 413 with nothing in any PHP log to explain it, which is
 * exactly the dead end this command exists to remove — so all three are written
 * in one transaction, from one number.
 *
 * ## Why this is `settings.manage` and not `customer.manage`
 *
 * These values belong to the control panel itself, not to any customer. Getting
 * them wrong takes the panel offline for every admin at once, which puts it in
 * the same class as the web server mode and the panel's certificate.
 *
 * ## Order
 *
 * Read the files that are genuinely on disk → patch only the lines this screen
 * owns → let php-fpm's and Apache's own validators decide → graceful reload of
 * both (never a restart: the request being answered is the one from whoever just
 * clicked save) → store the values, so the installer can put them back after the
 * next update rewrites `panel.conf` from the template.
 */
final class PanelPhpSet implements Capability
{
    /** The settings keys these values are stored under */
    public const PREFIX = 'panel.php.';

    public static function name(): string
    {
        return 'panel.php_set';
    }

    public function permission(): string
    {
        return 'settings.manage';
    }

    public function isMutating(): bool
    {
        return true;
    }

    public function summary(): string
    {
        return "Set the panel's own PHP values";
    }

    /**
     * @param array<string,mixed> $args
     * @return array<string,mixed>
     */
    public function validate(array $args): array
    {
        $clean = [];

        foreach (array_keys(PhpSettings::FIELDS) as $field) {
            if (!array_key_exists($field, $args) || $args[$field] === null) {
                continue;
            }

            $clean[$field] = PhpSettings::assertValue($field, $args[$field]);
        }

        /*
         * `apply_only` = re-write the files from the values already stored,
         * changing none of them · this is what `phpcp panel:php-apply` sends
         * after an update has regenerated panel.conf from the template · the
         * screen never sends it, and it is the only way to call this with no
         * values at all
         */
        $applyOnly = ($args['apply_only'] ?? false) === true;

        if ($clean === [] && !$applyOnly) {
            throw new ValidationError('At least one PHP value to change must be specified');
        }

        return $clean + ['apply_only' => $applyOnly];
    }

    /**
     * @param array<string,mixed> $args
     * @return array<string,mixed>
     */
    public function run(array $args, Executor $executor, Context $context): array
    {
        $settings = new SettingsRepository($context->db);
        $before = PhpSettings::fromSettings($settings->all(), self::PREFIX);

        $after = $args['apply_only'] === true ? $before : PhpSettings::fromArray($args, $before);
        $after->assertConsistent();

        $poolPath = PanelPhpTuning::poolPath($context->config);
        $httpdPath = PanelPhpTuning::httpdPath($context->config);

        if (!$executor->exists($executor->path($poolPath))) {
            throw new ValidationError(
                "The panel's own pool file was not found at {$poolPath}"
                . ' — this machine runs the panel from a development server rather than its own php-fpm, so there is nothing to change',
            );
        }

        /*
         * Ownership has to be put back by hand after the write
         *
         * `writeFile()` writes a temp file and renames it, which is what makes
         * the write atomic — and which also means the result is owned by the
         * agent (root:root), not by the `root:phpcp` the installer set. Nothing
         * breaks today, since both services read these as root, but a file whose
         * ownership silently drifts from what the installer documented is the
         * kind of difference that costs an hour the day it does matter.
         */
        $ownership = [
            $poolPath => $executor->stat($executor->path($poolPath)),
            $httpdPath => $executor->stat($executor->path($httpdPath)),
        ];

        $transaction = new ConfigTransaction($executor);
        $transaction->write(
            $poolPath,
            PanelPhpTuning::applyToPool($executor->readFile($executor->path($poolPath)), $after),
            0640,
        );

        // A dev install has no panel Apache · its half is simply skipped rather
        // than failing the whole command, since the pool half is still real
        $httpdTouched = $executor->exists($executor->path($httpdPath));

        if ($httpdTouched) {
            $transaction->write(
                $httpdPath,
                PanelPhpTuning::applyToHttpd($executor->readFile($executor->path($httpdPath)), $after),
                0640,
            );
        }

        $transaction->commit(fn (): array => $this->check($executor, $context, $httpdTouched));

        foreach ($ownership as $path => $stat) {
            $this->restoreOwnership($executor, $path, $stat);
        }

        $reloaded = PanelPhpTuning::reload($executor);

        $settings->save($after->toSettings(self::PREFIX));

        $changes = $after->diff($before);

        return [
            'php' => $after->toArray(),
            'changes' => $changes,
            'reloaded' => $reloaded,
            'limit_request_body' => $after->bodyLimitMb() * 1048576,
            /*
             * Said plainly, because a reload that did not happen is the one way
             * this command can report success while nothing changed — on a
             * machine where the units are not running, the files are correct and
             * take effect at the next start, which is a different thing from "in
             * effect now" and the admin has to be able to tell them apart
             */
            'message' => $reloaded === []
                ? "Saved the panel's PHP values — no service was reloaded, so they take effect the next time the panel's php-fpm starts"
                : sprintf("Saved the panel's PHP values and reloaded %s", implode(', ', $reloaded)),
        ];
    }

    /**
     * Puts a file's uid/gid back to what it was before the atomic rewrite
     *
     * Numeric ids, not names — whatever the installer chose on this machine is
     * the right answer, and looking up a name would mean deciding that here
     * instead of preserving it.
     *
     * @param array{uid:int,gid:int}|null $stat
     */
    private function restoreOwnership(Executor $executor, string $path, ?array $stat): void
    {
        if ($stat === null || !$executor->exists($executor->path($path))) {
            return;
        }

        $executor->exec(
            [$executor->path('/usr/bin/chown'), $stat['uid'] . ':' . $stat['gid'], $executor->path($path)],
            timeout: 15,
        );
    }

    /**
     * Both validators, in the order that fails fastest
     *
     * @return array{0:bool,1:string}
     */
    private function check(Executor $executor, Context $context, bool $httpdTouched): array
    {
        [$ok, $output] = PanelPhpTuning::checkConfig($executor, $context->config);

        if (!$ok) {
            return [false, "The panel's php-fpm configuration failed validation:\n" . $output];
        }

        if (!$httpdTouched) {
            return [true, $output];
        }

        [$httpdOk, $httpdOutput] = (new PanelCertificate())->checkConfig($executor, $context->config);

        if (!$httpdOk) {
            return [false, "The panel's Apache configuration failed validation:\n" . $httpdOutput];
        }

        return [true, trim($output)];
    }
}
