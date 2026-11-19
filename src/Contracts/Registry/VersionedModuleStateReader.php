<?php

declare(strict_types=1);

namespace Packman\Modules\Contracts\Registry;

use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use Packman\Context\Contracts\ContextScopeInterface;

/** Allows the registry to cache a scope's enabled modules until its revision changes. */
#[Spi(kind: SpiKind::Implement, since: '0.1.1')]
interface VersionedModuleStateReader extends ModuleStateReader
{
    public function versionFor(ContextScopeInterface $ctx): int;
}
