<?php

declare(strict_types=1);

namespace Packman\Plugins\Enums\Plugin;

use Packman\Attributes\Api;

/** Where a plugin was registered from. */
#[Api(since: '1.0')]
enum PluginSource: string
{
    case BuiltIn = 'builtin';
    case Config = 'config';
}
