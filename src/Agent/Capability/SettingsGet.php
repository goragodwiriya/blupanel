<?php

declare(strict_types=1);

namespace Phpcp\Agent\Capability;

use Phpcp\Agent\Capability;
use Phpcp\Agent\Context;
use Phpcp\Agent\Executor\Executor;
use Phpcp\Domain\SettingsRepository;
use Phpcp\Driver\PanelCertificate;
use Phpcp\Driver\Mail\MailManager;
use Phpcp\Driver\Template;

/**
 * Reads settings that can be edited from the web page, along with mail's real
 * status on the machine
 *
 * Secret values are always masked before being sent out — a bot token that
 * leaked into the HTML would let anyone send messages as the system, and it would
 * sit in the browser's cache for a long time afterward.
 */
final class SettingsGet implements Capability
{
    public static function name(): string
    {
        return 'settings.get';
    }

    public function permission(): string
    {
        return 'settings.view';
    }

    public function isMutating(): bool
    {
        return false;
    }

    public function summary(): string
    {
        return 'Read system settings';
    }

    public function validate(array $args): array
    {
        return [];
    }

    public function run(array $args, Executor $executor, Context $context): array
    {
        $settings = new SettingsRepository($context->db);
        $mail = new MailManager(new Template($context->config->paths->templates()));

        /*
         * The management certificate's status is read from the **real file**, not
         * from a saved value — the two can diverge if someone swaps the file by
         * hand, and that's exactly the moment an admin needs the answer most.
         */
        $panelCert = (new PanelCertificate())->status(
            $executor,
            $settings->get(\Phpcp\Agent\Capability\PanelCertSet::SETTING),
        );

        return [
            'values' => SettingsRepository::mask($settings->all()),
            'mail_status' => $mail->status($executor),
            'panel_cert' => $panelCert,
        ];
    }
}
