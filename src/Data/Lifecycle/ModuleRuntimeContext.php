<?php

declare(strict_types=1);

namespace Packman\Modules\Data\Lifecycle;

use Packman\Attributes\Api;
use Packman\Context\Contracts\ContextScopeInterface;
use Packman\Modules\Contracts\Lifecycle\DisableHook;
use Packman\Modules\Contracts\Lifecycle\EnableHook;
use Packman\Modules\Contracts\Lifecycle\PurgeHook;
use Packman\Modules\Contracts\Lifecycle\RecordsRegistrar;

/**
 * Payload passed to {@see EnableHook}/{@see DisableHook}/
 * {@see PurgeHook}: which module, for which tenant, with the
 * registrar the hook must use to claim/release anything it creates.
 */
#[Api(since: '1.0')]
final readonly class ModuleRuntimeContext
{
    public function __construct(
        public string $module,
        public ContextScopeInterface $tenant,
        public RecordsRegistrar $records,
    ) {}
}
