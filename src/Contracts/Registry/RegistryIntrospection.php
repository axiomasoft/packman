<?php

declare(strict_types=1);

namespace Packman\Modules\Contracts\Registry;

use Packman\Attributes\Api;
use Packman\Context\Contracts\ContextScopeInterface;
use Packman\Modules\Data\Registry\ModuleExplanation;
use Packman\Modules\Data\Registry\RegistryMap;

/**
 * Read-only registry diagnostics over compiled module bytes and current
 * activation state. No state writes, provider reflection or network access.
 */
#[Api(since: '1.0')]
interface RegistryIntrospection
{
    public function extensionPoints(): RegistryMap;

    public function why(string $module, ?ContextScopeInterface $context): ModuleExplanation;
}
