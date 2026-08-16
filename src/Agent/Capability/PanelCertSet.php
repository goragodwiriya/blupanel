<?php

declare(strict_types=1);

namespace Phpcp\Agent\Capability;

use Phpcp\Agent\Capability;
use Phpcp\Agent\Context;
use Phpcp\Agent\Executor\Executor;
use Phpcp\Domain\SettingsRepository;
use Phpcp\Driver\ConfigTransaction;
use Phpcp\Driver\PanelCertificate;
use Phpcp\Driver\RollbackGuard;
use Phpcp\Support\Validator;

/**
 * Switches **the panel's own** certificate to a real one, or back to a self-signed one
 *
 * ## Why this needs to exist, when the file could just be edited by hand
 *
 * The only way used to be sshing in and swapping files by hand — the result
 * was almost nobody ever did it, and admins just clicked through the
 * certificate warning every single day instead · **that trains people to
 * ignore a warning that will one day be real** — the actual cost of that
 * limitation isn't inconvenience, it's turning the browser's single most
 * important warning signal into something everyone just dismisses.
 *
 * ## Why this has to be reversible
 *
 * This is a command that **can cut off its own access**, exactly like
 * firewall rules and SSH settings can · a bad certificate makes the browser
 * refuse the connection entirely, and an admin then has no way left to fix
 * it through the web page at all · so it arms a `RollbackGuard` exactly the
 * same way — if nothing confirms within the given time, the system restores
 * the previous certificate on its own.
 *
 * A path back that doesn't depend on the web page at all always exists too:
 * `phpcp panel:cert --self-signed`
 *
 * ## Why the order matters
 *
 * Save the current state → check the key pair matches and its expiry →
 * write the files → **let Apache's own validator decide** → a graceful
 * `reload` (never a restart, because the request currently being answered
 * is the one from whoever just clicked the button) → arm the rollback timer.
 */
final class PanelCertSet implements Capability
{
    /** The key that remembers which domain's certificate the panel is currently bound to — empty = self-signed */
    public const SETTING = 'panel.cert_domain';

    public static function name(): string
    {
        return 'panel.cert_set';
    }

    /** A machine-level setting — affects every admin's access, not any one website's */
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
        return "Switch the panel's own certificate";
    }

    public function validate(array $args): array
    {
        $domain = trim((string) ($args['domain'] ?? ''));

        return [
            // Empty = switch back to self-signed, which must always remain a way back
            'domain' => $domain === '' ? '' : Validator::domain($domain),
            // 0 = never arm a rollback timer (used from the command line) · a negative value also counts as 0
            'window' => isset($args['window']) ? max(0, (int) $args['window']) : RollbackGuard::DEFAULT_WINDOW,
        ];
    }

    public function run(array $args, Executor $executor, Context $context): array
    {
        $panel = new PanelCertificate();
        $domain = (string) $args['domain'];

        $previous = [
            PanelCertificate::CERT => $this->contents($executor, PanelCertificate::CERT),
            PanelCertificate::KEY => $this->contents($executor, PanelCertificate::KEY),
        ];

        [$certPath, $keyPath] = $domain === ''
            ? [PanelCertificate::SELF_SIGNED_CERT, PanelCertificate::SELF_SIGNED_KEY]
            : array_values($panel::sourcePaths($domain));

        /*
         * The self-signed certificate might not be backed up yet (a machine
         * installed before this feature existed) — save whatever's in use
         * right now before switching, so the way back genuinely exists, not
         * just on paper.
         */
        if ($domain !== '' && !$executor->exists($executor->path(PanelCertificate::SELF_SIGNED_CERT))) {
            $this->keepSelfSigned($executor, $previous);
        }

        $files = $panel->read($executor, $certPath, $keyPath);

        $transaction = new ConfigTransaction($executor);
        $transaction->write(PanelCertificate::CERT, $files['cert'], 0644);
        // The private key must be readable by root only — Apache already reads it as root at start time
        $transaction->write(PanelCertificate::KEY, $files['key'], 0600);

        $transaction->commit(fn (): array => $panel->checkConfig($executor, $context->config));

        (new SettingsRepository($context->db))->save([self::SETTING => $domain]);

        $this->installHook($executor, $context, $domain !== '');

        // graceful — the request currently being answered is the one from whoever just clicked the button; a restart would cut it off
        $panel->reload($executor);

        /*
         * **`window = 0` means never arm a rollback timer at all, not "arm it
         * for zero seconds"**
         *
         * `RollbackGuard::arm()` always clamps the value into the 30–900
         * second range — passing 0 straight through would get 30 seconds,
         * and a command run from the command line would then revert itself
         * within half a minute with the operator having no idea where they
         * were supposed to go confirm it · someone running from the CLI is
         * already on the machine and can fix it back immediately, so this
         * mechanism exists only for someone working through the web page.
         */
        $rollbackId = 0;

        if ($args['window'] > 0) {
            $rollbackId = (new RollbackGuard($context->db))->arm(
                action: self::name(),
                description: $domain === ''
                    ? "Revert the panel's certificate back to self-signed"
                    : sprintf("Use %s's certificate for the panel", $domain),
                files: $previous,
                reloadUnits: [PanelCertificate::UNIT],
                window: $args['window'],
                actorId: $context->actor->userId,
            );
        }

        $confirm = $rollbackId > 0
            ? ', then confirm within the time given, or the system will restore the previous certificate automatically'
            : '';

        return [
            'domain' => $domain,
            'rollback_id' => $rollbackId,
            'window' => $args['window'],
            'message' => $domain === ''
                ? 'Reverted to the self-signed certificate — reopen the panel to confirm it still lets you in'
                    . $confirm
                : sprintf(
                    "The panel is now using %s's certificate — open the panel in a new tab to confirm it genuinely still lets you in%s",
                    $domain,
                    $confirm,
                ),
        ];
    }

    /** Saves the certificate currently in use as a way back — only called when no backup exists yet */
    private function keepSelfSigned(Executor $executor, array $current): void
    {
        foreach ([
            PanelCertificate::SELF_SIGNED_CERT => [$current[PanelCertificate::CERT], 0644],
            PanelCertificate::SELF_SIGNED_KEY => [$current[PanelCertificate::KEY], 0600],
        ] as $path => [$content, $mode]) {
            if ($content === null) {
                continue;
            }

            $executor->writeFile($executor->path($path), $content, $mode);
        }
    }

    /**
     * Installs (or removes) the hook certbot calls after a renewal
     *
     * **Missing this hook means the certificate expires in 90 days and the
     * warning is right back**, even though certbot's own copy on disk is
     * completely correct — a symptom nobody would ever trace back to a
     * button clicked three months earlier.
     */
    private function installHook(Executor $executor, Context $context, bool $wanted): void
    {
        $path = $executor->path(PanelCertificate::HOOK);

        if (!$wanted) {
            if ($executor->exists($path)) {
                $executor->removePath($path);
            }

            return;
        }

        $executor->makeDirectory($executor->path(dirname(PanelCertificate::HOOK)), 0755);
        $executor->writeFile(
            $path,
            PanelCertificate::hookScript(
                PHP_BINARY,
                rtrim($context->config->paths->root, '/') . '/bin/phpcp',
            ),
            0755,
        );
    }

    /** null = this file doesn't exist yet, which RollbackGuard treats as "delete it on restore" */
    private function contents(Executor $executor, string $path): ?string
    {
        $resolved = $executor->path($path);

        if (!$executor->exists($resolved)) {
            return null;
        }

        try {
            return $executor->readFile($resolved);
        } catch (\Throwable) {
            return null;
        }
    }
}
