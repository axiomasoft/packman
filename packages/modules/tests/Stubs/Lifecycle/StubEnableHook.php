<?php

declare(strict_types=1);

namespace Packman\Modules\Tests\Stubs\Lifecycle;

use Packman\Modules\Contracts\EnableHook;
use Packman\Modules\Data\ModuleRuntimeContext;

/** Idempotent (AC-7): claiming the same setting reference twice is a no-op update. */
final class StubEnableHook implements EnableHook
{
    public function __invoke(ModuleRuntimeContext $ctx): void
    {
        $ctx->records->claim($ctx->module, 'setting', 'greeting', ['value' => 'hello']);
    }
}
