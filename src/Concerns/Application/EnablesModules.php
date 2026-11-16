<?php

declare(strict_types=1);

namespace Packman\Testkit\Concerns\Application;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Packman\Attributes\Api;

/** Configures enabled Packman module IDs inside Orchestra Testbench application configs. */
#[Api(since: '1.0')]
trait EnablesModules
{
    /** @param list<string> $moduleIds */
    protected function enablePackmanModules(array $moduleIds, string $configKey = 'packman-modules.enabled'): void
    {
        resolve(ConfigRepository::class)->set($configKey, array_values(array_unique($moduleIds)));
    }
}
