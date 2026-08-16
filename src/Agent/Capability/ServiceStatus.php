<?php

declare(strict_types=1);

namespace Phpcp\Agent\Capability;

use Phpcp\Agent\Capability;
use Phpcp\Agent\Context;
use Phpcp\Agent\Executor\Executor;
use Phpcp\Agent\SelfProtection;
use Phpcp\Agent\ValidationError;
use Phpcp\Domain\ServiceCatalog;
use Phpcp\Support\Validator;

/**
 * Reads service status from systemd — read-only
 *
 * Accepts a list so a page that needs to show several services can do it in one round trip.
 *
 * Security: unit names come only from ServiceCatalog's allowlist. Even if an
 * attacker sent "apache2; rm -rf /", in_array() rejects it before it ever reaches
 * the executor — and even if it somehow got through, an argv array still never
 * passes through a shell. Two structural layers of defense.
 */
final class ServiceStatus implements Capability
{
    private const MAX_UNITS = 32;

    public static function name(): string
    {
        return 'service.status';
    }

    public function permission(): string
    {
        return 'service.view';
    }

    public function isMutating(): bool
    {
        return false;
    }

    public function summary(): string
    {
        return 'Read system service status';
    }

    public function validate(array $args): array
    {
        // Not specified = get everything manageable
        $requested = isset($args['services'])
            ? Validator::requireStringList($args, 'services', maxItems: self::MAX_UNITS, maxLength: 64)
            : ServiceCatalog::units();

        $clean = [];
        foreach ($requested as $unit) {
            $unit = strtolower($unit);

            SelfProtection::assertUnit($unit);

            if (!ServiceCatalog::isAllowed($unit)) {
                throw new ValidationError("Unknown service: {$unit}");
            }

            $clean[] = $unit;
        }

        if ($clean === []) {
            throw new ValidationError('At least one service must be specified');
        }

        return ['services' => $clean];
    }

    public function run(array $args, Executor $executor, Context $context): array
    {
        $result = [];

        foreach ($args['services'] as $unit) {
            $result[$unit] = ServiceProbe::read($executor, $unit);
        }

        return ['services' => $result];
    }
}
