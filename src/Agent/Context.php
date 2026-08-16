<?php

declare(strict_types=1);

namespace Phpcp\Agent;

use Phpcp\Kernel\Config;
use Phpcp\Kernel\Db;

/**
 * The environment a capability can reach, besides the Executor
 *
 * Kept small on purpose — the fewer things a capability depends on, the easier it
 * is to review and test.
 */
final readonly class Context
{
    public function __construct(
        public Actor $actor,
        public Config $config,
        public Db $db,
    ) {
    }
}
