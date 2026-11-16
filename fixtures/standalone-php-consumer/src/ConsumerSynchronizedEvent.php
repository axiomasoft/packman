<?php

declare(strict_types=1);

namespace Fixture\StandalonePhp;

use Packman\Events\DomainEvent;

final readonly class ConsumerSynchronizedEvent extends DomainEvent
{
    public static function name(): string
    {
        return 'consumer.synchronized';
    }
}
