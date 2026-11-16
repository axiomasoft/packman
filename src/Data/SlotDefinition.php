<?php

declare(strict_types=1);

namespace Packman\Plugins\Data;

use Packman\Attributes\Api;
use Packman\Plugins\Enums\Cardinality;
use Packman\Plugins\Enums\SlotKind;

/**
 * A slot contract, the plugin that owns it, its behavioral kind, and its cardinality.
 */
#[Api(since: '1.0')]
final readonly class SlotDefinition
{
    /** @param class-string $contract */
    public function __construct(
        public string $contract,
        public string $pluginId,
        public SlotKind $kind,
        public Cardinality $cardinality = Cardinality::Many,
    ) {}
}
