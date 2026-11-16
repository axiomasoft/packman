<?php

declare(strict_types=1);

namespace Packman\Panels\SavedViews\Data;

use Packman\Attributes\Api;

#[Api(since: '1.0')]
enum ViewAction: string
{
    case Read = 'read';
    case Create = 'create';
    case Update = 'update';
    case Delete = 'delete';
    case SetDefault = 'setDefault';
}
