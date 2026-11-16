<?php

declare(strict_types=1);

namespace Packman\Panels\Data\Subjects;

use InvalidArgumentException;
use Packman\Attributes\Api;

#[Api(since: '1.0')]
final readonly class EntityReference
{
    public function __construct(public string $handle, public string $id)
    {
        if ($handle === '' || $id === '') {
            throw new InvalidArgumentException('Entity references require a handle and an id.');
        }
    }
}
