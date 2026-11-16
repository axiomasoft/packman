<?php

declare(strict_types=1);

namespace Packman\Plugins\Contracts\Registry;

use Packman\Attributes\Api;
use Packman\Plugins\Data\Plugin\PluginDescription;

/** Read-only view of the compiled plugin registry for diagnostics (`packman:plugins`). */
#[Api(since: '1.0')]
interface PluginInspector
{
    /** @return list<PluginDescription> plugins in load order */
    public function describe(): array;
}
