<?php

declare(strict_types=1);

namespace Packman\Context\Data;

use InvalidArgumentException;
use Packman\Attributes\Api;

#[Api(since: '1.0')]
final readonly class ActorRef
{
    public function __construct(
        public string $type,
        public ?string $id = null,
    ) {
        if ($this->type === '') {
            throw new InvalidArgumentException('Actor type must be a non-empty string.');
        }
    }
}
