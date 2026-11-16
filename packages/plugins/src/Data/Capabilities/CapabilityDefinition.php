<?php

declare(strict_types=1);

namespace Packman\Plugins\Data\Capabilities;

use Packman\Attributes\Api;
use Packman\Plugins\Enums\Contributions\Cardinality;

/**
 * A capability contract and the plugin that defines it.
 */
#[Api(since: '1.0')]
final readonly class CapabilityDefinition
{
    /** @param class-string $contract */
    public function __construct(
        public string $contract,
        public string $pluginId,
        public Cardinality $cardinality = Cardinality::RequiredSingle,
    ) {}
}
