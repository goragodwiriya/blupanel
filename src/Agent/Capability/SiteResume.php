<?php

declare(strict_types=1);

namespace Phpcp\Agent\Capability;

/** site.resume — see SiteSetStatus for details */
final class SiteResume extends SiteSetStatus
{
    public static function name(): string
    {
        return 'site.resume';
    }

    public function summary(): string
    {
        return 'Resume a suspended website';
    }

    protected function targetStatus(): string
    {
        return 'active';
    }
}
