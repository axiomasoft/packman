<?php

declare(strict_types=1);

namespace Packman\Modules\Contracts\Definition;

use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use Packman\Modules\Data\Lifecycle\ModuleManifest;

#[Spi(kind: SpiKind::Implement, since: '1.0')]
interface ModuleDiscovery
{
    /** @var list<string> */
    public const array DEFAULT_PACKAGE_TYPES = [
        'packman-module',
        'packman-plugin',
        'module',
        'plugin',
    ];

    /** @var list<list<string>> */
    public const array DEFAULT_EXTRA_KEYS = [
        ['packman', 'module'],
        ['packman', 'plugin'],
        ['module'],
        ['plugin'],
    ];

    /** @return list<ModuleManifest> */
    public function discover(): array;
}
