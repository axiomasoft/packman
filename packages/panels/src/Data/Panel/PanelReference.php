<?php

declare(strict_types=1);

namespace Packman\Panels\Data\Panel;

use InvalidArgumentException;
use Packman\Attributes\Api;
use Packman\Panels\Data\Subjects\EntityReference;

#[Api(since: '1.0')]
final readonly class PanelReference
{
    public function __construct(public string $providerKey, public string $panelId, public ?EntityReference $parent)
    {
        if ($providerKey === '' || $panelId === '') {
            throw new InvalidArgumentException('Panel references require a provider and panel id.');
        }
    }
}
