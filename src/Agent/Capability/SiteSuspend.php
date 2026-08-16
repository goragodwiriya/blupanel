<?php

declare(strict_types=1);

namespace Phpcp\Agent\Capability;

/** site.suspend — see SiteSetStatus for details */
final class SiteSuspend extends SiteSetStatus
{
    public static function name(): string
    {
        return 'site.suspend';
    }

    public function summary(): string
    {
        return 'Temporarily suspend a website (files and database stay intact)';
    }

    protected function targetStatus(): string
    {
        return 'suspended';
    }
}
