<?php

declare(strict_types=1);

namespace Packman\Modules\Data;

use Packman\Attributes\Api;
use Packman\Context\Contracts\ContextScopeInterface;
use Packman\Modules\Contracts\RecordsRegistrar;

/**
 * Payload passed to {@see Contracts\EnableHook}/{@see Contracts\DisableHook}/
 * {@see Contracts\PurgeHook}: which module, for which tenant, with the
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
